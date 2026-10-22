<?php

namespace App\Domain\Inventory\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Contracts\ContractValidator;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\DirectApproval;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stock initial validé (I00, EE025) : positions, lots et mouvements `OPENING` d'un comptage fictif ou validé par la
 * gérante. Route `POST /stock/opening`, EN LIGNE seulement (le contrat fait de ce stock un acte de la gérante
 * présente, réauthentifiée). Un mouvement d'ouverture exige une approbation (CHECK `ck_stock_movements_06`) : la
 * gérante l'accorde en exécutant elle-même, `DirectApproval` écrit l'autorisation exacte (contenu empreint, motif)
 * dans la même transaction. Une approbation à distance (`approval_id` fourni) attend B06 : elle est refusée plutôt
 * qu'ignorée.
 *
 * Règles : articles gérés en stock (`STOCKED`) et actifs seulement ; emplacement actif ; quantité strictement
 * positive, entière si l'article l'exige ; lot obligatoire et daté pour un article périssable (collyre) ; le stock
 * initial d'un article dans un emplacement ne s'enregistre qu'UNE fois (ensuite : réception, inventaire,
 * ajustement approuvé) ; coût d'achat connu ou explicitement inconnu (`null`, jamais zéro). Les projections
 * (`stock_positions`, `stock_lots`) sont écrites dans la même transaction que les mouvements, dont elles sont la
 * somme.
 *
 * Verrous : positions existantes (rang 80), rien d'autre (les lignes créées ici n'existent pour personne d'autre).
 */
final class RegisterOpeningStockHandler implements CommandHandler
{
    public const COMMAND = 'RegisterOpeningStock';

    public const REQUEST_SCHEMA = 'schemas/requests/http.json#/$defs/OpeningStockRequest';

    public function __construct(private readonly ContractValidator $contracts, private readonly ActorRights $rights) {}

    public function permissions(): array
    {
        return ['stock.adjust'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($idempotencyId === null) {
            throw new \LogicException('Le stock initial exige une clé d\'idempotence : ses mouvements portent l\'identifiant de la commande.');
        }
        $this->contracts->assertCommandPayload(self::REQUEST_SCHEMA, $command->payload);
        if (! $this->rights->manager($context)) {
            throw BusinessError::forbidden('Le stock initial est validé par la gérante.');
        }

        $payload = $command->payload;
        $errors = new FieldErrors;
        $at = CarbonImmutable::now('UTC');
        $now = $at->format('Y-m-d H:i:s.u');

        if (($payload['approval_id'] ?? null) !== null) {
            $errors->add('approval_id', 'Les approbations à distance ne sont pas encore ouvertes : la gérante enregistre elle-même le stock initial (laissez ce champ vide).');
        }
        $countedAt = null;
        try {
            $countedAt = Instant::parse((string) $payload['counted_at'], 'counted_at');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        $skew = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
        if ($countedAt !== null && $countedAt->epochMilliseconds > ((int) $at->format('Uv')) + $skew) {
            $errors->add('counted_at', 'La date du comptage ne peut pas être dans le futur.');
        }
        $reason = trim((string) $payload['reason']);

        /** @var list<array<string, mixed>> $lines */
        $lines = array_values($payload['lines']);
        $itemIds = array_values(array_unique(array_map(static fn (array $line): string => (string) $line['item_id'], $lines)));
        $locationIds = array_values(array_unique(array_map(static fn (array $line): string => (string) $line['location_id'], $lines)));
        $items = DB::table('catalog_items')->where('clinic_id', $context->clinicId)->whereIn('id', $itemIds)->get()->keyBy('id');
        $locations = DB::table('stock_locations')->where('clinic_id', $context->clinicId)->whereIn('id', $locationIds)->get()->keyBy('id');
        $positions = DB::table('stock_positions')->where('clinic_id', $context->clinicId)->whereIn('item_id', $itemIds)->whereIn('location_id', $locationIds)->get();
        $positionByKey = [];
        foreach ($positions as $position) {
            $positionByKey[$position->item_id.'|'.$position->location_id] = $position;
        }

        $seenMovements = [];
        $seenLots = [];
        $prepared = [];
        foreach ($lines as $index => $line) {
            $prefix = 'lines.'.$index;
            $itemId = (string) $line['item_id'];
            $locationId = (string) $line['location_id'];
            $item = $items->get($itemId);
            $movementId = (string) $line['movement_id'];

            if (isset($seenMovements[$movementId]) || DB::table('stock_movements')->where('id', $movementId)->exists()) {
                $errors->add($prefix.'.movement_id', 'Cet identifiant de mouvement est déjà utilisé.');
            }
            $seenMovements[$movementId] = true;

            if ($item === null) {
                $errors->add($prefix.'.item_id', 'Article introuvable dans ce cabinet.');
            } elseif (! (bool) $item->active || (string) $item->stock_mode !== 'STOCKED') {
                $errors->add($prefix.'.item_id', 'Seul un article actif géré en stock reçoit un stock initial.');
            }
            $location = $locations->get($locationId);
            if ($location === null || ! (bool) $location->active) {
                $errors->add($prefix.'.location_id', 'Emplacement introuvable ou désactivé.');
            }

            $quantity = null;
            try {
                $quantity = Quantity::parse((string) $line['quantity'], 'quantity');
                if ($quantity === null) {
                    $errors->add($prefix.'.quantity', 'La quantité est obligatoire.');
                } elseif ($item !== null) {
                    Quantity::forLine($quantity, (bool) $item->quantity_integer_only, 'quantity');
                }
            } catch (RuleViolation $violation) {
                $errors->addViolation($prefix, $violation);
                $quantity = null;
            }

            $unitCost = $line['unit_cost'] ?? null;
            if ($unitCost !== null) {
                try {
                    $unitCost = Money::amount((int) $unitCost, 'unit_cost');
                } catch (RuleViolation $violation) {
                    $errors->addViolation($prefix, $violation);
                }
            }

            $lot = $line['lot'] ?? null;
            $lotPlan = null;
            if (is_array($lot)) {
                $batch = trim((string) $lot['batch_number']);
                $expires = null;
                if ($lot['expires_on'] !== null) {
                    try {
                        $expires = CivilDate::fromString((string) $lot['expires_on'], 'expires_on')->toString();
                    } catch (RuleViolation $violation) {
                        $errors->addViolation($prefix.'.lot', $violation);
                    }
                }
                $lotId = (string) $lot['lot_id'];
                if ($batch === '') {
                    $errors->add($prefix.'.lot.batch_number', 'Le numéro de lot est obligatoire.');
                }
                if (isset($seenLots[$lotId]) || DB::table('stock_lots')->where('id', $lotId)->exists()) {
                    $errors->add($prefix.'.lot.lot_id', 'Cet identifiant de lot est déjà utilisé.');
                }
                if (DB::table('stock_lots')->where('clinic_id', $context->clinicId)->where('item_id', $itemId)->where('location_id', $locationId)->where('batch_number', $batch)->exists()
                    || isset($seenLots['batch|'.$itemId.'|'.$locationId.'|'.$batch])) {
                    $errors->add($prefix.'.lot.batch_number', 'Ce numéro de lot existe déjà pour cet article dans cet emplacement.');
                }
                $seenLots[$lotId] = true;
                $seenLots['batch|'.$itemId.'|'.$locationId.'|'.$batch] = true;
                if ($item !== null && (bool) $item->requires_expiry && $expires === null) {
                    $errors->add($prefix.'.lot.expires_on', 'Un article périssable exige la date d\'expiration du lot.');
                }
                $lotPlan = ['id' => $lotId, 'batch_number' => $batch, 'expires_on' => $expires];
            } elseif ($item !== null && (bool) $item->requires_expiry) {
                $errors->add($prefix.'.lot', 'Un article périssable ne reçoit son stock initial que sur un lot daté.');
            }

            $existing = $positionByKey[$itemId.'|'.$locationId] ?? null;
            if ($existing !== null && DB::table('stock_movements')->where('clinic_id', $context->clinicId)->where('position_id', $existing->id)->exists()) {
                $errors->add($prefix.'.item_id', 'Le stock initial de cet article est déjà enregistré dans cet emplacement : utilisez une réception, un inventaire ou un ajustement approuvé.');
            }

            $prepared[] = ['movement_id' => $movementId, 'item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'lot' => $lotPlan];
        }
        $errors->throwIfAny();

        // Verrous : positions déjà existantes (rang 80), par identifiant trié.
        $existingIds = array_values(array_map(static fn (object $position): string => (string) $position->id, $positionByKey));
        $locks->stockPositions($existingIds);

        $approvalId = DirectApproval::record(
            $context,
            'stock.adjust',
            'stock_movements',
            $prepared[0]['movement_id'],
            CanonicalJson::hash(['lines' => array_map(static fn (array $line): array => [
                'item_id' => $line['item_id'],
                'location_id' => $line['location_id'],
                'quantity' => $line['quantity']?->canonical(),
                'unit_cost' => $line['unit_cost'],
                'lot' => $line['lot'],
            ], $prepared), 'counted_at' => $countedAt?->toString()]),
            $reason,
        );

        $countedDb = CarbonImmutable::createFromTimestampMsUTC((int) $countedAt?->epochMilliseconds)->format('Y-m-d H:i:s.u');
        $positionIds = [];
        $positionTotals = [];
        $changes = [];
        $movementIds = [];
        $position = 0;
        foreach ($prepared as $line) {
            $key = $line['item_id'].'|'.$line['location_id'];
            if (! isset($positionIds[$key])) {
                $positionIds[$key] = isset($positionByKey[$key]) ? (string) $positionByKey[$key]->id : Ulid::generate();
                $positionTotals[$key] = FixedDecimal::fromUnits(0, Quantity::SCALE);
                if (! isset($positionByKey[$key])) {
                    DB::table('stock_positions')->insert([
                        'id' => $positionIds[$key],
                        'clinic_id' => $context->clinicId,
                        'item_id' => $line['item_id'],
                        'location_id' => $line['location_id'],
                        'on_hand' => '0.000',
                        'reserved' => '0.000',
                        'offline_allocated' => '0.000',
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
            /** @var FixedDecimal $quantity */
            $quantity = $line['quantity'];
            $positionTotals[$key] = $positionTotals[$key]->plus($quantity);

            $lotId = null;
            if ($line['lot'] !== null) {
                $lotId = $line['lot']['id'];
                DB::table('stock_lots')->insert([
                    'id' => $lotId,
                    'clinic_id' => $context->clinicId,
                    'item_id' => $line['item_id'],
                    'location_id' => $line['location_id'],
                    'stock_position_id' => $positionIds[$key],
                    'batch_number' => $line['lot']['batch_number'],
                    'expires_on' => $line['lot']['expires_on'],
                    'received_at' => $countedDb,
                    'unit_cost' => $line['unit_cost'],
                    'status' => 'SELLABLE',
                    'on_hand' => $quantity->canonical(),
                    'reserved' => '0.000',
                    'offline_allocated' => '0.000',
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $position++;
            DB::table('stock_movements')->insert([
                'id' => $line['movement_id'],
                'clinic_id' => $context->clinicId,
                'movement_position' => $position,
                'item_id' => $line['item_id'],
                'position_id' => $positionIds[$key],
                'lot_id' => $lotId,
                'kind' => 'OPENING',
                'signed_quantity' => $quantity->canonical(),
                'unit_cost' => $line['unit_cost'],
                'approval_id' => $approvalId,
                'reason' => $reason,
                'occurred_at' => $countedDb,
                'actor_id' => $context->actorId,
                'idempotency_id' => $idempotencyId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $movementIds[] = $line['movement_id'];
        }

        foreach ($positionTotals as $key => $total) {
            $existing = $positionByKey[$key] ?? null;
            $version = 1;
            if ($existing !== null) {
                $version = (int) $existing->version + 1;
            }
            DB::table('stock_positions')->where('clinic_id', $context->clinicId)->where('id', $positionIds[$key])->update([
                'on_hand' => Quantity::bounded($total->units, 'on_hand')->canonical(),
                'version' => $version,
                'updated_at' => $now,
            ]);
            $changes[] = new ChangeRecord('stock_positions', $positionIds[$key], $version, ChangeVisibility::Financial, $existing === null ? ChangeKind::Created : ChangeKind::Updated);
        }

        return new CommandOutcome(
            result: ['movement_ids' => $movementIds, 'approval_id' => $approvalId],
            resultType: 'stock_movements',
            resultId: $movementIds[0],
            audit: [new AuditEntry('stock.opening_registered', 'stock_movements', $movementIds[0], $reason, [
                'movements' => count($movementIds),
                'positions' => count($positionIds),
                'approval_id' => $approvalId,
            ])],
            changes: $changes,
        );
    }
}
