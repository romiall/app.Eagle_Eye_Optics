<?php

namespace App\Domain\Patients\Handlers;

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
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'une visite de la file du jour (P03 ; fichier 02 : la file distingue « annulé ») : la visite passe
 * `CANCELLED` avec sa version attendue (409 `VERSION_CONFLICT` avec `current_version` si elle a changé) et
 * `closed_at` à l'instant du serveur. Rien n'est supprimé : la visite reste dans la file du jour, marquée annulée, et
 * l'historique n'est jamais réécrit.
 *
 * Annulable seulement AVANT l'examen : `ARRIVED`, `AWAITING_EXAM_FEE` ou `READY_FOR_EXAM`. Une visite dont l'examen a
 * démarré (consultation existante, `IN_CONSULTATION`, `COMPLETED`) ou déjà annulée : 409 `STATE_TRANSITION_REFUSED`
 * avec `details.current_status`. Une visite liée à une facture non abandonnée (brouillon ou facture émise) : 409
 * également, l'argent ne se règle pas par l'annulation d'une visite (abandonner le brouillon, ou corriger la facture
 * émise par avoir, B07).
 *
 * Motif OBLIGATOIRE (3 à 500 caractères, information d'accueil seulement), conservé dans l'audit, jamais ailleurs
 * (aucune colonne ne le porte). Même droit que l'enregistrement d'une arrivée (`visit.register` : accueil, gérante,
 * professionnel), matrice 09. Commande EN LIGNE seulement (hors du journal d'un poste). Verrous : cabinet,
 * idempotence, visite (46).
 */
final class CancelVisitHandler implements CommandHandler
{
    public const COMMAND = 'CancelVisit';

    public const REASON_MIN_LENGTH = 3;

    public const REASON_MAX_LENGTH = 500;

    /** Statuts d'une visite encore avant l'examen : seuls ceux-là s'annulent. */
    public const CANCELLABLE = ['ARRIVED', 'AWAITING_EXAM_FEE', 'READY_FOR_EXAM'];

    public function permissions(): array
    {
        return ['visit.register'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('L\'annulation d\'une visite depuis un poste hors-ligne n\'est pas encore ouverte.');
        }

        $payload = $command->payload;
        $visitId = (string) $payload['visit_id'];
        $at = CarbonImmutable::now('UTC');

        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, self::REASON_MAX_LENGTH, 'reason');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['reason' => [$violation->getMessage()]]);
        }
        if ($reason === null || mb_strlen($reason) < self::REASON_MIN_LENGTH) {
            throw BusinessError::validation(['reason' => ['Indiquez le motif de l\'annulation (au moins '.self::REASON_MIN_LENGTH.' caractères).']]);
        }

        $visit = $locks->visit($visitId);
        if ($visit === null) {
            throw BusinessError::notFound('Visite introuvable dans ce cabinet.');
        }
        if ($command->expectedVersion === null || (int) $visit->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'La visite a changé depuis l\'affichage : la file est relue.', 409, [], null, [], ['current_version' => (int) $visit->version]);
        }

        $status = (string) $visit->status;
        if (! in_array($status, self::CANCELLABLE, true)) {
            throw BusinessError::stateRefused(
                $status === 'CANCELLED'
                    ? 'Cette visite est déjà annulée.'
                    : 'L\'examen de cette visite a démarré ou la visite est close : elle ne s\'annule plus.',
                $status,
            );
        }
        if (DB::table('consultations')->where('clinic_id', $context->clinicId)->where('visit_id', $visitId)->exists()) {
            throw BusinessError::stateRefused('L\'examen de cette visite a démarré : elle ne s\'annule plus.', $status);
        }
        if (DB::table('invoices')->where('clinic_id', $context->clinicId)->where('visit_id', $visitId)->where('status', '<>', 'ABANDONED')->exists()) {
            throw BusinessError::stateRefused(
                'Une facture est liée à cette visite : abandonnez son brouillon, ou corrigez la facture émise par un avoir, avant d\'annuler la visite.',
                $status,
            );
        }

        $version = (int) $visit->version + 1;
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('visits')->where('clinic_id', $context->clinicId)->where('id', $visitId)->update([
            'status' => 'CANCELLED',
            'closed_at' => $stamp,
            'version' => $version,
            'updated_at' => $stamp,
        ]);

        return new CommandOutcome(
            result: ['visit_id' => $visitId, 'version' => $version, 'status' => 'CANCELLED'],
            resultType: 'visits',
            resultId: $visitId,
            audit: [new AuditEntry('visit.cancelled', 'visits', $visitId, $reason, [
                'previous_status' => $status,
                'version' => $version,
            ])],
            changes: [new ChangeRecord('visits', $visitId, $version, ChangeVisibility::Administrative, ChangeKind::Updated)],
        );
    }
}
