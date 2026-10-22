<?php

namespace App\Domain\Clinical\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Effects\OutboxEvent;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\ClinicalStepStore;
use App\Domain\Clinical\ConsultationReader;
use App\Domain\Clinical\RevisionHash;
use App\Domain\Clinical\RevisionSnapshot;
use App\Domain\Clinical\Rules\ClinicalInput;
use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Patients\Rules\VisitStatus;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/**
 * Finalisation d'une consultation (EE003, EE013, EE017 ; AT19, AT21) : fige la révision 1 (snapshot complet) dans la
 * MÊME transaction que le passage à « terminé », puis clôt la visite.
 *
 * Règles :
 *  - la consultation est « en cours » (au moins une rubrique enregistrée) ; brouillon ou déjà terminée : 409
 *    `STATE_TRANSITION_REFUSED` ; version attendue = `consultations.version` (409 `VERSION_CONFLICT`) ;
 *  - fichier 02 : « exiger motif ou plaintes, conclusion ou justification d'absence, et résolution des erreurs de
 *    validation ; ne pas exiger des mesures non réalisées ». Hypothèse de lecture : si le motif/les plaintes OU la
 *    conclusion manquent, la justification d'absence (`finalization_missing_data_reason`) est obligatoire ; les
 *    rubriques mesurées vides ne sont JAMAIS exigées ni transformées en « aucun » ;
 *  - l'état enregistré des sept rubriques est revalidé par les règles de l'enregistrement (aucune erreur de
 *    validation ne subsiste) ;
 *  - `snapshot_sha256` est l'empreinte SHA-256 de JCS(steps) que le poste a lue ; le serveur la RECALCULE sur les
 *    tables vivantes sous verrou, sans jamais s'y fier : un écart est un 422. L'empreinte de la révision
 *    (`clinical_revisions.sha256`) est celle de JCS(snapshot), calculée par le serveur seul (hypothèse « G8 ») ;
 *  - la révision est immuable (triggers de la base) : aucune modification, aucune suppression ;
 *  - la visite passe de « en consultation » à « terminée » (fichier 02 : la file distingue terminé), avec sa date de
 *    clôture ; AUCUN contrôle de paiement de l'examen n'est écrit (B04 et B06 n'existent pas).
 *
 * Verrous : cabinet → idempotence → visite (rang 46) → consultation (rang 47). Effets : audit `consultation.completed`
 * (révision, empreinte : jamais de contenu clinique), événement `ConsultationCompleted` (charge minimale), journal des
 * changements (consultation, visite, révision).
 */
final class CompleteConsultationHandler implements CommandHandler
{
    public const COMMAND = 'CompleteConsultation';

    /** Motif conservé avec la révision 1 (le champ est obligatoire en base ; le motif des corrections est saisi). */
    public const REVISION_REASON = 'Finalisation de la consultation';

    public function __construct(
        private readonly ConsultationReader $reader,
        private readonly ClinicalStepStore $steps,
    ) {}

    public function permissions(): array
    {
        return ['clinical.complete'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La finalisation d\'une consultation depuis un poste hors-ligne n\'est pas encore ouverte.');
        }

        $payload = $command->payload;
        $consultationId = (string) $payload['consultation_id'];
        $revisionId = (string) $payload['revision_id'];

        // Ordre des verrous : la visite (46) avant la consultation (47). Son identifiant ne change jamais.
        $visitId = DB::table('consultations')->where('clinic_id', $context->clinicId)->where('id', $consultationId)->value('visit_id');
        if ($visitId === null) {
            throw BusinessError::notFound();
        }
        $visit = $locks->visit((string) $visitId);
        $consultation = $locks->consultation($consultationId);
        if ($consultation === null || $visit === null) {
            throw BusinessError::notFound();
        }

        if ($consultation->status === ClinicalSteps::COMPLETED) {
            throw BusinessError::stateRefused('Cette consultation est déjà terminée : sa première révision est figée.', ClinicalSteps::COMPLETED);
        }
        if ($consultation->status === ClinicalSteps::DRAFT) {
            throw BusinessError::stateRefused('Enregistrez au moins une rubrique avant de terminer l\'examen.', ClinicalSteps::DRAFT);
        }
        if ((int) $consultation->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'Une version plus récente existe : actualisez avant de terminer.', 409, [], null, [], ['current_version' => (int) $consultation->version]);
        }
        if ($visit->status !== VisitStatus::IN_CONSULTATION) {
            throw BusinessError::stateRefused('La visite n\'est pas en consultation : la consultation ne peut pas être terminée.', (string) $visit->status);
        }

        $errors = new FieldErrors;
        if ((int) $consultation->current_revision !== 0) {
            $errors->add('revision_no', 'Une consultation non terminée ne porte aucune révision.');
        }
        if (DB::table('clinical_revisions')->where('id', $revisionId)->exists()) {
            $errors->add('revision_id', 'Cet identifiant de révision est déjà utilisé.');
        }

        $steps = $this->reader->steps($consultation);
        $this->revalidateStoredState($consultation, $steps, $errors);

        $missing = ClinicalInput::text($payload['finalization_missing_data_reason'] ?? null, 'finalization_missing_data_reason', $errors);
        $absent = [];
        if ($steps['ANAMNESIS']['complaints'] === [] && $steps['ANAMNESIS']['symptoms'] === null) {
            $absent[] = 'le motif ou les plaintes';
        }
        if ($steps['CONCLUSION']['conclusion'] === null) {
            $absent[] = 'la conclusion';
        }
        if ($absent !== [] && $missing === null && ! array_key_exists('finalization_missing_data_reason', $errors->all())) {
            $errors->add('finalization_missing_data_reason', 'Il manque '.implode(' et ', $absent).' : renseignez-les ou justifiez leur absence avant de terminer.');
        }

        try {
            $stepsHash = RevisionSnapshot::stepsHash($steps);
        } catch (InvalidArgumentException) {
            throw BusinessError::validation(['snapshot_sha256' => ['Le contenu de la consultation ne peut pas être figé.']]);
        }
        if (! hash_equals($stepsHash, (string) $payload['snapshot_sha256'])) {
            $errors->add('snapshot_sha256', 'Le contenu enregistré n\'est plus celui que vous avez relu : rechargez la consultation avant de la terminer.');
        }
        if (! $errors->isEmpty()) {
            throw BusinessError::validation($errors->all());
        }

        $nowStored = $context->now->utc()->format('Y-m-d H:i:s.u');
        $version = (int) $consultation->version + 1;
        $snapshot = RevisionSnapshot::build(
            $consultation,
            $steps,
            $this->reader->patient($consultation),
            1,
            $context->now,
            (string) $context->actorId,
            $context->actorName,
            $missing,
            null,
            null,
        );
        $sha256 = RevisionHash::hash($snapshot);

        DB::table('clinical_revisions')->insert([
            'id' => $revisionId,
            'clinic_id' => $context->clinicId,
            'consultation_id' => $consultationId,
            'revision_no' => 1,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'reason' => self::REVISION_REASON,
            'author_id' => (string) $context->actorId,
            'recorded_at' => $nowStored,
            'previous_revision_id' => null,
            'sha256' => $sha256,
            'created_at' => $nowStored,
            'updated_at' => $nowStored,
        ]);
        DB::table('consultations')
            ->where('clinic_id', $context->clinicId)
            ->where('id', $consultationId)
            ->update([
                'status' => ClinicalSteps::COMPLETED,
                'completed_at' => $nowStored,
                'current_revision' => 1,
                'finalization_missing_data_reason' => $missing,
                'version' => $version,
                'updated_at' => $nowStored,
            ]);
        $visitVersion = (int) $visit->version + 1;
        DB::table('visits')
            ->where('clinic_id', $context->clinicId)
            ->where('id', (string) $visitId)
            ->update(['status' => VisitStatus::COMPLETED, 'closed_at' => $nowStored, 'version' => $visitVersion, 'updated_at' => $nowStored]);

        return new CommandOutcome(
            result: ['consultation_id' => $consultationId, 'revision_id' => $revisionId, 'revision_no' => 1, 'version' => $version, 'status' => ClinicalSteps::COMPLETED],
            resultType: 'consultations',
            resultId: $consultationId,
            audit: [new AuditEntry('consultation.completed', 'consultations', $consultationId, null, [
                'revision_no' => 1,
                'revision_id' => $revisionId,
                'sha256' => $sha256,
            ])],
            events: [new OutboxEvent('ConsultationCompleted', 1, 'consultations', $consultationId, $version, [
                'consultation_id' => $consultationId,
                'patient_id' => (string) $consultation->patient_id,
                'visit_id' => (string) $visitId,
                'revision_id' => $revisionId,
                'revision_no' => 1,
                'completed_at' => RevisionSnapshot::instant($context->now),
            ])],
            changes: [
                new ChangeRecord('visits', (string) $visitId, $visitVersion, ChangeVisibility::Administrative, ChangeKind::Updated),
                new ChangeRecord('consultations', $consultationId, $version, ChangeVisibility::Clinical, ChangeKind::Updated),
                new ChangeRecord('clinical_revisions', $revisionId, 1, ChangeVisibility::Clinical, ChangeKind::Created),
            ],
        );
    }

    /**
     * « Résolution des erreurs de validation » : l'état enregistré de chaque rubrique repasse par les règles de
     * l'enregistrement. Les erreurs sont rendues sous `steps.<RUBRIQUE>.…`.
     *
     * @param  array<string, array<string, mixed>>  $steps
     */
    private function revalidateStoredState(\stdClass $consultation, array $steps, FieldErrors $errors): void
    {
        foreach (ClinicalSteps::ALL as $step) {
            $local = new FieldErrors;
            $this->steps->normalize($consultation, $step, $steps[$step], $local);
            foreach ($local->all() as $key => $messages) {
                foreach ($messages as $message) {
                    $errors->add('steps.'.$step.substr($key, strlen('step.data')), $message);
                }
            }
        }
    }
}
