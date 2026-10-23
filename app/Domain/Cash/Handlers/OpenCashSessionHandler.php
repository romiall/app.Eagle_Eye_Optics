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
use App\Domain\Billing\Guards\AuthoredSource;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ouverture d'une session de caisse avec son fond (B05, EE030, AT25) : UNE session ouverte par caisse ET par
 * caissière (clés uniques sur les colonnes générées de `cash_sessions`, vérifiées ici pour répondre 409 plutôt
 * qu'une erreur SQL). La caissière est le compte connecté, jamais un champ de la charge. Un seul poste
 * d'encaissement principal au départ : la caisse est celle créée par `eeo:sales:bootstrap`, aucune route n'en
 * crée une autre.
 *
 * Tout encaissement EN ESPÈCES exige ensuite une session ouverte (`RegisterPayment`) ; les autres modes
 * n'en dépendent pas. La date d'ouverture est celle du serveur ; l'instant du client doit seulement être plausible.
 * Commande d'un poste synchronisée (O05) : la session a été ouverte sur le poste, avant ses encaissements hors-ligne ;
 * sa date d'ouverture est l'instant où le poste l'a ouverte (jugé plausible par l'autorisation historique).
 * Verrous : cabinet, idempotence, session de caisse (70).
 */
final class OpenCashSessionHandler implements CommandHandler
{
    public const COMMAND = 'OpenCashSession';

    public function permissions(): array
    {
        return ['cash.own_session'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        AuthoredSource::assert($context, 'L\'ouverture d\'une session de caisse');

        $payload = $command->payload;
        $sessionId = (string) $payload['cash_session_id'];
        $registerId = (string) $payload['cash_register_id'];
        $opening = (int) $payload['opening_amount'];
        $at = CarbonImmutable::now('UTC');
        $openedAt = $context->pieceInstant($at);
        $errors = new FieldErrors;

        try {
            $claimed = Instant::parse((string) $payload['opened_at'], 'opened_at');
            $skew = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
            if ($claimed !== null && $claimed->epochMilliseconds > ((int) $at->format('Uv')) + $skew) {
                $errors->add('opened_at', 'L\'horloge du poste est en avance sur celle du serveur : vérifiez l\'heure de l\'appareil.');
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }

        $register = DB::table('cash_registers')->where('clinic_id', $context->clinicId)->where('id', $registerId)->first();
        if ($register === null || ! (bool) $register->active) {
            $errors->add('cash_register_id', 'Caisse introuvable ou désactivée.');
        }
        if (DB::table('cash_sessions')->where('id', $sessionId)->exists()) {
            $errors->add('cash_session_id', 'Cet identifiant de session est déjà utilisé.');
        }
        $errors->throwIfAny();

        $locks->cashSession($sessionId);
        $open = DB::table('cash_sessions')->where('clinic_id', $context->clinicId)->whereNull('closed_at')
            ->where(static function ($where) use ($registerId, $context): void {
                $where->where('cash_register_id', $registerId)->orWhere('cashier_id', $context->actorId);
            })
            ->first();
        if ($open !== null) {
            throw BusinessError::stateRefused(
                (string) $open->cash_register_id === $registerId ? 'Une session est déjà ouverte sur cette caisse.' : 'Vous avez déjà une session de caisse ouverte.',
                'OPEN',
            );
        }

        $now = $at->format('Y-m-d H:i:s.u');
        DB::table('cash_sessions')->insert([
            'id' => $sessionId,
            'clinic_id' => $context->clinicId,
            'cash_register_id' => $registerId,
            'cashier_id' => $context->actorId,
            'opened_at' => $openedAt->format('Y-m-d H:i:s.u'),
            'opening_amount' => $opening,
            'closed_at' => null,
            'counted_amount' => null,
            'expected_amount' => null,
            'variance' => null,
            'variance_reason' => null,
            'closed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: [
                'cash_session_id' => $sessionId,
                'cash_register_id' => $registerId,
                'opened_at' => $openedAt->format('Y-m-d\TH:i:s.v\Z'),
                'opening_amount' => $opening,
            ],
            resultType: 'cash_sessions',
            resultId: $sessionId,
            audit: [new AuditEntry('cash_session.opened', 'cash_sessions', $sessionId, null, [
                'cash_register_id' => $registerId,
                'opening_amount' => $opening,
            ])],
            changes: [new ChangeRecord('cash_sessions', $sessionId, 1, ChangeVisibility::Financial, ChangeKind::Created)],
        );
    }
}
