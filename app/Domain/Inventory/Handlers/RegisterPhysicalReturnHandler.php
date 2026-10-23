<?php

namespace App\Domain\Inventory\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Inventory\Rules\DeliveryRules;
use App\Domain\Inventory\StockCounters;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Quantity;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Retour physique d'un article remis (I02, EE027 ; fichier 03 : « Retour physique ≤ quantité remise non retournée,
 * inspection et destination justifiées ; un avoir seul n'augmente jamais le stock »). Route
 * `POST /deliveries/{id}/physical-returns`, droit `stock.adjust` (gérante par défaut), réauthentification récente.
 *
 * Le retour est lié à UNE ligne de remise confirmée ; le cumul des retours de la ligne ne dépasse jamais la quantité
 * remise. Inspection : état constaté (`condition_code`) et destination (`accepted_for_restock`). Seul un article
 * vendable remis en stock écrit un mouvement `PATIENT_RETURN` positif, dans la position et le lot d'origine (jamais un
 * lot expiré ou en quarantaine au jour local du retour) ; un article abîmé, en quarantaine ou non remis en vente reste
 * tracé par le retour, sans mouvement : le stock vendable n'augmente pas.
 *
 * Aucun avoir n'est créé : le remboursement éventuel est une décision financière distincte (`IssueCreditNote`, puis
 * `RefundCredit`). Un avoir déjà émis sur la même facture peut être RELIÉ au retour (`credit_note_id`), jamais exigé.
 * La personne qui contrôle et accepte le retour est celle qui l'enregistre (`approved_by` = l'auteur).
 *
 * Verrous : cabinet, idempotence, facture de la remise (60, sérialise les retours d'une même vente), position (80),
 * lot (81).
 */
final class RegisterPhysicalReturnHandler implements CommandHandler
{
    public const COMMAND = 'RegisterPhysicalReturn';

    public function permissions(): array
    {
        return ['stock.adjust'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'Le retour physique');
        if ($idempotencyId === null || $context->actorId === null) {
            throw new LogicException('Un retour physique exige une clé d\'idempotence et un auteur identifié.');
        }

        $payload = $command->payload;
        $returnId = (string) $payload['physical_return_id'];
        $deliveryLineId = (string) $payload['delivery_line_id'];
        $itemId = (string) $payload['item_id'];
        $condition = (string) $payload['condition_code'];
        $restock = (bool) $payload['accepted_for_restock'];
        $approvedBy = (string) $payload['approved_by'];
        $creditNoteId = is_string($payload['credit_note_id'] ?? null) ? $payload['credit_note_id'] : null;
        $movementId = is_string($payload['stock_movement_id'] ?? null) ? $payload['stock_movement_id'] : null;
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $timezone = (string) $clinic->timezone;
        $errors = new FieldErrors;

        $quantity = null;
        $reason = null;
        $occurredAt = null;
        try {
            $quantity = Quantity::parse(is_string($payload['quantity'] ?? null) ? $payload['quantity'] : null);
            if ($quantity === null) {
                $errors->add('quantity', 'La quantité retournée est obligatoire.');
            } else {
                Quantity::forLine($quantity, false);
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
            $quantity = null;
        }
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($reason === null) {
            $errors->add('reason', 'Le motif du retour est obligatoire (ce que le patient rapporte et pourquoi).');
        }
        try {
            $occurredAt = Instant::parse((string) $payload['occurred_at'], 'occurred_at');
            $skew = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
            if ($occurredAt === null) {
                $errors->add('occurred_at', 'L\'heure du retour est obligatoire.');
            } elseif ($occurredAt->epochMilliseconds > ((int) $at->format('Uv')) + $skew) {
                $errors->add('occurred_at', 'L\'heure du retour ne peut pas être dans le futur.');
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($approvedBy !== $context->actorId) {
            $errors->add('approved_by', 'Le retour est contrôlé et accepté par la personne qui l\'enregistre : indiquez votre propre compte.');
        }
        if ($restock && $condition !== 'SELLABLE') {
            $errors->add('accepted_for_restock', 'Seul un article contrôlé vendable est remis en stock.');
        }
        if (! $restock && $movementId !== null) {
            $errors->add('stock_movement_id', 'Un retour non remis en stock n\'écrit aucun mouvement : laissez ce champ vide.');
        }
        if (DB::table('physical_returns')->where('id', $returnId)->exists()) {
            $errors->add('physical_return_id', 'Cet identifiant de retour est déjà utilisé.');
        }
        if ($movementId !== null && DB::table('stock_movements')->where('id', $movementId)->exists()) {
            $errors->add('stock_movement_id', 'Cet identifiant de mouvement est déjà utilisé.');
        }
        $errors->throwIfAny();
        if ($quantity === null || $occurredAt === null || $reason === null) {
            throw new LogicException('Saisie acceptée incomplète.');
        }

        // 1. Ligne de remise confirmée, puis verrou de sa facture (60).
        $line = DB::table('delivery_lines')->where('clinic_id', $context->clinicId)->where('id', $deliveryLineId)->first();
        if ($line === null) {
            throw BusinessError::notFound('Ligne de remise introuvable dans ce cabinet.');
        }
        $locks->invoice((string) $line->invoice_id);
        $delivery = DB::table('deliveries')->where('clinic_id', $context->clinicId)->where('id', $line->delivery_id)->first();
        if ($delivery === null || (string) $delivery->status !== 'CONFIRMED') {
            throw BusinessError::stateRefused('Seule une remise confirmée reçoit un retour.', $delivery === null ? 'UNKNOWN' : (string) $delivery->status);
        }
        if ((string) $line->item_id !== $itemId) {
            throw BusinessError::validation(['item_id' => ['L\'article ne correspond pas à cette ligne de remise.']]);
        }
        if ($occurredAt->epochMilliseconds < (int) CarbonImmutable::parse((string) $delivery->delivered_at, 'UTC')->format('Uv')) {
            throw BusinessError::validation(['occurred_at' => ['Le retour ne peut pas précéder la remise.']]);
        }
        $item = DB::table('catalog_items')->where('clinic_id', $context->clinicId)->where('id', $itemId)->first();
        if ($item !== null && (bool) $item->quantity_integer_only && ! $quantity->isInteger()) {
            throw BusinessError::validation(['quantity' => ['Cet article se vend à l\'unité : quantité entière obligatoire.']]);
        }
        if ($creditNoteId !== null && ! DB::table('credit_notes')->where('clinic_id', $context->clinicId)->where('id', $creditNoteId)
            ->where('invoice_id', $line->invoice_id)->where('status', 'ISSUED')->exists()) {
            throw BusinessError::validation(['credit_note_id' => ['Avoir émis introuvable sur la facture de cette remise.']]);
        }

        $already = Quantity::of('0');
        foreach (DB::table('physical_returns')->where('clinic_id', $context->clinicId)->where('delivery_line_id', $deliveryLineId)->pluck('quantity') as $returned) {
            $already = $already->plus(Quantity::of((string) $returned));
        }
        try {
            DeliveryRules::assertReturnable($quantity, Quantity::of((string) $line->quantity), $already);
        } catch (RuleViolation $violation) {
            throw new BusinessError('VALIDATION_FAILED', $violation->getMessage(), 422, ['quantity' => [$violation->getMessage()]], $violation, [], $violation->details);
        }

        // 2. Destination : remise en stock dans la position et le lot d'origine (80, 81), contrôlée avant toute écriture.
        $position = null;
        $lot = null;
        $positionOnHand = null;
        $lotOnHand = null;
        if ($restock) {
            if ($line->stock_position_id === null) {
                throw BusinessError::validation(['accepted_for_restock' => ['Cet article n\'est pas géré en stock : son retour ne se remet pas en stock.']]);
            }
            $position = $locks->stockPositions([(string) $line->stock_position_id])[(string) $line->stock_position_id] ?? null;
            if ($line->stock_lot_id !== null) {
                $lot = $locks->stockLots([(string) $line->stock_lot_id])[(string) $line->stock_lot_id] ?? null;
            }
            if ($position === null || ($line->stock_lot_id !== null && $lot === null)) {
                throw new LogicException('Position ou lot d\'une ligne de remise introuvable.');
            }
            try {
                DeliveryRules::assertRestockable($condition, true, $lot === null ? null : (string) $lot->status,
                    $lot === null || $lot->expires_on === null ? null : CivilDate::fromString((string) $lot->expires_on), $occurredAt->civilDate($timezone));
                $positionOnHand = StockCounters::onHandAfter($position, $quantity, 'quantity');
                $lotOnHand = $lot === null ? null : StockCounters::onHandAfter($lot, $quantity, 'quantity');
            } catch (RuleViolation $violation) {
                throw $violation->toBusinessError();
            }
        }

        // 3. Faits : le retour, puis son mouvement et les projections s'il est remis en stock.
        $nowDb = $at->format('Y-m-d H:i:s.u');
        $occurredDb = CarbonImmutable::createFromTimestampMsUTC($occurredAt->epochMilliseconds)->format('Y-m-d H:i:s.u');
        DB::table('physical_returns')->insert([
            'id' => $returnId,
            'clinic_id' => $context->clinicId,
            'item_id' => $itemId,
            'delivery_line_id' => $deliveryLineId,
            'quantity' => $quantity->canonical(),
            'condition_code' => $condition,
            'accepted_for_restock' => $restock,
            'approved_by' => $approvedBy,
            'occurred_at' => $occurredDb,
            'reason' => $reason,
            'credit_note_id' => $creditNoteId,
            'idempotency_id' => $idempotencyId,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);

        $changes = [new ChangeRecord('physical_returns', $returnId, 1, ChangeVisibility::Financial, ChangeKind::Created)];
        $writtenMovement = null;
        if ($restock) {
            $writtenMovement = $movementId ?? Ulid::generate();
            DB::table('stock_movements')->insert([
                'id' => $writtenMovement,
                'clinic_id' => $context->clinicId,
                'movement_position' => 1,
                'item_id' => $itemId,
                'position_id' => (string) $line->stock_position_id,
                'lot_id' => $line->stock_lot_id,
                'kind' => 'PATIENT_RETURN',
                'signed_quantity' => $quantity->canonical(),
                'unit_cost' => $lot !== null && $lot->unit_cost !== null ? (int) $lot->unit_cost : null,
                'delivery_line_id' => null,
                'physical_return_id' => $returnId,
                'goods_receipt_line_id' => null,
                'approval_id' => null,
                'reason' => $reason,
                'occurred_at' => $occurredDb,
                'actor_id' => $context->actorId,
                'idempotency_id' => $idempotencyId,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
            ]);
            $version = StockCounters::write($context->clinicId, 'stock_positions', $position, $positionOnHand, $nowDb);
            if ($lot !== null && $lotOnHand instanceof FixedDecimal) {
                StockCounters::write($context->clinicId, 'stock_lots', $lot, $lotOnHand, $nowDb);
            }
            $changes[] = new ChangeRecord('stock_positions', (string) $line->stock_position_id, $version, ChangeVisibility::Financial, ChangeKind::Updated);
        }

        $occurredText = $occurredAt->toString();

        return new CommandOutcome(
            result: [
                'id' => $returnId,
                'delivery_line_id' => $deliveryLineId,
                'item_id' => $itemId,
                'quantity' => $quantity->canonical(),
                'condition_code' => $condition,
                'accepted_for_restock' => $restock,
                'occurred_at' => $occurredText,
                'stock_movement_id' => $writtenMovement,
                'credit_note_id' => $creditNoteId,
            ],
            resultType: 'physical_returns',
            resultId: $returnId,
            audit: [new AuditEntry('stock.physical_return_registered', 'physical_returns', $returnId, $reason, [
                'delivery_id' => (string) $line->delivery_id,
                'delivery_line_id' => $deliveryLineId,
                'invoice_id' => (string) $line->invoice_id,
                'quantity' => $quantity->canonical(),
                'condition_code' => $condition,
                'restocked' => $restock,
                'stock_movement_id' => $writtenMovement,
                'credit_note_id' => $creditNoteId,
                'credit_note_created' => false,
            ])],
            changes: $changes,
        );
    }
}
