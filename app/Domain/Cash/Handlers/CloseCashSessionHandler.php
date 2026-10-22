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
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Cash\CashSessions;
use App\Domain\Cash\Rules\CashClosing;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Clôture d'une session de caisse (B05, EE030, AT25) : le compté est saisi, l'attendu est RECALCULÉ par le serveur sous
 * le verrou de la session, l'écart est calculé et son motif est obligatoire s'il n'est pas nul (CHECK
 * `ck_cash_sessions_04`). La session close est figée : le trigger refuse toute nouvelle modification et tout nouvel
 * encaissement en espèces dans cette session ; une correction ultérieure ne réécrit jamais le comptage.
 *
 * Transition d'état (la table n'a pas de colonne version) : le serveur vérifie sous verrou que la session est
 * ouverte. `expected_amount` et `variance` de la charge sont des valeurs À VÉRIFIER : si l'attendu a changé depuis
 * l'affichage (un encaissement espèces est survenu), 409 `VERSION_CONFLICT` avec le nouvel attendu, et la caissière
 * recompte. Une caissière clôture sa propre session ; la gérante (`cash.manage`) clôture celle d'une autre.
 *
 * La date de clôture est celle du serveur. Verrou : cabinet, idempotence, session de caisse (70).
 */
final class CloseCashSessionHandler implements CommandHandler
{
    public const COMMAND = 'CloseCashSession';

    public function __construct(private readonly ActorRights $rights) {}

    public function permissions(): array
    {
        return ['cash.own_session'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La clôture d\'une session de caisse');
        if ($context->actorId === null) {
            throw new \LogicException('La clôture d\'une session a toujours un auteur identifié.');
        }

        $payload = $command->payload;
        $sessionId = (string) $payload['cash_session_id'];
        $counted = (int) $payload['counted_amount'];
        $at = CarbonImmutable::now('UTC');
        $errors = new FieldErrors;

        try {
            $claimed = Instant::parse((string) $payload['closed_at'], 'closed_at');
            $skew = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
            if ($claimed !== null && $claimed->epochMilliseconds > ((int) $at->format('Uv')) + $skew) {
                $errors->add('closed_at', 'L\'horloge du poste est en avance sur celle du serveur : vérifiez l\'heure de l\'appareil.');
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['variance_reason'] ?? null) ? $payload['variance_reason'] : null, 1000, 'variance_reason');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        $errors->throwIfAny();

        $session = $locks->cashSession($sessionId);
        if ($session === null) {
            throw BusinessError::notFound('Session de caisse introuvable dans ce cabinet.');
        }
        if ($session->closed_at !== null) {
            throw BusinessError::stateRefused('Cette session de caisse est déjà clôturée.', 'CLOSED');
        }
        if ((string) $session->cashier_id !== $context->actorId && ! $this->rights->has($context, 'cash.manage')) {
            throw BusinessError::forbidden('Cette session appartient à une autre caissière : seule la gérante la clôture.');
        }

        $expected = CashSessions::theoretical($session);
        if ((int) $payload['expected_amount'] !== $expected) {
            throw new BusinessError(
                'VERSION_CONFLICT',
                'Les espèces attendues ont changé depuis votre affichage : actualisez la session et recomptez.',
                409,
                [],
                null,
                [],
                ['expected_amount' => $expected],
            );
        }
        $variance = CashClosing::variance($counted, $expected);
        if ((int) $payload['variance'] !== $variance) {
            throw BusinessError::validation(['variance' => ['L\'écart est le compté moins l\'attendu : '.$variance.' FCFA.']]);
        }
        if ($variance !== 0 && $reason === null) {
            throw BusinessError::validation(['variance_reason' => ['Un écart de caisse exige un motif.']]);
        }

        $now = $at->format('Y-m-d H:i:s.u');
        DB::table('cash_sessions')->where('clinic_id', $context->clinicId)->where('id', $sessionId)->update([
            'closed_at' => $now,
            'counted_amount' => $counted,
            'expected_amount' => $expected,
            'variance' => $variance,
            'variance_reason' => $reason,
            'closed_by' => $context->actorId,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: [
                'cash_session_id' => $sessionId,
                'closed_at' => $at->format('Y-m-d\TH:i:s.v\Z'),
                'counted_amount' => $counted,
                'expected_amount' => $expected,
                'variance' => $variance,
            ],
            resultType: 'cash_sessions',
            resultId: $sessionId,
            audit: [new AuditEntry('cash_session.closed', 'cash_sessions', $sessionId, $reason, [
                'counted_amount' => $counted,
                'expected_amount' => $expected,
                'variance' => $variance,
                'closed_by_owner' => (string) $session->cashier_id === $context->actorId,
            ])],
            changes: [new ChangeRecord('cash_sessions', $sessionId, 2, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
