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
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Access\Approvals\ApprovalTargets;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Demande d'une approbation EXACTE (B06, AT06, AT07, AT13, AT14 ; fichier 03 : « Approbation : demandeur, opération
 * cible, contenu exact ou empreinte, motif, montant, décision gérante, date et expiration »).
 *
 * La demande porte le CONTENU exact de l'opération (`subject`) : le serveur en dérive le code d'action, la cible et
 * l'empreinte (`payload_hash`), jamais fournis par le client. La cible est vérifiée dans le cabinet (elle existe et
 * l'opération y est encore possible : `ApprovalTargets`). Le motif est obligatoire ; l'échéance proposée est facultative
 * et ne peut qu'être raccourcie par la décision (durée maximale `eeo.approvals.ttl_minutes` après l'accord).
 *
 * Une même opération ne peut avoir qu'une demande en attente ou une approbation valable à la fois (409
 * `STATE_TRANSITION_REFUSED`, `details.current_status`) : une demande en double n'encombre pas la liste de la gérante.
 * Le demandeur est le compte connecté. Aucune donnée clinique : identifiants et montants seulement.
 *
 * Premier jalon EN LIGNE : une demande depuis un poste hors-ligne est refusée (les approbations distantes attendent la
 * connexion, fichier 19). Verrous : cabinet, idempotence, puis ceux de la cible (dossier patient, visite, facture).
 */
final class RequestApprovalHandler implements CommandHandler
{
    public const COMMAND = 'RequestApproval';

    public function __construct(private readonly ApprovalTargets $targets) {}

    public function permissions(): array
    {
        return ['approval.request'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Une demande d\'approbation depuis un poste hors-ligne n\'est pas encore ouverte : elle attend la connexion.');
        }
        if ($context->actorId === null) {
            throw new LogicException('Une demande d\'approbation a toujours un demandeur identifié.');
        }

        $payload = $command->payload;
        $approvalId = (string) $payload['approval_id'];
        $errors = [];

        try {
            $subject = ApprovalSubject::fromRequest(is_array($payload['subject'] ?? null) ? $payload['subject'] : []);
        } catch (RuleViolation $violation) {
            throw BusinessError::validation([$violation->field => [$violation->getMessage()]]);
        }

        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            $errors['reason'] = [$violation->getMessage()];
        }
        if ($reason === null && ! isset($errors['reason'])) {
            $errors['reason'] = ['Le motif de la demande est obligatoire.'];
        }

        $expiresAt = null;
        try {
            $expiresAt = Instant::parse(is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null, 'expires_at');
        } catch (RuleViolation $violation) {
            $errors['expires_at'] = [$violation->getMessage()];
        }
        $nowMs = (int) $context->now->format('Uv');
        if ($expiresAt !== null && $expiresAt->epochMilliseconds <= $nowMs) {
            $errors['expires_at'] = ['L\'échéance d\'une demande est dans le futur.'];
        }

        // Clé primaire GLOBALE : un identifiant déjà pris (ici ou dans un autre cabinet) est refusé sans distinction.
        if (DB::table('approvals')->where('id', $approvalId)->exists()) {
            $errors['approval_id'] = ['Cet identifiant d\'approbation est déjà utilisé.'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $this->targets->lockAndCheck($locks, $context, $subject);

        $existing = ExactApprovals::findPending($context->clinicId, $subject, $context->now)
            ?? ExactApprovals::findValid($context->clinicId, $subject, $context->now);
        if ($existing !== null) {
            throw BusinessError::stateRefused(
                'Une demande identique est déjà en attente ou accordée pour cette opération : suivez-la plutôt que d\'en créer une autre.',
                ExactApprovals::effectiveStatus($existing, $context->now),
            );
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        $expires = $expiresAt === null ? null : CarbonImmutable::createFromTimestampMsUTC($expiresAt->epochMilliseconds)->format('Y-m-d H:i:s.u');
        DB::table('approvals')->insert([
            'id' => $approvalId,
            'clinic_id' => $context->clinicId,
            'action_code' => $subject->actionCode(),
            'target_type' => $subject->targetType(),
            'target_id' => $subject->targetId,
            'payload_hash' => $subject->hash(),
            'requested_by' => $context->actorId,
            'reason' => (string) $reason,
            'status' => ExactApprovals::REQUESTED,
            'decided_by' => null,
            'decided_at' => null,
            'expires_at' => $expires,
            'consumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: ['approval_id' => $approvalId, 'status' => ExactApprovals::REQUESTED, 'payload_hash' => $subject->hash()],
            resultType: 'approvals',
            resultId: $approvalId,
            // Le contenu exact est conservé dans l'audit de la demande (aucune colonne ne le porte) : l'écran de la
            // gérante le relit et le revérifie contre l'empreinte avant de l'afficher.
            audit: [new AuditEntry('approval.requested', 'approvals', $approvalId, $reason, [
                'kind' => $subject->kind->value,
                'action_code' => $subject->actionCode(),
                'target_type' => $subject->targetType(),
                'target_id' => $subject->targetId,
                'payload_hash' => $subject->hash(),
                'subject' => $subject->toArray(),
                'expires_at' => $expiresAt?->toString(),
            ])],
            changes: [new ChangeRecord('approvals', $approvalId, 1, ChangeVisibility::Manager, ChangeKind::Created)],
        );
    }
}
