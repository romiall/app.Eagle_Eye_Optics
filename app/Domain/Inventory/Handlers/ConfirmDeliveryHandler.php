<?php

namespace App\Domain\Inventory\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Effects\OutboxEvent;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Billing\Snapshots\InvoiceSnapshots;
use App\Domain\Inventory\ReservationRefused;
use App\Domain\Inventory\ReservationService;
use App\Domain\Inventory\Rules\DeliveryRules;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Remise au patient liée à une facture émise (I01, AT23, EE016, EE026, IT15 ; fichier 03 : « Remise : quantité remise
 * non déjà livrée, lot vendable non expiré au jour local, approbation si dette »). Route `POST /invoices/{id}/deliveries`.
 *
 * Remise partielle ou totale : chaque ligne vise une ligne de CETTE facture portant un article du catalogue ; le cumul
 * des remises confirmées de la ligne ne dépasse jamais la quantité facturée. Article géré en stock : la quantité est
 * prise sur les réserves OUVERTES de la ligne (posées à l'émission, `ReservationService`) — jamais sur le stock d'une
 * autre vente — et sort du physique dans la même transaction (`consumeForDelivery` : réserve centrale ou quota d'un
 * poste, rien n'est soustrait deux fois). Une ligne dont la réserve couvre deux lots donne deux lignes de remise (la
 * première garde l'identifiant de la commande). Un mouvement `SALE_DELIVERY` négatif par ligne de remise, lié à elle.
 * Article sans stock (service, fabrication sur mesure) : ligne de remise sans réserve, sans lot ni mouvement.
 *
 * Lot vendable et non expiré au jour LOCAL de la remise (fuseau du cabinet). En ligne, la remise est datée du jour où
 * l'article est remis : une heure du poste dans le futur (au-delà de la dérive admise) ou d'un jour antérieur est
 * refusée.
 *
 * Facture NON SOLDÉE (reste dû > 0 à l'instant) : remise à crédit seulement avec l'approbation exacte de la gérante
 * (`credit_delivery_approval_id` : facture, reste dû, lignes et quantités), consommée une fois, ou exécution directe par
 * la gérante. Facture soldée : aucune approbation n'est consommée (un identifiant fourni est ignoré, la demande éventuelle
 * expire d'elle-même). La remise ne change ni la facture, ni son solde, ni ses reçus.
 *
 * Écritures dans l'ordre des triggers (« enfants écrits avant la finalisation ») : remise `DRAFT`, lignes, mouvements,
 * puis une seule mise à jour `DRAFT` → `CONFIRMED` avec l'instantané du patient (identité administrative à la remise).
 * Une remise confirmée est immuable (trigger) ; une erreur de remise se corrige par un retour physique lié.
 *
 * Verrous : cabinet, idempotence, propriété de facture (50), facture (60), positions (80), lots (81), quotas (82),
 * approbation (90). Les réserves de la facture ne changent que sous le verrou de la facture : deux postes qui remettent
 * le dernier article de la même vente sont sérialisés, le second reçoit le reste actualisé (0).
 */
final class ConfirmDeliveryHandler implements CommandHandler
{
    public const COMMAND = 'ConfirmDelivery';

    public function __construct(private readonly ReservationService $reservations, private readonly ExactApprovals $approvals) {}

    public function permissions(): array
    {
        return ['delivery.confirm'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La remise au patient');
        if ($idempotencyId === null || $context->actorId === null) {
            throw new LogicException('Une remise exige une clé d\'idempotence et un auteur identifié.');
        }

        $payload = $command->payload;
        $deliveryId = (string) $payload['delivery_id'];
        $invoiceId = (string) $payload['invoice_id'];
        $approvalId = is_string($payload['credit_delivery_approval_id'] ?? null) ? $payload['credit_delivery_approval_id'] : null;
        /** @var list<array<string, mixed>> $payloadLines */
        $payloadLines = array_values(is_array($payload['lines'] ?? null) ? $payload['lines'] : []);
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $timezone = (string) $clinic->timezone;
        $errors = new FieldErrors;

        // 1. Saisie : instant de remise, identifiants neufs, quantités.
        $nowInstant = Instant::fromEpochMilliseconds((int) $at->format('Uv'));
        $deliveredAt = null;
        try {
            $deliveredAt = Instant::parse((string) $payload['delivered_at'], 'delivered_at');
            if ($deliveredAt === null) {
                $errors->add('delivered_at', 'L\'heure de la remise est obligatoire.');
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($deliveredAt !== null) {
            $skew = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
            if ($deliveredAt->epochMilliseconds > $nowInstant->epochMilliseconds + $skew) {
                $errors->add('delivered_at', 'L\'heure de la remise ne peut pas être dans le futur.');
            } elseif ($deliveredAt->civilDate($timezone)->compare($nowInstant->civilDate($timezone)) < 0) {
                $errors->add('delivered_at', 'Une remise est enregistrée le jour où l\'article est remis : la date ne peut pas être d\'un jour antérieur.');
            }
        }
        if (DB::table('deliveries')->where('id', $deliveryId)->exists()) {
            $errors->add('delivery_id', 'Cet identifiant de remise est déjà utilisé.');
        }

        /** @var array<int, array{delivery_line_id: string, invoice_line_id: string, item_id: string, quantity: ?FixedDecimal, stock_position_id: ?string, stock_lot_id: ?string, reservation_id: ?string, stock_movement_id: ?string}> $lines */
        $lines = [];
        $seen = [];
        foreach ($payloadLines as $index => $line) {
            $prefix = 'lines.'.$index;
            $parsed = [
                'delivery_line_id' => (string) ($line['delivery_line_id'] ?? ''),
                'invoice_line_id' => (string) ($line['invoice_line_id'] ?? ''),
                'item_id' => (string) ($line['item_id'] ?? ''),
                'quantity' => null,
                'stock_position_id' => is_string($line['stock_position_id'] ?? null) ? $line['stock_position_id'] : null,
                'stock_lot_id' => is_string($line['stock_lot_id'] ?? null) ? $line['stock_lot_id'] : null,
                'reservation_id' => is_string($line['reservation_id'] ?? null) ? $line['reservation_id'] : null,
                'stock_movement_id' => is_string($line['stock_movement_id'] ?? null) ? $line['stock_movement_id'] : null,
            ];
            foreach ([['delivery_line_id', 'delivery_lines', 'ligne de remise'], ['stock_movement_id', 'stock_movements', 'mouvement']] as [$field, $table, $label]) {
                $id = $parsed[$field];
                if ($id === null) {
                    continue;
                }
                if (isset($seen[$id]) || DB::table($table)->where('id', $id)->exists()) {
                    $errors->add($prefix.'.'.$field, 'Cet identifiant de '.$label.' est déjà utilisé.');
                }
                $seen[$id] = true;
            }
            try {
                $parsed['quantity'] = Quantity::parse(is_string($line['quantity'] ?? null) ? $line['quantity'] : null);
                if ($parsed['quantity'] === null) {
                    $errors->add($prefix.'.quantity', 'La quantité remise est obligatoire.');
                } else {
                    Quantity::forLine($parsed['quantity'], false);
                }
            } catch (RuleViolation $violation) {
                $errors->addViolation($prefix, $violation);
                $parsed['quantity'] = null;
            }
            $lines[$index] = $parsed;
        }
        $errors->throwIfAny();
        if ($deliveredAt === null) {
            throw new LogicException('Instant de remise absent malgré une saisie acceptée.');
        }

        // 2. Propriété de la facture (50) puis facture (60) : une facture détenue par un poste se remet sur ce poste.
        foreach ($locks->invoiceOwners($invoiceId) as $owner) {
            if (in_array((string) $owner->status, ['ACTIVE', 'CLOSING'], true)) {
                $name = DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $owner->device_id)->value('name');
                throw new BusinessError(
                    'WRITE_OWNER_REQUIRED',
                    'Remise disponible sur la caisse « '.(is_string($name) ? $name : 'poste').' » : cette facture est détenue par ce poste tant que son droit est actif.',
                    409,
                );
            }
        }
        $invoice = $locks->invoice($invoiceId);
        if ($invoice === null) {
            throw BusinessError::notFound('Facture introuvable dans ce cabinet.');
        }
        if ((string) $invoice->status !== 'ISSUED') {
            throw BusinessError::stateRefused('Seule une facture émise se remet au patient (un brouillon s\'émet d\'abord).', (string) $invoice->status);
        }
        if ($deliveredAt->epochMilliseconds < (int) CarbonImmutable::parse((string) $invoice->issued_at, 'UTC')->format('Uv')) {
            throw BusinessError::validation(['delivered_at' => ['La remise ne peut pas précéder l\'émission de la facture.']]);
        }

        // 3. Lignes, cumul déjà remis et réserves ouvertes, relus sous le verrou de la facture.
        /** @var array<string, stdClass> $invoiceLines */
        $invoiceLines = DB::table('invoice_lines')->where('clinic_id', $context->clinicId)->where('invoice_id', $invoiceId)->get()->keyBy('id')->all();
        $itemIds = array_values(array_unique(array_filter(array_map(static fn (stdClass $line): ?string => $line->catalog_item_id === null ? null : (string) $line->catalog_item_id, $invoiceLines), 'is_string')));
        /** @var array<string, stdClass> $items */
        $items = DB::table('catalog_items')->where('clinic_id', $context->clinicId)->whereIn('id', $itemIds)->get()->keyBy('id')->all();
        $delivered = self::deliveredByLine($context->clinicId, $invoiceId);
        $reservationRows = DB::table('stock_reservations')
            ->where('clinic_id', $context->clinicId)
            ->whereIn('invoice_line_id', array_map('strval', array_keys($invoiceLines)))
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->get()
            ->all();
        $lotIds = array_values(array_unique(array_filter(array_map(static fn (stdClass $row): ?string => $row->stock_lot_id === null ? null : (string) $row->stock_lot_id, $reservationRows), 'is_string')));
        /** @var array<string, stdClass> $lotRows */
        $lotRows = DB::table('stock_lots')->where('clinic_id', $context->clinicId)->whereIn('id', $lotIds)->get(['id', 'expires_on', 'unit_cost'])->keyBy('id')->all();
        $reservationsById = [];
        $openByReservation = [];
        $reservationsByLine = [];
        foreach ($reservationRows as $row) {
            $id = (string) $row->id;
            $reservationsById[$id] = $row;
            $openByReservation[$id] = Quantity::of((string) $row->quantity)->minus(Quantity::of((string) $row->consumed_quantity))->minus(Quantity::of((string) $row->released_quantity));
            $reservationsByLine[(string) $row->invoice_line_id][] = $id;
        }

        $lineErrors = new FieldErrors;
        /** @var array<string, int|string|null> $details */
        $details = [];
        $refusalCode = null;
        $requestedByLine = [];
        $firstIndexByLine = [];
        /** @var array<int, list<array{reservation_id: string, position_id: string, lot_id: ?string, quantity: FixedDecimal}>> $planned */
        $planned = [];
        foreach ($lines as $index => $line) {
            $prefix = 'lines.'.$index;
            $lineId = $line['invoice_line_id'];
            /** @var FixedDecimal $quantity */
            $quantity = $line['quantity'];
            $origin = $invoiceLines[$lineId] ?? null;
            if ($origin === null) {
                $lineErrors->add($prefix.'.invoice_line_id', 'Cette ligne n\'appartient pas à la facture.');

                continue;
            }
            $item = $origin->catalog_item_id === null ? null : ($items[(string) $origin->catalog_item_id] ?? null);
            if ($item === null || (string) $origin->catalog_item_id !== $line['item_id']) {
                $lineErrors->add($prefix.'.item_id', $origin->catalog_item_id === null
                    ? 'Une ligne libre (sans article du catalogue) ne se remet pas : elle ne porte aucun article.'
                    : 'L\'article ne correspond pas à cette ligne de facture.');

                continue;
            }
            $firstIndexByLine[$lineId] ??= $index;
            try {
                Quantity::forLine($quantity, (bool) $item->quantity_integer_only);
                $requested = ($requestedByLine[$lineId] ?? Quantity::of('0'))->plus($quantity);
                DeliveryRules::assertWithinRemaining($requested, Quantity::of((string) $origin->quantity), $delivered[$lineId] ?? Quantity::of('0'));
                $requestedByLine[$lineId] = $requested;
            } catch (RuleViolation $violation) {
                $lineErrors->addViolation($prefix, $violation);
                $details += $violation->details + ['invoice_line_id' => $lineId];

                continue;
            }

            if ((string) $item->stock_mode !== 'STOCKED') {
                if ($line['stock_position_id'] !== null || $line['stock_lot_id'] !== null || $line['reservation_id'] !== null || $line['stock_movement_id'] !== null) {
                    $lineErrors->add($prefix.'.reservation_id', 'Cet article n\'est pas géré en stock : sa remise ne porte ni réserve, ni position, ni lot, ni mouvement.');

                    continue;
                }
                $planned[$index] = [];

                continue;
            }

            $candidates = [];
            foreach ($reservationsByLine[$lineId] ?? [] as $reservationId) {
                $row = $reservationsById[$reservationId];
                $lotId = $row->stock_lot_id === null ? null : (string) $row->stock_lot_id;
                $expires = $lotId !== null && isset($lotRows[$lotId]) && $lotRows[$lotId]->expires_on !== null ? CivilDate::fromString((string) $lotRows[$lotId]->expires_on) : null;
                $candidates[] = ['id' => $reservationId, 'position_id' => (string) $row->stock_position_id, 'lot_id' => $lotId, 'open' => $openByReservation[$reservationId], 'expires_on' => $expires];
            }
            try {
                $allocations = DeliveryRules::allocate($quantity, $candidates, $line['reservation_id'], $line['stock_lot_id'], $line['stock_position_id']);
            } catch (RuleViolation $violation) {
                $lineErrors->addViolation($prefix, $violation);
                $details += $violation->details + ['invoice_line_id' => $lineId];
                $refusalCode ??= $violation->errorCode === RuleViolation::VALIDATION_FAILED ? null : $violation->errorCode;

                continue;
            }
            foreach ($allocations as $allocation) {
                $openByReservation[$allocation['reservation_id']] = $openByReservation[$allocation['reservation_id']]->minus($allocation['quantity']);
            }
            $planned[$index] = $allocations;
        }
        if (! $lineErrors->isEmpty()) {
            if ($refusalCode !== null) {
                throw new BusinessError($refusalCode, 'Le stock réservé pour cette vente ne couvre pas la remise demandée.', 409, $lineErrors->all(), null, [], $details);
            }
            throw new BusinessError('VALIDATION_FAILED', 'Certaines lignes de la remise ne visent pas cette facture ou dépassent le reste à remettre.', 422, $lineErrors->all(), null, [], $details);
        }

        // 4. Facture non soldée : remise à crédit seulement avec l'approbation exacte (contrôlée après le stock, rang 90).
        $ledger = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        $amountDue = $ledger->amountDue();
        $credit = $amountDue > 0;
        if ($credit && $approvalId === null) {
            throw new BusinessError(
                'APPROVAL_REQUIRED_OR_EXPIRED',
                'Cette facture a encore un reste à encaisser ('.number_format($amountDue, 0, ',', ' ').' FCFA) : la remise à crédit exige l\'approbation exacte de la gérante. Demandez-la, puis reprenez avec son identifiant.',
                422,
                ['credit_delivery_approval_id' => ['Approbation de la gérante obligatoire pour remettre une facture avant son règlement complet.']],
                null,
                [],
                ['amount_due' => $amountDue],
            );
        }

        // 5. Consommation des réserves : positions (80), lots (81), quotas (82).
        $localDate = $deliveredAt->civilDate($timezone);
        $allocations = [];
        foreach ($planned as $index => $lineAllocations) {
            foreach ($lineAllocations as $allocation) {
                $allocations[] = ['reservation' => $reservationsById[$allocation['reservation_id']], 'quantity' => $allocation['quantity'], 'invoice_line_id' => $lines[$index]['invoice_line_id']];
            }
        }
        try {
            $versions = $this->reservations->consumeForDelivery($context->clinicId, $locks, $localDate, $allocations, $at);
        } catch (ReservationRefused $refused) {
            throw $refused->toBusinessError('lines.'.($firstIndexByLine[$refused->invoiceLineId] ?? 0).'.quantity');
        }

        // 6. Approbation (90), consommée, ou exécution directe de la gérante.
        $authorization = null;
        if ($credit) {
            $subjectLines = [];
            foreach ($requestedByLine as $lineId => $requested) {
                $subjectLines[] = ['invoice_line_id' => (string) $lineId, 'quantity' => $requested->canonical()];
            }
            $authorization = $this->approvals->authorize(
                $locks,
                $context,
                $approvalId,
                ApprovalSubject::creditDelivery($invoiceId, $amountDue, $subjectLines),
                'Remise avant règlement complet (reste à encaisser : '.$amountDue.' FCFA).',
                'credit_delivery_approval_id',
            );
        }

        // 7. Faits : remise en brouillon, lignes, mouvements, puis confirmation figée.
        $nowDb = $at->format('Y-m-d H:i:s.u');
        $deliveredDb = CarbonImmutable::createFromTimestampMsUTC($deliveredAt->epochMilliseconds)->format('Y-m-d H:i:s.u');
        $patient = DB::table('patient_records')->where('clinic_id', $context->clinicId)->where('id', $invoice->patient_id)->first();
        $patientSnapshot = $patient instanceof stdClass
            ? InvoiceSnapshots::patient($patient)
            : json_decode((string) $invoice->patient_snapshot, true, 512, JSON_THROW_ON_ERROR);

        DB::table('deliveries')->insert([
            'id' => $deliveryId,
            'clinic_id' => $context->clinicId,
            'invoice_id' => $invoiceId,
            'status' => 'DRAFT',
            'delivered_at' => null,
            'delivered_by' => null,
            'credit_delivery_approval_id' => null,
            'idempotency_id' => $idempotencyId,
            'patient_snapshot' => null,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);

        $reason = 'Remise au patient, facture '.(string) $invoice->number;
        $resultLines = [];
        $movementIds = [];
        $movementPosition = 0;
        foreach ($planned as $index => $lineAllocations) {
            $line = $lines[$index];
            /** @var FixedDecimal $quantity */
            $quantity = $line['quantity'];
            if ($lineAllocations === []) {
                DB::table('delivery_lines')->insert([
                    'id' => $line['delivery_line_id'],
                    'clinic_id' => $context->clinicId,
                    'item_id' => $line['item_id'],
                    'stock_position_id' => null,
                    'delivery_id' => $deliveryId,
                    'invoice_id' => $invoiceId,
                    'invoice_line_id' => $line['invoice_line_id'],
                    'quantity' => $quantity->canonical(),
                    'stock_lot_id' => null,
                    'workshop_order_id' => null,
                    'reservation_id' => null,
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                ]);
                $resultLines[] = ['id' => $line['delivery_line_id'], 'invoice_line_id' => $line['invoice_line_id'], 'item_id' => $line['item_id'], 'quantity' => $quantity->canonical(),
                    'stock_position_id' => null, 'stock_lot_id' => null, 'reservation_id' => null];

                continue;
            }
            foreach ($lineAllocations as $position => $allocation) {
                $deliveryLineId = $position === 0 ? $line['delivery_line_id'] : Ulid::generate();
                DB::table('delivery_lines')->insert([
                    'id' => $deliveryLineId,
                    'clinic_id' => $context->clinicId,
                    'item_id' => $line['item_id'],
                    'stock_position_id' => $allocation['position_id'],
                    'delivery_id' => $deliveryId,
                    'invoice_id' => $invoiceId,
                    'invoice_line_id' => $line['invoice_line_id'],
                    'quantity' => $allocation['quantity']->canonical(),
                    'stock_lot_id' => $allocation['lot_id'],
                    'workshop_order_id' => null,
                    'reservation_id' => $allocation['reservation_id'],
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                ]);
                $movementId = $position === 0 && $line['stock_movement_id'] !== null ? $line['stock_movement_id'] : Ulid::generate();
                $movementPosition++;
                DB::table('stock_movements')->insert([
                    'id' => $movementId,
                    'clinic_id' => $context->clinicId,
                    'movement_position' => $movementPosition,
                    'item_id' => $line['item_id'],
                    'position_id' => $allocation['position_id'],
                    'lot_id' => $allocation['lot_id'],
                    'kind' => 'SALE_DELIVERY',
                    'signed_quantity' => Quantity::of('0')->minus($allocation['quantity'])->canonical(),
                    'unit_cost' => $allocation['lot_id'] !== null && isset($lotRows[$allocation['lot_id']]) && $lotRows[$allocation['lot_id']]->unit_cost !== null ? (int) $lotRows[$allocation['lot_id']]->unit_cost : null,
                    'delivery_line_id' => $deliveryLineId,
                    'physical_return_id' => null,
                    'goods_receipt_line_id' => null,
                    'approval_id' => null,
                    'reason' => $reason,
                    'occurred_at' => $deliveredDb,
                    'actor_id' => $context->actorId,
                    'idempotency_id' => $idempotencyId,
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                ]);
                $movementIds[] = $movementId;
                $resultLines[] = ['id' => $deliveryLineId, 'invoice_line_id' => $line['invoice_line_id'], 'item_id' => $line['item_id'], 'quantity' => $allocation['quantity']->canonical(),
                    'stock_position_id' => $allocation['position_id'], 'stock_lot_id' => $allocation['lot_id'], 'reservation_id' => $allocation['reservation_id']];
            }
        }

        $creditApprovalId = $authorization['id'] ?? null;
        DB::table('deliveries')->where('clinic_id', $context->clinicId)->where('id', $deliveryId)->update([
            'status' => 'CONFIRMED',
            'delivered_at' => $deliveredDb,
            'delivered_by' => $context->actorId,
            'credit_delivery_approval_id' => $creditApprovalId,
            'patient_snapshot' => json_encode($patientSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $nowDb,
        ]);

        // Ligne entièrement remise après cette remise ? (affichage et audit : « remise totale » ou « partielle »).
        $after = self::deliveredByLine($context->clinicId, $invoiceId);
        $complete = true;
        foreach ($invoiceLines as $lineId => $origin) {
            if ($origin->catalog_item_id === null) {
                continue;
            }
            if (($after[(string) $lineId] ?? Quantity::of('0'))->compare(Quantity::of((string) $origin->quantity)) < 0) {
                $complete = false;
            }
        }

        $deliveredAtText = $deliveredAt->toString();
        $changes = [new ChangeRecord('deliveries', $deliveryId, 1, ChangeVisibility::Financial, ChangeKind::Created)];
        foreach ($versions['positions'] as $positionId => $version) {
            $changes[] = new ChangeRecord('stock_positions', (string) $positionId, $version, ChangeVisibility::Financial, ChangeKind::Updated);
        }

        return new CommandOutcome(
            result: [
                'id' => $deliveryId,
                'invoice_id' => $invoiceId,
                'status' => 'CONFIRMED',
                'delivered_at' => $deliveredAtText,
                'delivered_by' => $context->actorId,
                'credit_delivery_approval_id' => $creditApprovalId,
                'lines' => $resultLines,
            ],
            resultType: 'deliveries',
            resultId: $deliveryId,
            audit: [new AuditEntry('delivery.confirmed', 'deliveries', $deliveryId, null, [
                'invoice_id' => $invoiceId,
                'invoice_number' => (string) $invoice->number,
                'lines' => count($resultLines),
                'movements' => count($movementIds),
                'complete' => $complete,
                'credit' => $credit,
                'amount_due' => $amountDue,
                'approval_id' => $creditApprovalId,
                'approval_mode' => $authorization['mode'] ?? null,
                'stock_modes' => array_values(array_unique($versions['modes'])),
            ])],
            events: [new OutboxEvent('DeliveryConfirmed', 1, 'deliveries', $deliveryId, 1, [
                'delivery_id' => $deliveryId,
                'invoice_id' => $invoiceId,
                'delivered_at' => $deliveredAtText,
                'line_count' => count($resultLines),
            ])],
            changes: $changes,
        );
    }

    /**
     * Quantités déjà remises par ligne de facture (remises confirmées).
     *
     * @return array<string, FixedDecimal>
     */
    public static function deliveredByLine(string $clinicId, string $invoiceId): array
    {
        $totals = [];
        foreach (DB::table('delivery_lines')
            ->join('deliveries', static function ($join): void {
                $join->on('deliveries.clinic_id', '=', 'delivery_lines.clinic_id')->on('deliveries.id', '=', 'delivery_lines.delivery_id');
            })
            ->where('delivery_lines.clinic_id', $clinicId)
            ->where('delivery_lines.invoice_id', $invoiceId)
            ->where('deliveries.status', 'CONFIRMED')
            ->get(['delivery_lines.invoice_line_id', 'delivery_lines.quantity']) as $row) {
            $key = (string) $row->invoice_line_id;
            $totals[$key] = ($totals[$key] ?? Quantity::of('0'))->plus(Quantity::of((string) $row->quantity));
        }

        return $totals;
    }
}
