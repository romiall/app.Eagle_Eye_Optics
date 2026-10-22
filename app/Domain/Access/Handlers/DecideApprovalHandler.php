<?php

namespace App\Domain\Access\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\Approvals\ApprovalKind;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Billing\ExamFeeVisits;
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Décision de la gérante sur une demande d'approbation (B06 ; fichier 03 : « La gérante se réauthentifie avant
 * autorisation sensible ; une approbation distante passe par son compte personnel ; un code partagé permanent n'est pas
 * suffisant »). La route exige une réauthentification récente (`reauthenticated`, 423 sinon) ; la commande, elle :
 *
 *  - n'accepte qu'une demande EN ATTENTE et non échue (409 `STATE_TRANSITION_REFUSED` avec le statut effectif sinon :
 *    une décision ne se change pas, une demande échue se renouvelle) ;
 *  - refuse que la demandeuse décide de sa propre demande (séparation des tâches : la gérante qui veut agir elle-même
 *    exécute directement l'opération, avec la même traçabilité) ;
 *  - exige, en plus de `approval.decide`, la permission que l'approbation remplace (`action_code` : remise et prix,
 *    examen, correction de paiement, avoir, remboursement restent des droits distincts) ;
 *  - accorde pour une durée bornée : échéance = la plus proche de celle proposée par la demande et de l'instant de la
 *    décision + `eeo.approvals.ttl_minutes` (60 minutes par défaut, proposition à confirmer : Q07) ;
 *  - exige un motif pour un refus (il est rendu à la demandeuse) ; le motif est facultatif pour un accord.
 *
 * Une dispense d'examen accordée passe la visite « prête pour l'examen » (`ExamFeeVisits`) dans la même transaction. La
 * décision est auditée (`approval.decided`) avec la nature, la cible et l'empreinte, jamais un contenu clinique.
 *
 * Verrous : cabinet, idempotence, visite (46) pour une dispense d'examen, puis l'approbation (90, le dernier rang).
 */
final class DecideApprovalHandler implements CommandHandler
{
    public const COMMAND = 'DecideApproval';

    public function __construct(private readonly ActorRights $rights, private readonly ExamFeeVisits $visits) {}

    public function permissions(): array
    {
        return ['approval.decide'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Une décision d\'approbation depuis un poste hors-ligne n\'est pas encore ouverte : elle attend la connexion.');
        }
        if ($context->actorId === null) {
            throw new LogicException('Une décision d\'approbation a toujours une gérante identifiée.');
        }

        $payload = $command->payload;
        $approvalId = (string) $payload['approval_id'];
        $decision = (string) $payload['decision'];

        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 4000, 'reason');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['reason' => [$violation->getMessage()]]);
        }
        if ($decision === ExactApprovals::REFUSED && $reason === null) {
            throw BusinessError::validation(['reason' => ['Indiquez le motif du refus : il est rendu à la personne qui a demandé.']]);
        }

        // Lecture sans verrou pour connaître la cible (rang 46 de la visite avant le rang 90 de l'approbation).
        $peek = DB::table('approvals')->where('clinic_id', $context->clinicId)->where('id', $approvalId)->first();
        if ($peek === null) {
            throw BusinessError::notFound('Demande d\'approbation introuvable dans ce cabinet.');
        }
        $kind = ApprovalKind::fromStored((string) $peek->action_code, (string) $peek->target_type);
        $visit = $kind === ApprovalKind::ExamFeeWaiver ? $locks->visit((string) $peek->target_id) : null;

        $approval = $locks->approval($approvalId);
        if ($approval === null) {
            throw BusinessError::notFound('Demande d\'approbation introuvable dans ce cabinet.');
        }
        $current = ExactApprovals::effectiveStatus($approval, $context->now);
        if ($current !== ExactApprovals::REQUESTED) {
            throw BusinessError::stateRefused(
                $current === ExactApprovals::EXPIRED
                    ? 'Cette demande est échue : la personne qui l\'a faite doit en déposer une nouvelle.'
                    : 'Cette demande a déjà été décidée : une décision ne se modifie pas.',
                $current,
            );
        }
        if ((string) $approval->requested_by === $context->actorId) {
            throw BusinessError::forbidden('Une demande se décide par une autre personne que celle qui l\'a faite : exécutez directement l\'opération si vous en avez le droit.');
        }
        if ($kind === null || ! $this->rights->has($context, (string) $approval->action_code)) {
            throw BusinessError::forbidden('Votre compte ne détient pas la permission que cette approbation remplace : elle ne peut pas l\'accorder.');
        }

        $nowDb = $context->now->utc()->format('Y-m-d H:i:s.u');
        $expiresAt = null;
        if ($decision === ExactApprovals::APPROVED) {
            $limit = $context->now->utc()->addMinutes(ExactApprovals::ttlMinutes());
            $requested = $approval->expires_at === null ? null : CarbonImmutable::parse((string) $approval->expires_at, 'UTC');
            $expiresAt = $requested !== null && $requested->lessThan($limit) ? $requested : $limit;
        }

        DB::table('approvals')->where('clinic_id', $context->clinicId)->where('id', $approvalId)->update([
            'status' => $decision,
            'decided_by' => $context->actorId,
            'decided_at' => $nowDb,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s.u') ?? $approval->expires_at,
            'updated_at' => $nowDb,
        ]);

        $changes = [new ChangeRecord('approvals', $approvalId, 2, ChangeVisibility::Manager, ChangeKind::Updated)];
        $visitChange = $this->visits->refresh($context, $visit);
        if ($visitChange !== null) {
            $changes[] = $visitChange;
        }

        $expiresText = $expiresAt?->format('Y-m-d\TH:i:s.v\Z');

        return new CommandOutcome(
            result: ['approval_id' => $approvalId, 'status' => $decision, 'expires_at' => $expiresText],
            resultType: 'approvals',
            resultId: $approvalId,
            audit: [new AuditEntry('approval.decided', 'approvals', $approvalId, $reason, [
                'decision' => $decision,
                'kind' => $kind->value,
                'action_code' => (string) $approval->action_code,
                'target_type' => (string) $approval->target_type,
                'target_id' => (string) $approval->target_id,
                'payload_hash' => (string) $approval->payload_hash,
                'requested_by' => (string) $approval->requested_by,
                'expires_at' => $expiresText,
            ])],
            changes: $changes,
        );
    }
}
