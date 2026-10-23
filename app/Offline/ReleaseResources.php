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
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Clôture des ressources d'un poste APRÈS reprise complète (O03 ; fichier 19 : « Une plage est clôturée explicitement
 * après reprise complète ; numéros inutilisés marqués non utilisés et conservés, jamais recyclés » ; fiche O03 :
 * « Restituer seulement après reprise complète, jamais à expiration simple »). Gérante réauthentifiée, en ligne.
 *
 * Reprise complète, vérifiée sous le verrou du cabinet :
 *  - la séquence et l'empreinte annoncées par le poste (`last_device_sequence`, `last_event_hash`) sont EXACTEMENT le
 *    préfixe finalisé et vérifié du serveur (`enrolled_devices.last_accepted_sequence/hash`, contrat 23) ;
 *  - aucune commande de ce poste n'est reçue au-delà de ce préfixe (rien en attente, en dépendance ou en conflit).
 * Sinon : 409, rien n'est libéré (une restitution prématurée referait vendre des unités ou réutiliser des numéros
 * qu'une commande en attente consomme peut-être). Poste perdu (`LOST`) : 409, procédure documentée de poste perdu
 * (fichier 19 : reconstruire, décision gérante), jamais une restitution silencieuse.
 *
 * Effets : propriétés de facture CLOSED (`final_device_sequence` = préfixe finalisé, rang 50) ; quotas CLOSED, le
 * reliquat non consommé revient au disponible central (`returned_quantity`, `offline_allocated` des positions et lots
 * diminué, rangs 80, 81, 82) ; plages CLOSED, chaque numéro inutilisé inscrit `UNUSED` dans `document_number_usages`
 * (conservé, jamais recyclé : la séquence centrale est déjà au-delà de la plage).
 */
final class ReleaseResources implements CommandHandler
{
    public const COMMAND = 'ReleaseDevice';

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La clôture des ressources d\'un poste se fait en ligne, par la gérante.');
        }
        $deviceId = (string) $command->entityId;
        $declaredSequence = $command->payload['last_device_sequence'] ?? null;
        $declaredHash = $command->payload['last_event_hash'] ?? null;
        $reason = RevokeDevice::reason($command->payload['reason'] ?? null);

        $device = DeviceDirectory::underClinicLock('enrolled_devices', $context->clinicId, $deviceId);
        if ($device === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }
        if ((string) $device->status === 'LOST' || (string) $device->status === 'REPLACED') {
            throw BusinessError::stateRefused('Poste perdu ou remplacé : sa reprise suit la procédure documentée (reconstruction et décision de la gérante), pas une restitution automatique.', (string) $device->status);
        }
        if (! is_string($declaredSequence) || preg_match('/^(0|[1-9][0-9]{0,18})$/', $declaredSequence) !== 1) {
            throw BusinessError::validation(['last_device_sequence' => ['Dernière séquence du journal du poste attendue (chaîne de chiffres).']]);
        }
        $accepted = (string) $device->last_accepted_sequence;
        $acceptedHash = $device->last_accepted_hash === null ? null : (string) $device->last_accepted_hash;
        if ($declaredSequence !== $accepted || $declaredHash !== $acceptedHash) {
            throw BusinessError::stateRefused(
                'Reprise incomplète : le journal du poste (séquence '.$declaredSequence.') n\'est pas entièrement finalisé sur le serveur (séquence '.$accepted.'). Synchronisez d\'abord toutes ses commandes.',
                'SYNC_INCOMPLETE',
            );
        }
        $beyond = DB::table('sync_commands')
            ->where('clinic_id', $context->clinicId)
            ->where('device_id', $deviceId)
            ->where('device_sequence', '>', (int) $device->last_accepted_sequence)
            ->exists();
        if ($beyond) {
            throw BusinessError::stateRefused('Des commandes de ce poste sont reçues mais pas finalisées (attente, dépendance ou conflit) : rien n\'est restitué.', 'SYNC_INCOMPLETE');
        }

        $stamp = $context->now->utc()->format('Y-m-d H:i:s.u');
        $closedOwners = $this->closeOwners($context, $locks, $deviceId, (int) $device->last_accepted_sequence, $stamp);
        $returned = $this->returnQuotas($context, $locks, $deviceId, $stamp);
        $closedRanges = $this->closeRanges($context, $deviceId, $stamp);

        $audit = [new AuditEntry('device.resources_released', 'enrolled_devices', $deviceId, $reason, [
            'last_accepted_sequence' => $accepted,
            'closed_write_owners' => $closedOwners,
            'returned_stock_allocations' => array_keys($returned['quotas']),
            'closed_number_allocations' => array_map(static fn (array $range): array => [
                'allocation_id' => $range['id'],
                'unused' => $range['unused'],
            ], $closedRanges),
        ])];
        $changes = [];
        foreach ($closedOwners as $ownerId) {
            $changes[] = new ChangeRecord('invoice_write_owners', $ownerId, 2, ChangeVisibility::Financial, ChangeKind::Updated);
        }
        foreach ($returned['quotas'] as $quotaId => $version) {
            $changes[] = new ChangeRecord('device_stock_allocations', (string) $quotaId, $version, ChangeVisibility::Manager, ChangeKind::Updated);
        }
        foreach ($returned['positions'] as $positionId => $version) {
            $changes[] = new ChangeRecord('stock_positions', (string) $positionId, $version, ChangeVisibility::Administrative, ChangeKind::Updated);
        }
        foreach ($closedRanges as $range) {
            $changes[] = new ChangeRecord('document_number_allocations', $range['id'], $range['version'], ChangeVisibility::Manager, ChangeKind::Updated);
        }

        return new CommandOutcome(
            result: [
                'device_id' => $deviceId,
                'closed_number_allocations' => array_map(static fn (array $range): string => $range['id'], $closedRanges),
                'returned_stock_allocations' => array_map('strval', array_keys($returned['quotas'])),
                'closed_write_owners' => $closedOwners,
                'released_at' => $context->now->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
            resultType: 'enrolled_devices',
            resultId: $deviceId,
            audit: $audit,
            changes: $changes,
        );
    }

    /**
     * Clôture de TOUTES les ressources d'un poste dont le journal est entièrement finalisé (préfixe vérifié par
     * l'appelant) : propriétés de facture, quotas (reliquat rendu au central) et plages (numéros inutilisés `UNUSED`).
     * Employée aussi par `ReconcileReplacement` pour un poste remplacé dont les pièces ont été reprises (O07).
     *
     * @return array{owners: list<string>, returned: array{quotas: array<string, int>, positions: array<string, int>}, ranges: list<array{id: string, unused: int, version: int}>}
     */
    public function releaseAll(CommandContext $context, TransactionLocks $locks, string $deviceId, int $finalSequence, string $stamp): array
    {
        return [
            'owners' => $this->closeOwners($context, $locks, $deviceId, $finalSequence, $stamp),
            'returned' => $this->returnQuotas($context, $locks, $deviceId, $stamp),
            'ranges' => $this->closeRanges($context, $deviceId, $stamp),
        ];
    }

    /**
     * @return list<string> propriétés clôturées
     */
    private function closeOwners(CommandContext $context, TransactionLocks $locks, string $deviceId, int $finalSequence, string $stamp): array
    {
        $invoiceIds = DB::table('invoice_write_owners')
            ->where('clinic_id', $context->clinicId)
            ->where('device_id', $deviceId)
            ->whereIn('status', ['ACTIVE', 'CLOSING'])
            ->orderBy('invoice_id')
            ->pluck('invoice_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
        $closed = [];
        foreach ($invoiceIds as $invoiceId) {
            foreach ($locks->invoiceOwners($invoiceId) as $owner) {
                if ((string) $owner->device_id !== $deviceId || ! in_array((string) $owner->status, ['ACTIVE', 'CLOSING'], true)) {
                    continue;
                }
                DB::table('invoice_write_owners')->where('clinic_id', $context->clinicId)->where('id', $owner->id)->update([
                    'status' => 'CLOSED',
                    'closed_at' => $stamp,
                    'final_device_sequence' => $finalSequence,
                    'updated_at' => $stamp,
                ]);
                $closed[] = (string) $owner->id;
            }
        }

        return $closed;
    }

    /**
     * @return array{quotas: array<string, int>, positions: array<string, int>}
     */
    private function returnQuotas(CommandContext $context, TransactionLocks $locks, string $deviceId, string $stamp): array
    {
        $active = DB::table('device_stock_allocations')
            ->where('clinic_id', $context->clinicId)
            ->where('device_id', $deviceId)
            ->whereIn('status', ['ACTIVE', 'CLOSING'])
            ->orderBy('id')
            ->get(['id', 'stock_position_id', 'stock_lot_id']);
        if ($active->isEmpty()) {
            return ['quotas' => [], 'positions' => []];
        }
        $positionIds = array_values(array_unique($active->map(static fn (object $row): string => (string) $row->stock_position_id)->all()));
        $lotIds = array_values(array_unique(array_filter($active->map(static fn (object $row): ?string => $row->stock_lot_id === null ? null : (string) $row->stock_lot_id)->all(), 'is_string')));
        $positions = $locks->stockPositions($positionIds);
        $lots = $locks->stockLots($lotIds);
        $quotas = $locks->stockQuotas(array_values(array_map(static fn (object $row): string => (string) $row->id, $active->all())));

        $positionDelta = [];
        $lotDelta = [];
        $quotaVersions = [];
        foreach ($quotas as $quotaId => $quota) {
            $remaining = Quantity::of((string) $quota->allocated_quantity)
                ->minus(Quantity::of((string) $quota->consumed_quantity))
                ->minus(Quantity::of((string) $quota->returned_quantity));
            $version = ((int) $quota->version) + 1;
            DB::table('device_stock_allocations')->where('clinic_id', $context->clinicId)->where('id', $quotaId)->update([
                'returned_quantity' => Quantity::of((string) $quota->returned_quantity)->plus($remaining)->canonical(),
                'status' => 'CLOSED',
                'closed_at' => $stamp,
                'version' => $version,
                'updated_at' => $stamp,
            ]);
            $quotaVersions[(string) $quotaId] = $version;
            $positionId = (string) $quota->stock_position_id;
            $positionDelta[$positionId] = ($positionDelta[$positionId] ?? Quantity::of('0'))->plus($remaining);
            if ($quota->stock_lot_id !== null) {
                $lotId = (string) $quota->stock_lot_id;
                $lotDelta[$lotId] = ($lotDelta[$lotId] ?? Quantity::of('0'))->plus($remaining);
            }
        }

        $positionVersions = [];
        foreach ($positionDelta as $positionId => $delta) {
            $row = $positions[$positionId] ?? null;
            if ($row === null || $delta->isZero()) {
                continue;
            }
            $version = ((int) $row->version) + 1;
            DB::table('stock_positions')->where('clinic_id', $context->clinicId)->where('id', $positionId)->update([
                'offline_allocated' => Quantity::of((string) $row->offline_allocated)->minus($delta)->canonical(),
                'version' => $version,
                'updated_at' => $stamp,
            ]);
            $positionVersions[(string) $positionId] = $version;
        }
        foreach ($lotDelta as $lotId => $delta) {
            $row = $lots[$lotId] ?? null;
            if ($row === null || $delta->isZero()) {
                continue;
            }
            DB::table('stock_lots')->where('clinic_id', $context->clinicId)->where('id', $lotId)->update([
                'offline_allocated' => Quantity::of((string) $row->offline_allocated)->minus($delta)->canonical(),
                'version' => ((int) $row->version) + 1,
                'updated_at' => $stamp,
            ]);
        }

        return ['quotas' => $quotaVersions, 'positions' => $positionVersions];
    }

    /**
     * @return list<array{id: string, unused: int, version: int}>
     */
    private function closeRanges(CommandContext $context, string $deviceId, string $stamp): array
    {
        $ranges = DB::table('document_number_allocations')
            ->where('clinic_id', $context->clinicId)
            ->where('device_id', $deviceId)
            ->whereIn('status', ['ACTIVE', 'CLOSING'])
            ->orderBy('document_type')
            ->orderBy('document_year')
            ->orderBy('range_start')
            ->get();
        $closed = [];
        foreach ($ranges as $range) {
            $type = (string) $range->document_type;
            $year = (int) $range->document_year;
            $start = (int) $range->range_start;
            $end = (int) $range->range_end;
            $used = DB::table('document_number_usages')
                ->where('clinic_id', $context->clinicId)
                ->where('document_type', $type)
                ->where('document_year', $year)
                ->whereBetween('number_value', [$start, $end])
                ->pluck('number_value')
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();
            $usedSet = array_fill_keys($used, true);
            $rows = [];
            for ($value = $start; $value <= $end; $value++) {
                if (isset($usedSet[$value])) {
                    continue;
                }
                $rows[] = [
                    'id' => Ulid::generate(),
                    'clinic_id' => $context->clinicId,
                    'allocation_id' => (string) $range->id,
                    'document_type' => $type,
                    'document_year' => $year,
                    'number_value' => $value,
                    'rendered_number' => NumberFormats::render($type, $year, $value),
                    'entity_type' => 'document_number_allocations',
                    'entity_id' => (string) $range->id,
                    'source_device_id' => $deviceId,
                    'actor_id' => (string) $context->actorId,
                    'consumed_at' => $stamp,
                    'status' => 'UNUSED',
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('document_number_usages')->insert($chunk);
            }
            $version = ((int) $range->version) + 1;
            DB::table('document_number_allocations')->where('clinic_id', $context->clinicId)->where('id', $range->id)->update([
                'status' => 'CLOSED',
                'closed_at' => $stamp,
                'last_consumed_value' => $used === [] ? null : max($used),
                'version' => $version,
                'updated_at' => $stamp,
            ]);
            $closed[] = ['id' => (string) $range->id, 'unused' => count($rows), 'version' => $version];
        }

        return $closed;
    }
}
