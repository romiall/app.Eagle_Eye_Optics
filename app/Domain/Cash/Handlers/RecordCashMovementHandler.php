<?php

namespace App\Domain\Cash\Handlers;

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
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Cash\CashMovements;
use App\Domain\Cash\CashSessions;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Illuminate\Support\Facades\DB;

/**
 * Mouvement d'espèces hors vente d'une session de caisse OUVERTE (L1D05) : dépense catégorisée, dépôt, retrait ou
 * correction de l'ouverture. Commande EN LIGNE seulement (la caisse hors-ligne principale n'enregistre pas de dépense
 * tant qu'O05 n'est pas vérifié). Jamais un paiement : ni facture, ni reçu, ni chiffre d'affaires.
 *
 * Droits : la caissière dans SA session (`cash.own_session`) avec l'approbation exacte de la gérante (`CASH_MOVEMENT` :
 * même session, nature, montant signé et catégorie) ; la gérante (`cash.manage` et `approval.decide`) l'exécute
 * directement avec la même traçabilité (approbation écrite décidée et consommée sous l'identifiant fourni).
 *
 * Refus : session inconnue (404) ou clôturée (409 `STATE_TRANSITION_REFUSED`, `current_status` CLOSED : la clôture est
 * figée) ; session d'une autre caissière sans `cash.manage` (403) ; sortie supérieure aux espèces théoriques du tiroir
 * (422 sur `signed_amount`, avec le montant disponible) ; motif de 3 à 500 caractères. Verrous : session de caisse (70),
 * puis approbation (90).
 */
final class RecordCashMovementHandler implements CommandHandler
{
    public const COMMAND = 'RecordCashMovement';

    public function __construct(private readonly ExactApprovals $approvals, private readonly ActorRights $rights) {}

    public function permissions(): array
    {
        return ['cash.own_session', 'cash.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Les mouvements de caisse hors vente s\'enregistrent en ligne.');
        }

        $payload = $command->payload;
        $movementId = (string) $payload['movement_id'];
        $sessionId = (string) $payload['cash_session_id'];
        $kind = (string) $payload['movement_kind'];
        $signedAmount = is_int($payload['signed_amount'] ?? null) ? $payload['signed_amount'] : 0;
        $categoryId = is_string($payload['expense_category_id'] ?? null) ? $payload['expense_category_id'] : null;
        $approvalId = (string) $payload['approval_id'];

        try {
            CashMovements::assertShape($kind, $signedAmount, $categoryId);
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, CashMovements::REASON_MAX, 'reason');
            $reference = Text::optional(is_string($payload['reference'] ?? null) ? $payload['reference'] : null, CashMovements::REFERENCE_MAX, 'reference');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation([$violation->field => [$violation->getMessage()]]);
        }
        if ($reason === null || mb_strlen($reason) < CashMovements::REASON_MIN) {
            throw BusinessError::validation(['reason' => ['Indiquez le motif du mouvement (au moins '.CashMovements::REASON_MIN.' caractères).']]);
        }

        $session = $locks->cashSession($sessionId);
        if ($session === null) {
            throw BusinessError::notFound('Session de caisse introuvable dans ce cabinet.');
        }
        if ($session->closed_at !== null) {
            throw BusinessError::stateRefused('Cette session de caisse est clôturée : elle est figée, aucun mouvement ne s\'y ajoute.', 'CLOSED');
        }
        if ((string) $session->cashier_id !== (string) $context->actorId && ! $this->rights->has($context, 'cash.manage')) {
            throw BusinessError::forbidden('Cette session appartient à une autre caissière.');
        }
        if ($categoryId !== null && ! CashMovements::activeCategory($context->clinicId, $categoryId)) {
            throw BusinessError::validation(['expense_category_id' => ['Catégorie de dépense introuvable ou désactivée.']]);
        }

        $available = CashSessions::theoretical($session);
        if ($signedAmount < 0 && $available + $signedAmount < 0) {
            throw new BusinessError(
                'VALIDATION_FAILED',
                'Le tiroir ne contient en théorie que '.$available.' FCFA : une sortie ne peut pas dépasser les espèces présentes.',
                422,
                ['signed_amount' => ['Espèces théoriques disponibles : '.$available.' FCFA.']],
                null,
                [],
                ['available' => $available],
            );
        }

        $authorization = $this->approvals->authorize($locks, $context, $approvalId, ApprovalSubject::cashMovement($sessionId, $kind, $signedAmount, $categoryId), $reason);

        $stamp = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('cash_movements')->insert([
            'id' => $movementId,
            'clinic_id' => $context->clinicId,
            'cash_session_id' => $sessionId,
            'kind' => $kind,
            'signed_amount' => $signedAmount,
            'reason' => $reason,
            'approval_id' => $authorization['id'],
            'expense_category_id' => $categoryId,
            'reference' => $reference,
            'occurred_at' => $stamp,
            'created_by' => (string) $context->actorId,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return new CommandOutcome(
            result: ['movement_id' => $movementId, 'cash_session_id' => $sessionId, 'signed_amount' => $signedAmount, 'approval_mode' => $authorization['mode']],
            resultType: 'cash_movements',
            resultId: $movementId,
            audit: [new AuditEntry('cash.movement_recorded', 'cash_movements', $movementId, $reason, [
                'cash_session_id' => $sessionId,
                'movement_kind' => $kind,
                'signed_amount' => $signedAmount,
                'expense_category_id' => $categoryId,
                'approval_id' => $authorization['id'],
                'approval_mode' => $authorization['mode'],
            ])],
            changes: [new ChangeRecord('cash_movements', $movementId, 1, ChangeVisibility::Financial, ChangeKind::Created)],
        );
    }
}
