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
use App\Domain\Cash\CashMovements;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;

/**
 * Revue de l'écart d'une session clôturée par la gérante (L1D05 ; sortie vérifiable « Écart motivé et autorisé »).
 * La clôture a figé l'attendu, le compté, l'écart et le motif de la caissière ; la gérante les ACCEPTE ou les CONTESTE,
 * avec une note, sans rien réécrire : la décision est un fait d'audit (`cash.variance_reviewed`), une seule fois par
 * session. Une contestation ne modifie ni la session ni les espèces : la suite (recherche, correction par un mouvement
 * d'une session ouverte, mesure disciplinaire) se décide hors de l'application. Mot de passe confirmé (route).
 */
final class ReviewCashVarianceHandler implements CommandHandler
{
    public const COMMAND = 'ReviewCashVariance';

    public const DECISIONS = ['ACCEPTED', 'DISPUTED'];

    public function permissions(): array
    {
        return ['cash.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La revue d\'un écart de caisse se fait en ligne.');
        }

        $payload = $command->payload;
        $sessionId = (string) $payload['cash_session_id'];
        $decision = (string) $payload['decision'];
        if (! in_array($decision, self::DECISIONS, true)) {
            throw BusinessError::validation(['decision' => ['Décision ACCEPTED ou DISPUTED attendue.']]);
        }
        try {
            $note = Text::optional(is_string($payload['note'] ?? null) ? $payload['note'] : null, 1000, 'note');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation([$violation->field => [$violation->getMessage()]]);
        }
        if ($decision === 'DISPUTED' && ($note === null || mb_strlen($note) < 3)) {
            throw BusinessError::validation(['note' => ['Une contestation exige une note (3 caractères au moins).']]);
        }

        $session = $locks->cashSession($sessionId);
        if ($session === null) {
            throw BusinessError::notFound('Session de caisse introuvable dans ce cabinet.');
        }
        if ($session->closed_at === null) {
            throw BusinessError::stateRefused('La session n\'est pas clôturée : son écart n\'existe pas encore.', 'OPEN');
        }
        if ((int) $session->variance === 0) {
            throw BusinessError::stateRefused('Cette session a été clôturée sans écart : rien à revoir.', 'NO_VARIANCE');
        }
        if (CashMovements::varianceReview($context->clinicId, $sessionId) !== null) {
            throw BusinessError::stateRefused('L\'écart de cette session a déjà été revu.', 'REVIEWED');
        }

        return new CommandOutcome(
            result: ['cash_session_id' => $sessionId, 'decision' => $decision],
            resultType: 'cash_sessions',
            resultId: $sessionId,
            audit: [new AuditEntry('cash.variance_reviewed', 'cash_sessions', $sessionId, $note, [
                'decision' => $decision,
                'variance' => (int) $session->variance,
                'expected_amount' => (int) $session->expected_amount,
                'counted_amount' => (int) $session->counted_amount,
            ])],
            changes: [new ChangeRecord('cash_sessions', $sessionId, 3, ChangeVisibility::Manager, ChangeKind::Updated)],
        );
    }
}
