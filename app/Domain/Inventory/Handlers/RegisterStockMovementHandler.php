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
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Inventory\StockCounters;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Quantity;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Casse et ajustement de stock hors vente (I02, EE028 ; fichier 03 : « Ajustement : motif, approbation gérante » ;
 * CHECK `ck_stock_movements_05` et `_06`). Route `POST /stock/movements`, droit `stock.adjust`, réauthentification
 * récente. Le stock initial passe par `POST /stock/opening`, les sorties de vente par la remise (ConfirmDelivery), les
 * retours par `RegisterPhysicalReturn` : aucune de ces natures n'est admise ici.
 *
 *  - CASSE (`BREAKAGE`) : quantité négative (article détruit, perdu) ;
 *  - AJUSTEMENT (`ADJUSTMENT`) : quantité signée non nulle (écart de comptage constaté), positive ou négative.
 *
 * Un retrait ne prend jamais la marchandise réservée pour une vente émise ni celle confiée à un poste : au plus le
 * disponible central (`StockCounters`). Un article suivi par lots est ajusté lot par lot (le lot est obligatoire dès que
 * la position a des lots ; la position et le lot bougent ensemble). Quantité entière pour un article vendu à l'unité.
 *
 * Approbation EXACTE obligatoire (`approval_id` : position, article, lot, nature, quantité signée), consommée une fois,
 * ou exécution directe de la gérante sous ce même identifiant (`DirectApproval`). Motif obligatoire. Coût unitaire
 * connu ou explicitement inconnu (`null`, jamais zéro inventé).
 *
 * Verrous : cabinet, idempotence, position (80), lot (81), approbation (90).
 */
final class RegisterStockMovementHandler implements CommandHandler
{
    public const COMMAND = 'RegisterStockMovement';

    private const KINDS = ['BREAKAGE', 'ADJUSTMENT'];

    public function __construct(private readonly ExactApprovals $approvals) {}

    public function permissions(): array
    {
        return ['stock.adjust'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'Une casse ou un ajustement de stock');
        if ($idempotencyId === null || $context->actorId === null) {
            throw new LogicException('Un mouvement de stock exige une clé d\'idempotence et un auteur identifié.');
        }

        $payload = $command->payload;
        $movementId = (string) $payload['movement_id'];
        $itemId = (string) $payload['item_id'];
        $positionId = (string) $payload['position_id'];
        $lotId = is_string($payload['lot_id'] ?? null) ? $payload['lot_id'] : null;
        $kind = (string) $payload['kind'];
        $approvalId = (string) $payload['approval_id'];
        $at = CarbonImmutable::now('UTC');
        $errors = new FieldErrors;

        $signed = null;
        try {
            $signed = FixedDecimal::parse(is_string($payload['signed_quantity'] ?? null) ? $payload['signed_quantity'] : null, Quantity::SCALE, Quantity::INTEGER_DIGITS, true, 'signed_quantity');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($signed === null || $signed->isZero()) {
            $errors->add('signed_quantity', 'La quantité du mouvement est obligatoire et non nulle.');
        } elseif ($kind === 'BREAKAGE' && ! $signed->isNegative()) {
            $errors->add('signed_quantity', 'Une casse retire du stock : quantité négative.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            $errors->add('kind', 'Seules la casse et l\'ajustement s\'enregistrent ici.');
        }
        $unitCost = $payload['unit_cost'] ?? null;
        if ($unitCost !== null) {
            try {
                $unitCost = Money::amount((int) $unitCost, 'unit_cost');
            } catch (RuleViolation $violation) {
                $errors->addViolation('', $violation);
            }
        }
        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($reason === null) {
            $errors->add('reason', 'Le motif du mouvement est obligatoire.');
        }
        $occurredAt = null;
        try {
            $occurredAt = Instant::parse((string) $payload['occurred_at'], 'occurred_at');
            $skew = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
            if ($occurredAt === null) {
                $errors->add('occurred_at', 'La date du constat est obligatoire.');
            } elseif ($occurredAt->epochMilliseconds > ((int) $at->format('Uv')) + $skew) {
                $errors->add('occurred_at', 'La date du constat ne peut pas être dans le futur.');
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if (DB::table('stock_movements')->where('id', $movementId)->exists()) {
            $errors->add('movement_id', 'Cet identifiant de mouvement est déjà utilisé.');
        }

        $item = DB::table('catalog_items')->where('clinic_id', $context->clinicId)->where('id', $itemId)->first();
        if ($item === null || (string) $item->stock_mode !== 'STOCKED') {
            $errors->add('item_id', 'Seul un article géré en stock reçoit une casse ou un ajustement.');
        } elseif ($signed !== null && (bool) $item->quantity_integer_only && ! $signed->isInteger()) {
            $errors->add('signed_quantity', 'Cet article se vend à l\'unité : quantité entière obligatoire.');
        }
        $positionRow = DB::table('stock_positions')->where('clinic_id', $context->clinicId)->where('id', $positionId)->first(['id', 'item_id']);
        if ($positionRow === null || (string) $positionRow->item_id !== $itemId) {
            $errors->add('position_id', 'Position de stock introuvable pour cet article.');
        }
        $hasLots = DB::table('stock_lots')->where('clinic_id', $context->clinicId)->where('stock_position_id', $positionId)->exists();
        if ($lotId === null && ($hasLots || ($item !== null && (bool) $item->requires_expiry))) {
            $errors->add('lot_id', 'Cet article est suivi par lots : indiquez le lot concerné.');
        }
        if ($lotId !== null && ! DB::table('stock_lots')->where('clinic_id', $context->clinicId)->where('id', $lotId)->where('stock_position_id', $positionId)->exists()) {
            $errors->add('lot_id', 'Ce lot n\'appartient pas à cette position de stock.');
        }
        $errors->throwIfAny();
        if ($signed === null || $occurredAt === null || $reason === null) {
            throw new LogicException('Saisie acceptée incomplète.');
        }

        // 1. Position (80) et lot (81), relus sous verrou : le disponible y est jugé.
        $position = $locks->stockPositions([$positionId])[$positionId] ?? null;
        $lot = $lotId === null ? null : ($locks->stockLots([$lotId])[$lotId] ?? null);
        if ($position === null || ($lotId !== null && $lot === null)) {
            throw BusinessError::notFound('Position ou lot de stock introuvable dans ce cabinet.');
        }
        try {
            $positionOnHand = StockCounters::onHandAfter($position, $signed);
            $lotOnHand = $lot === null ? null : StockCounters::onHandAfter($lot, $signed);
        } catch (RuleViolation $violation) {
            throw $violation->toBusinessError();
        }

        // 2. Approbation exacte (90), consommée, ou exécution directe de la gérante.
        $authorization = $this->approvals->authorize(
            $locks,
            $context,
            $approvalId,
            ApprovalSubject::stockMovement($positionId, $itemId, $lotId, $kind, $signed->canonical()),
            $reason,
        );

        // 3. Fait append-only, puis projections dans la même transaction.
        $nowDb = $at->format('Y-m-d H:i:s.u');
        $occurredDb = CarbonImmutable::createFromTimestampMsUTC($occurredAt->epochMilliseconds)->format('Y-m-d H:i:s.u');
        DB::table('stock_movements')->insert([
            'id' => $movementId,
            'clinic_id' => $context->clinicId,
            'movement_position' => 1,
            'item_id' => $itemId,
            'position_id' => $positionId,
            'lot_id' => $lotId,
            'kind' => $kind,
            'signed_quantity' => $signed->canonical(),
            'unit_cost' => $unitCost,
            'delivery_line_id' => null,
            'physical_return_id' => null,
            'goods_receipt_line_id' => null,
            'approval_id' => $approvalId,
            'reason' => $reason,
            'occurred_at' => $occurredDb,
            'actor_id' => $context->actorId,
            'idempotency_id' => $idempotencyId,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);
        $version = StockCounters::write($context->clinicId, 'stock_positions', $position, $positionOnHand, $nowDb);
        if ($lot !== null && $lotOnHand !== null) {
            StockCounters::write($context->clinicId, 'stock_lots', $lot, $lotOnHand, $nowDb);
        }

        $occurredText = $occurredAt->toString();

        return new CommandOutcome(
            result: [
                'id' => $movementId,
                'item_id' => $itemId,
                'position_id' => $positionId,
                'lot_id' => $lotId,
                'kind' => $kind,
                'signed_quantity' => $signed->canonical(),
                'occurred_at' => $occurredText,
                'reason' => $reason,
                'approval_id' => $approvalId,
            ],
            resultType: 'stock_movements',
            resultId: $movementId,
            audit: [new AuditEntry('stock.movement_registered', 'stock_movements', $movementId, $reason, [
                'kind' => $kind,
                'item_id' => $itemId,
                'position_id' => $positionId,
                'lot_id' => $lotId,
                'signed_quantity' => $signed->canonical(),
                'on_hand_after' => $positionOnHand->canonical(),
                'approval_id' => $approvalId,
                'approval_mode' => $authorization['mode'],
            ])],
            changes: [new ChangeRecord('stock_positions', $positionId, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
