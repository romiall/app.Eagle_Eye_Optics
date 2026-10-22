<?php

namespace App\Domain\Clinical\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\ClinicalStepStore;
use App\Domain\Clinical\ConsultationReader;
use App\Domain\Clinical\RevisionHash;
use App\Domain\Clinical\RevisionSnapshot;
use App\Domain\Clinical\Rules\ClinicalInput;
use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Correction motivée d'une consultation TERMINÉE (EE003, EE013, EE017 ; AT19, AT21, AT42) : une nouvelle révision
 * immuable (N ≥ 2) avec son motif et son auteur ; les révisions antérieures ne sont jamais modifiées ni supprimées
 * (triggers de la base) ; aucune stratégie « dernier écrit gagnant ».
 *
 * Règles :
 *  - la consultation est TERMINÉE (sinon 409 `STATE_TRANSITION_REFUSED` : une consultation en cours se modifie
 *    directement) ; version attendue = `consultations.version` (409 `VERSION_CONFLICT`) ;
 *  - `revision_no` = révision courante + 1 et `previous_revision_id` = la révision courante (422 sinon) ;
 *  - le motif est obligatoire (3 à 1000 caractères) ; il est conservé avec la révision (lisible avec `clinical.read`),
 *    JAMAIS recopié dans l'audit, car il peut contenir une donnée clinique ;
 *  - chaque rubrique envoyée REMPLACE la rubrique actuelle (mêmes règles que l'enregistrement, mêmes erreurs par
 *    champ sous `steps.<n>.data.…`), une rubrique au plus par nom ; une correction qui ne change rien est refusée (422) ;
 *  - `snapshot_sha256` = SHA-256 de JCS(steps) de la requête, recalculé par le serveur (intégrité de ce qui est
 *    reçu) ; l'empreinte de la révision (`clinical_revisions.sha256`) est celle de JCS(snapshot) construit après
 *    écriture, par le serveur seul ;
 *  - la visite n'est pas touchée (déjà terminée) ; aucun contrôle de paiement.
 *
 * Verrous : cabinet → idempotence → consultation (rang 47). Audit `consultation.amended` : numéro de révision,
 * identifiants, noms des rubriques corrigées, empreinte (jamais de contenu ni de motif).
 */
final class AmendConsultationHandler implements CommandHandler
{
    public const COMMAND = 'AmendConsultation';

    public function __construct(
        private readonly ConsultationReader $reader,
        private readonly ClinicalStepStore $steps,
    ) {}

    public function permissions(): array
    {
        return ['clinical.amend'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La correction d\'une consultation depuis un poste hors-ligne n\'est pas encore ouverte.');
        }

        $payload = $command->payload;
        $consultationId = (string) $payload['consultation_id'];
        $revisionId = (string) $payload['revision_id'];

        $consultation = $locks->consultation($consultationId);
        if ($consultation === null) {
            throw BusinessError::notFound();
        }
        if ($consultation->status !== ClinicalSteps::COMPLETED) {
            throw BusinessError::stateRefused('Seule une consultation terminée se corrige par une révision : enregistrez directement les rubriques d\'une consultation en cours.', (string) $consultation->status);
        }
        if ((int) $consultation->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'Une version plus récente existe : actualisez avant de corriger.', 409, [], null, [], ['current_version' => (int) $consultation->version]);
        }

        $errors = new FieldErrors;
        $current = (int) $consultation->current_revision;
        if ((int) $payload['revision_no'] !== $current + 1) {
            $errors->add('revision_no', 'Le numéro de révision attendu est '.($current + 1).'.');
        }
        $previousId = DB::table('clinical_revisions')
            ->where('clinic_id', $context->clinicId)
            ->where('consultation_id', $consultationId)
            ->where('revision_no', $current)
            ->value('id');
        if ($previousId === null || (string) $previousId !== (string) $payload['previous_revision_id']) {
            $errors->add('previous_revision_id', 'La révision précédente n\'est pas la dernière révision de cette consultation.');
        }
        if (DB::table('clinical_revisions')->where('id', $revisionId)->exists()) {
            $errors->add('revision_id', 'Cet identifiant de révision est déjà utilisé.');
        }

        $reasonErrors = new FieldErrors;
        $reason = ClinicalInput::text($payload['reason'] ?? null, 'reason', $reasonErrors, 1000);
        foreach ($reasonErrors->all() as $key => $messages) {
            foreach ($messages as $message) {
                $errors->add($key, $message);
            }
        }
        if ($reason === null || mb_strlen($reason, 'UTF-8') < 3) {
            if ($reasonErrors->isEmpty()) {
                $errors->add('reason', 'Le motif de la correction est obligatoire (3 caractères au moins).');
            }
        }

        try {
            $receivedHash = RevisionHash::hash($payload['steps']);
        } catch (InvalidArgumentException) {
            throw BusinessError::validation(['steps' => ['Une valeur numérique à virgule flottante n\'est pas admise : les mesures sont des chaînes décimales exactes.']]);
        }
        if (! hash_equals($receivedHash, (string) $payload['snapshot_sha256'])) {
            $errors->add('snapshot_sha256', 'L\'empreinte ne correspond pas aux rubriques reçues : renvoyez la correction.');
        }

        /** @var array<string, array<string, mixed>> $normalizedByStep */
        $normalizedByStep = [];
        $changed = [];
        foreach ($payload['steps'] as $index => $submitted) {
            $step = (string) $submitted['step'];
            $base = 'steps.'.$index.'.data';
            if (isset($normalizedByStep[$step])) {
                $errors->add('steps.'.$index.'.step', 'Cette rubrique figure déjà dans la correction : une seule fois par rubrique.');

                continue;
            }
            $local = new FieldErrors;
            $normalized = $this->steps->normalize($consultation, $step, $submitted['data'], $local);
            $this->steps->rejectForeignIds($consultation, $step, $submitted['data'], $local);
            foreach ($local->all() as $key => $messages) {
                foreach ($messages as $message) {
                    $errors->add($base.substr($key, strlen('step.data')), $message);
                }
            }
            $normalizedByStep[$step] = $normalized;
            if ($local->isEmpty() && ! $this->steps->isUnchanged($consultation, $step, $normalized)) {
                $changed[] = $step;
            }
        }
        if ($errors->isEmpty() && $changed === []) {
            $errors->add('steps', 'Aucune rubrique ne change : une correction modifie au moins une rubrique.');
        }
        if (! $errors->isEmpty()) {
            throw BusinessError::validation($errors->all());
        }

        $nowStored = $context->now->utc()->format('Y-m-d H:i:s.u');
        $columns = [];
        foreach ($changed as $step) {
            $columns += $this->steps->write($consultation, $step, $normalizedByStep[$step], $nowStored);
        }
        $version = (int) $consultation->version + 1;
        $revisionNo = $current + 1;
        DB::table('consultations')
            ->where('clinic_id', $context->clinicId)
            ->where('id', $consultationId)
            ->update($columns + ['current_revision' => $revisionNo, 'version' => $version, 'updated_at' => $nowStored]);

        // Le snapshot est construit APRÈS les écritures, dans la même transaction : il décrit l'état résultant. La ligne
        // de la consultation est relue (les colonnes narratives viennent d'être modifiées).
        $reasonText = (string) $reason;
        $after = $this->reader->find($context->clinicId, $consultationId) ?? $consultation;
        $snapshot = RevisionSnapshot::build(
            $after,
            $this->reader->steps($after),
            $this->reader->patient($after),
            $revisionNo,
            $context->now,
            (string) $context->actorId,
            $context->actorName,
            $consultation->finalization_missing_data_reason === null ? null : (string) $consultation->finalization_missing_data_reason,
            (string) $previousId,
            $reasonText,
        );
        $sha256 = RevisionHash::hash($snapshot);
        DB::table('clinical_revisions')->insert([
            'id' => $revisionId,
            'clinic_id' => $context->clinicId,
            'consultation_id' => $consultationId,
            'revision_no' => $revisionNo,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'reason' => $reasonText,
            'author_id' => (string) $context->actorId,
            'recorded_at' => $nowStored,
            'previous_revision_id' => (string) $previousId,
            'sha256' => $sha256,
            'created_at' => $nowStored,
            'updated_at' => $nowStored,
        ]);

        return new CommandOutcome(
            result: ['consultation_id' => $consultationId, 'revision_id' => $revisionId, 'revision_no' => $revisionNo, 'version' => $version, 'status' => ClinicalSteps::COMPLETED],
            resultType: 'consultations',
            resultId: $consultationId,
            audit: [new AuditEntry('consultation.amended', 'consultations', $consultationId, null, [
                'revision_no' => $revisionNo,
                'revision_id' => $revisionId,
                'previous_revision_id' => (string) $previousId,
                'steps' => $changed,
                'sha256' => $sha256,
            ])],
            changes: [
                new ChangeRecord('consultations', $consultationId, $version, ChangeVisibility::Clinical, ChangeKind::Updated),
                new ChangeRecord('clinical_revisions', $revisionId, $revisionNo, ChangeVisibility::Clinical, ChangeKind::Created),
            ],
        );
    }
}
