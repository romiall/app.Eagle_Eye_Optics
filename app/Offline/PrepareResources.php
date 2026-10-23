<?php

namespace App\Offline;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Préparation des RESSOURCES d'un poste enrôlé (O03 ; fichier 19 « Numéros », « Un seul rédacteur financier par
 * facture », « Droits de stock pré-alloués » ; contrat 23 « Chemin transactionnel central »). Gérante réauthentifiée
 * (`offline.enroll`), en ligne seulement, sous la clé d'idempotence de la requête. Trois ressources, toutes dans la
 * MÊME transaction :
 *
 *  1. Plages de numéros `(cabinet, série, année, début, fin, poste, grant)` prises SOUS le verrou de la séquence
 *     (rang 40) : la plage commence après le plus haut numéro connu de la série (séquence centrale, numéros déjà
 *     consommés, plages déjà réservées) et la séquence centrale monte à la fin de la plage. Une allocation centrale
 *     ultérieure est donc toujours hors de toute plage : plages disjointes, aucun numéro recyclé, aucun `MAX + 1`
 *     local. L'année est celle du fuseau du cabinet ; la plage de l'année suivante peut être préparée avant le
 *     franchissement (fichier 19), jamais une année passée.
 *  2. Propriété d'écriture de factures ÉMISES existantes (créances à encaisser sur ce poste) : une ligne
 *     `invoice_write_owners` ACTIVE par facture, d'époque supérieure à toutes les précédentes (rang 50), après
 *     vérification qu'aucun autre poste ne la détient. Tant qu'elle est active, aucune route directe ne paie, ne
 *     crédite ni ne rembourse cette facture (`BillingErrors::writeOwnerRequired`).
 *  3. Quotas de stock `device_stock_allocations` par position et lot, retirés du disponible central
 *     (`offline_allocated`, positions puis lots par identifiant trié, rangs 80 et 81) et JAMAIS du physique.
 *
 * Grant : chaque ressource est rattachée au grant ACTIF (non révoqué, non expiré) d'un des profils préparés qui
 * détient la permission de la consommer (série patient : `patient.create` ; facture : `invoice.issue` ; reçu et
 * propriété : `payment.collect` ; avoir : `credit_note.issue` ; remboursement : `refund.execute` ; quota :
 * `invoice.issue`). Une seule caisse hors-ligne principale (fichier 19) : tant qu'un AUTRE poste actif détient une
 * ressource financière, une ressource financière est refusée ici (`eeo.offline.single_financial_device`).
 *
 * Rien n'est jamais libéré par une expiration : seule `ReleaseDevice`, après reprise complète, restitue.
 */
final class PrepareResources implements CommandHandler
{
    public const COMMAND = 'PrepareDevice';

    /** Séries préparées en L0 ; devis, atelier et reprises papier viennent avec L1. */
    public const SERIES = ['PATIENT', 'INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'REFUND'];

    /** Séries financières : une seule caisse hors-ligne principale à la fois. */
    public const FINANCIAL_SERIES = ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'REFUND'];

    /** Permission du profil qui consomme chaque série (fichier 09). */
    public const SERIES_PERMISSION = [
        'PATIENT' => 'patient.create',
        'INVOICE' => 'invoice.issue',
        'RECEIPT' => 'payment.collect',
        'CREDIT_NOTE' => 'credit_note.issue',
        'REFUND' => 'refund.execute',
    ];

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La préparation d\'un poste se fait en ligne, par la gérante.');
        }
        $deviceId = (string) $command->entityId;
        $device = DeviceDirectory::underClinicLock('enrolled_devices', $context->clinicId, $deviceId);
        if ($device === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }
        if ((string) $device->status !== 'ACTIVE') {
            throw BusinessError::stateRefused('Ce poste n\'est plus actif : aucune ressource ne peut lui être préparée.', (string) $device->status);
        }

        $now = $context->now->utc();
        $stamp = $now->format('Y-m-d H:i:s.u');
        $timezone = (string) (DB::table('clinics')->where('id', $context->clinicId)->value('timezone') ?? 'Africa/Douala');
        $currentYear = DocumentNumbers::yearOf($context, $timezone);

        $errors = [];
        $grants = $this->grants($context->clinicId, $deviceId, $command->payload['profiles'] ?? [], $stamp, $errors);
        $ranges = $this->ranges($command->payload['number_ranges'] ?? [], $grants, $currentYear, $errors);
        $invoiceIds = $this->invoiceIds($command->payload['invoice_ids'] ?? [], $grants, $errors);
        $quotas = $this->quotas($command->payload['stock_quotas'] ?? [], $grants, $errors);
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $financial = $invoiceIds !== [] || $quotas !== []
            || array_filter($ranges, static fn (array $range): bool => in_array($range['type'], self::FINANCIAL_SERIES, true)) !== [];
        if ($financial && (bool) config('eeo.offline.single_financial_device', true)) {
            $this->assertSingleFinancialDevice($context->clinicId, $deviceId);
        }

        // 1. Plages, sous le verrou de chaque séquence (type, année) dans l'ordre trié par TransactionLocks.
        $sequenceRows = $locks->sequences(array_map(static fn (array $range): array => [$range['type'], $range['year']], $ranges));
        $allocations = [];
        foreach ($ranges as $range) {
            $allocations[] = $this->allocateRange($context, $deviceId, $range, $sequenceRows[sprintf('%s:%04d', $range['type'], $range['year'])] ?? null, $stamp);
        }

        // 2. Propriété des factures : toutes les propriétés (rang 50) par identifiant trié, puis les factures (60).
        sort($invoiceIds, SORT_STRING);
        $ownersByInvoice = [];
        foreach ($invoiceIds as $invoiceId) {
            $ownersByInvoice[$invoiceId] = $locks->invoiceOwners($invoiceId);
        }
        $owners = [];
        foreach ($invoiceIds as $invoiceId) {
            $owners[] = $this->takeOwnership($context, $locks, $deviceId, $invoiceId, $ownersByInvoice[$invoiceId], $stamp);
        }

        // 3. Quotas : positions (80) puis lots (81), par identifiant trié ; disponibles vérifiés sur les sommes.
        $stock = $this->allocateQuotas($context, $locks, $deviceId, $quotas, $stamp);

        $audit = [new AuditEntry('device.resources_prepared', 'enrolled_devices', $deviceId, null, [
            'number_allocations' => array_map(static fn (array $allocation): array => [
                'allocation_id' => $allocation['id'],
                'document_type' => $allocation['type'],
                'document_year' => $allocation['year'],
                'range_start' => $allocation['start'],
                'range_end' => $allocation['end'],
            ], $allocations),
            'write_owners' => array_map(static fn (array $owner): array => ['invoice_id' => $owner['invoice_id'], 'epoch' => $owner['epoch']], $owners),
            'stock_allocations' => array_map(static fn (array $quota): string => $quota['id'], $stock['allocations']),
        ])];
        $changes = [];
        foreach ($allocations as $allocation) {
            $changes[] = new ChangeRecord('document_number_allocations', $allocation['id'], 1, ChangeVisibility::Manager, ChangeKind::Created);
        }
        foreach ($owners as $owner) {
            $changes[] = new ChangeRecord('invoice_write_owners', $owner['id'], 1, ChangeVisibility::Financial, ChangeKind::Created);
        }
        foreach ($stock['allocations'] as $quota) {
            $changes[] = new ChangeRecord('device_stock_allocations', $quota['id'], 1, ChangeVisibility::Manager, ChangeKind::Created);
        }
        foreach ($stock['positions'] as $positionId => $version) {
            $changes[] = new ChangeRecord('stock_positions', (string) $positionId, $version, ChangeVisibility::Administrative, ChangeKind::Updated);
        }

        return new CommandOutcome(
            result: [
                'device_id' => $deviceId,
                'number_allocations' => array_map(static fn (array $allocation): string => $allocation['id'], $allocations),
                'write_owners' => array_map(static fn (array $owner): string => $owner['id'], $owners),
                'stock_allocations' => array_map(static fn (array $quota): string => $quota['id'], $stock['allocations']),
            ],
            resultType: 'enrolled_devices',
            resultId: $deviceId,
            audit: $audit,
            changes: $changes,
        );
    }

    /**
     * Grants actifs des profils préparés sur ce poste, par compte, avec leurs permissions figées.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, array{id: string, permissions: list<string>}>
     */
    private function grants(string $clinicId, string $deviceId, mixed $profiles, string $now, array &$errors): array
    {
        $grants = [];
        if (! is_array($profiles) || $profiles === []) {
            $errors['profiles'][] = 'Indiquez au moins un profil préparé sur ce poste.';

            return [];
        }
        foreach (array_values($profiles) as $index => $userId) {
            if (! is_string($userId) || isset($grants[$userId])) {
                $errors['profiles.'.$index][] = 'Profil en double ou invalide.';

                continue;
            }
            $grant = DB::table('offline_grants')
                ->where('clinic_id', $clinicId)
                ->where('device_id', $deviceId)
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->where('valid_until', '>', $now)
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->first();
            if ($grant === null) {
                $errors['profiles.'.$index][] = 'Ce profil n\'a pas de grant actif sur ce poste : préparez d\'abord son autorisation.';

                continue;
            }
            $permissions = json_decode((string) $grant->permissions_snapshot, true);
            $grants[$userId] = [
                'id' => (string) $grant->id,
                'permissions' => is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [],
            ];
        }

        return $grants;
    }

    /**
     * @param  array<string, array{id: string, permissions: list<string>}>  $grants
     * @param  array<string, list<string>>  $errors
     * @return list<array{type: string, year: int, quantity: int, grant_id: string}>
     */
    private function ranges(mixed $requested, array $grants, int $currentYear, array &$errors): array
    {
        if (! is_array($requested)) {
            return [];
        }
        $maxSize = max(1, (int) config('eeo.offline.range_max_size', 1000));
        $ranges = [];
        $seen = [];
        foreach (array_values($requested) as $index => $range) {
            $field = 'number_ranges.'.$index;
            $type = is_array($range) ? ($range['document_type'] ?? null) : null;
            $quantity = is_array($range) ? ($range['quantity'] ?? null) : null;
            $year = is_array($range) ? ($range['document_year'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::SERIES, true)) {
                $errors[$field.'.document_type'][] = 'Série non préparée hors-ligne dans cette version (patient, facture, reçu, avoir, remboursement).';

                continue;
            }
            if (! is_int($quantity) || $quantity < 1 || $quantity > $maxSize) {
                $errors[$field.'.quantity'][] = 'Taille de plage hors des bornes admises (1 à '.$maxSize.' numéros).';

                continue;
            }
            $year = $year === null ? $currentYear : $year;
            if (! is_int($year) || ($year !== $currentYear && $year !== $currentYear + 1)) {
                $errors[$field.'.document_year'][] = 'Seules l\'année en cours et la suivante (fuseau du cabinet) se préparent.';

                continue;
            }
            $key = $type.':'.$year;
            if (isset($seen[$key])) {
                $errors[$field][] = 'Une seule plage par série et par année dans une même préparation.';

                continue;
            }
            $seen[$key] = true;
            $grantId = $this->holder($grants, self::SERIES_PERMISSION[$type]);
            if ($grantId === null) {
                $errors[$field.'.document_type'][] = 'Aucun profil préparé ne peut émettre cette série (permission '.self::SERIES_PERMISSION[$type].').';

                continue;
            }
            $ranges[] = ['type' => $type, 'year' => $year, 'quantity' => $quantity, 'grant_id' => $grantId];
        }

        return $ranges;
    }

    /**
     * @param  array<string, array{id: string, permissions: list<string>}>  $grants
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function invoiceIds(mixed $requested, array $grants, array &$errors): array
    {
        if (! is_array($requested) || $requested === []) {
            return [];
        }
        if ($this->holder($grants, 'payment.collect') === null) {
            $errors['invoice_ids'][] = 'Aucun profil préparé ne peut encaisser (permission payment.collect) : aucune facture à attribuer.';

            return [];
        }
        $ids = [];
        foreach (array_values($requested) as $index => $invoiceId) {
            if (! is_string($invoiceId) || in_array($invoiceId, $ids, true)) {
                $errors['invoice_ids.'.$index][] = 'Facture en double ou invalide.';

                continue;
            }
            $ids[] = $invoiceId;
        }

        return $ids;
    }

    /**
     * @param  array<string, array{id: string, permissions: list<string>}>  $grants
     * @param  array<string, list<string>>  $errors
     * @return list<array{position_id: string, lot_id: string|null, quantity: FixedDecimal}>
     */
    private function quotas(mixed $requested, array $grants, array &$errors): array
    {
        if (! is_array($requested) || $requested === []) {
            return [];
        }
        if ($this->holder($grants, 'invoice.issue') === null) {
            $errors['stock_quotas'][] = 'Aucun profil préparé ne peut facturer (permission invoice.issue) : aucun quota à préparer.';

            return [];
        }
        $quotas = [];
        $seen = [];
        foreach (array_values($requested) as $index => $quota) {
            $field = 'stock_quotas.'.$index;
            $positionId = is_array($quota) ? ($quota['stock_position_id'] ?? null) : null;
            $lotId = is_array($quota) ? ($quota['stock_lot_id'] ?? null) : null;
            $text = is_array($quota) ? ($quota['quantity'] ?? null) : null;
            if (! is_string($positionId) || ($lotId !== null && ! is_string($lotId))) {
                $errors[$field][] = 'Position ou lot invalide.';

                continue;
            }
            try {
                $quantity = is_string($text) ? Quantity::parse($text, $field.'.quantity') : null;
            } catch (RuleViolation) {
                $quantity = null;
            }
            if ($quantity === null || $quantity->units <= 0) {
                $errors[$field.'.quantity'][] = 'Quantité strictement positive attendue.';

                continue;
            }
            $key = $positionId.'|'.($lotId ?? '');
            if (isset($seen[$key])) {
                $errors[$field][] = 'Un seul quota par position et par lot dans une même préparation.';

                continue;
            }
            $seen[$key] = true;
            $quotas[] = ['position_id' => $positionId, 'lot_id' => $lotId, 'quantity' => $quantity];
        }

        return $quotas;
    }

    /**
     * Premier grant (ordre des profils demandés) qui détient la permission.
     *
     * @param  array<string, array{id: string, permissions: list<string>}>  $grants
     */
    private function holder(array $grants, string $permission): ?string
    {
        foreach ($grants as $grant) {
            if (in_array($permission, $grant['permissions'], true)) {
                return $grant['id'];
            }
        }

        return null;
    }

    /** Une seule caisse hors-ligne principale : aucun AUTRE poste actif ne détient de ressource financière active. */
    private function assertSingleFinancialDevice(string $clinicId, string $deviceId): void
    {
        $others = DB::table('enrolled_devices')
            ->where('clinic_id', $clinicId)
            ->where('id', '<>', $deviceId)
            ->whereIn('status', ['ACTIVE', 'REVOKED', 'LOST'])
            ->where(static function ($query) use ($clinicId): void {
                $query->whereExists(static function ($sub) use ($clinicId): void {
                    $sub->select(DB::raw(1))->from('invoice_write_owners as o')
                        ->where('o.clinic_id', $clinicId)
                        ->whereColumn('o.device_id', 'enrolled_devices.id')
                        ->whereIn('o.status', ['ACTIVE', 'CLOSING']);
                })->orWhereExists(static function ($sub) use ($clinicId): void {
                    $sub->select(DB::raw(1))->from('device_stock_allocations as q')
                        ->where('q.clinic_id', $clinicId)
                        ->whereColumn('q.device_id', 'enrolled_devices.id')
                        ->whereIn('q.status', ['ACTIVE', 'CLOSING']);
                })->orWhereExists(static function ($sub) use ($clinicId): void {
                    $sub->select(DB::raw(1))->from('document_number_allocations as a')
                        ->where('a.clinic_id', $clinicId)
                        ->whereColumn('a.device_id', 'enrolled_devices.id')
                        ->whereIn('a.status', ['ACTIVE', 'CLOSING'])
                        ->whereIn('a.document_type', self::FINANCIAL_SERIES);
                });
            })
            ->orderBy('id')
            ->first(['id', 'name']);
        if ($others !== null) {
            throw BusinessError::stateRefused(
                'La caisse hors-ligne principale est le poste « '.$others->name.' » : clôturez ses ressources (reprise complète) avant d\'en préparer une autre.',
                'FINANCIAL_DEVICE_ELSEWHERE',
            );
        }
    }

    /**
     * @param  array{type: string, year: int, quantity: int, grant_id: string}  $range
     * @return array{id: string, type: string, year: int, start: int, end: int}
     */
    private function allocateRange(CommandContext $context, string $deviceId, array $range, ?stdClass $sequence, string $stamp): array
    {
        $used = (int) DB::table('document_number_usages')
            ->where('clinic_id', $context->clinicId)->where('document_type', $range['type'])->where('document_year', $range['year'])
            ->max('number_value');
        $reserved = (int) DB::table('document_number_allocations')
            ->where('clinic_id', $context->clinicId)->where('document_type', $range['type'])->where('document_year', $range['year'])
            ->max('range_end');
        $start = max($sequence === null ? 0 : (int) $sequence->last_value, $used, $reserved) + 1;
        $end = $start + $range['quantity'] - 1;

        if ($sequence === null) {
            DB::table('document_sequences')->insert([
                'id' => Ulid::generate(),
                'clinic_id' => $context->clinicId,
                'document_type' => $range['type'],
                'document_year' => $range['year'],
                'last_value' => $end,
                'version' => 1,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        } else {
            DB::table('document_sequences')
                ->where('clinic_id', $context->clinicId)
                ->where('id', $sequence->id)
                ->update(['last_value' => $end, 'version' => ((int) $sequence->version) + 1, 'updated_at' => $stamp]);
        }

        $id = Ulid::generate();
        DB::table('document_number_allocations')->insert([
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'device_id' => $deviceId,
            'grant_id' => $range['grant_id'],
            'document_type' => $range['type'],
            'document_year' => $range['year'],
            'range_start' => $start,
            'range_end' => $end,
            'last_consumed_value' => null,
            'status' => 'ACTIVE',
            'allocated_at' => $stamp,
            'closed_at' => null,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return ['id' => $id, 'type' => $range['type'], 'year' => $range['year'], 'start' => $start, 'end' => $end];
    }

    /**
     * @param  list<stdClass>  $existing  propriétés de la facture, verrouillées (rang 50)
     * @return array{id: string, invoice_id: string, epoch: int}
     */
    private function takeOwnership(CommandContext $context, TransactionLocks $locks, string $deviceId, string $invoiceId, array $existing, string $stamp): array
    {
        $invoice = $locks->invoice($invoiceId);
        if ($invoice === null) {
            throw BusinessError::validation(['invoice_ids' => ['Facture introuvable dans ce cabinet.']]);
        }
        if ((string) $invoice->status !== 'ISSUED') {
            throw BusinessError::stateRefused('Seule une facture émise s\'attribue à une caisse hors-ligne.', (string) $invoice->status);
        }
        $epoch = 0;
        foreach ($existing as $owner) {
            $epoch = max($epoch, (int) $owner->epoch);
            if (in_array((string) $owner->status, ['ACTIVE', 'CLOSING'], true)) {
                $name = DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $owner->device_id)->value('name');
                throw BusinessError::stateRefused(
                    'Cette facture est déjà attribuée au poste « '.(is_string($name) ? $name : 'poste').' » : transférez-la après sa reprise complète.',
                    'WRITE_OWNER_ACTIVE',
                );
            }
        }

        $id = Ulid::generate();
        DB::table('invoice_write_owners')->insert([
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'invoice_id' => $invoiceId,
            'device_id' => $deviceId,
            'epoch' => $epoch + 1,
            'status' => 'ACTIVE',
            'granted_at' => $stamp,
            'closed_at' => null,
            'final_device_sequence' => null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return ['id' => $id, 'invoice_id' => $invoiceId, 'epoch' => $epoch + 1];
    }

    /**
     * @param  list<array{position_id: string, lot_id: string|null, quantity: FixedDecimal}>  $quotas
     * @return array{allocations: list<array{id: string}>, positions: array<string, int>}
     */
    private function allocateQuotas(CommandContext $context, TransactionLocks $locks, string $deviceId, array $quotas, string $stamp): array
    {
        if ($quotas === []) {
            return ['allocations' => [], 'positions' => []];
        }
        $positionIds = array_values(array_unique(array_map(static fn (array $quota): string => $quota['position_id'], $quotas)));
        $lotIds = array_values(array_unique(array_filter(array_map(static fn (array $quota): ?string => $quota['lot_id'], $quotas), 'is_string')));
        $positions = $locks->stockPositions($positionIds);
        $lots = $locks->stockLots($lotIds);

        $perPosition = [];
        $perLot = [];
        foreach ($quotas as $index => $quota) {
            $position = $positions[$quota['position_id']] ?? null;
            if ($position === null) {
                throw BusinessError::validation(['stock_quotas.'.$index.'.stock_position_id' => ['Position de stock introuvable dans ce cabinet.']]);
            }
            if ($quota['lot_id'] !== null) {
                $lot = $lots[$quota['lot_id']] ?? null;
                if ($lot === null || (string) $lot->stock_position_id !== $quota['position_id']) {
                    throw BusinessError::validation(['stock_quotas.'.$index.'.stock_lot_id' => ['Ce lot n\'appartient pas à cette position.']]);
                }
                $perLot[$quota['lot_id']] = ($perLot[$quota['lot_id']] ?? Quantity::of('0'))->plus($quota['quantity']);
            }
            $perPosition[$quota['position_id']] = ($perPosition[$quota['position_id']] ?? Quantity::of('0'))->plus($quota['quantity']);
        }

        foreach ($perPosition as $positionId => $total) {
            $row = $positions[$positionId];
            $available = Quantity::of((string) $row->on_hand)->minus(Quantity::of((string) $row->reserved))->minus(Quantity::of((string) $row->offline_allocated));
            if ($available->compare($total) < 0) {
                throw BusinessError::stateRefused('Stock central disponible insuffisant pour ce quota (disponible : '.$available->canonical().').', 'STOCK_UNAVAILABLE');
            }
        }
        foreach ($perLot as $lotId => $total) {
            $row = $lots[$lotId];
            $available = Quantity::of((string) $row->on_hand)->minus(Quantity::of((string) $row->reserved))->minus(Quantity::of((string) $row->offline_allocated));
            if ($available->compare($total) < 0) {
                throw BusinessError::stateRefused('Lot : disponible insuffisant pour ce quota (disponible : '.$available->canonical().').', 'STOCK_UNAVAILABLE');
            }
        }

        $versions = [];
        foreach ($perPosition as $positionId => $total) {
            $row = $positions[$positionId];
            $version = ((int) $row->version) + 1;
            DB::table('stock_positions')->where('clinic_id', $context->clinicId)->where('id', $positionId)->update([
                'offline_allocated' => Quantity::of((string) $row->offline_allocated)->plus($total)->canonical(),
                'version' => $version,
                'updated_at' => $stamp,
            ]);
            $versions[(string) $positionId] = $version;
        }
        foreach ($perLot as $lotId => $total) {
            $row = $lots[$lotId];
            DB::table('stock_lots')->where('clinic_id', $context->clinicId)->where('id', $lotId)->update([
                'offline_allocated' => Quantity::of((string) $row->offline_allocated)->plus($total)->canonical(),
                'version' => ((int) $row->version) + 1,
                'updated_at' => $stamp,
            ]);
        }

        $allocations = [];
        foreach ($quotas as $quota) {
            $id = Ulid::generate();
            DB::table('device_stock_allocations')->insert([
                'id' => $id,
                'clinic_id' => $context->clinicId,
                'device_id' => $deviceId,
                'stock_position_id' => $quota['position_id'],
                'stock_lot_id' => $quota['lot_id'],
                'allocated_quantity' => $quota['quantity']->canonical(),
                'consumed_quantity' => '0',
                'returned_quantity' => '0',
                'status' => 'ACTIVE',
                'allocated_at' => $stamp,
                'closed_at' => null,
                'version' => 1,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $allocations[] = ['id' => $id];
        }

        return ['allocations' => $allocations, 'positions' => $versions];
    }
}
