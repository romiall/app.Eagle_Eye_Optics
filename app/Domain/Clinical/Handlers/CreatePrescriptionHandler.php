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
use App\Domain\Clinical\OpticalPrescriptions;
use App\Domain\Clinical\StepRows;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\OpticalPrescriptionRules;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\ValueObjects\Instant;
use Illuminate\Support\Facades\DB;

/**
 * Création d'une prescription optique BROUILLON (EE009, EE014 ; AT20, AT21) : prescription interne d'une
 * consultation, transcription d'une ordonnance EXTERNE identifiée (A13, aucun examen fictif) ou nouvelle version d'une
 * prescription validée (`supersedes_id`).
 *
 * Règles :
 *  - jamais produite automatiquement : l'écran envoie les valeurs que le professionnel a choisies (copie explicite
 *    de la réfraction puis édition indépendante) ; le serveur ne copie ni ne déduit rien ;
 *  - interne : la consultation existe dans le cabinet et appartient au patient indiqué ; une consultation n'a qu'UNE
 *    série de prescriptions (unicité consultation / numéro de version) : une seconde première version est un 409
 *    `STATE_TRANSITION_REFUSED`, la suite passe par une nouvelle version ;
 *  - externe : prescripteur et date d'origine obligatoires, aucune consultation ;
 *  - nouvelle version : la précédente est VALIDÉE et la plus récente de sa série, même série, même patient, même
 *    origine, même consultation, numéro suivant ; une prescription brouillon se modifie directement ;
 *  - contenu : `OpticalPrescriptionRules::normalize` (brouillon incomplet admis) ; toutes les erreurs sont rendues
 *    ensemble, avant la première écriture ; un identifiant déjà pris ailleurs est un 422, jamais une erreur SQL.
 *
 * Verrous : cabinet → idempotence → patient (rang 45) → consultation (rang 47). Le cabinet est verrouillé en
 * exclusif pendant toute la transaction, ce qui sérialise aussi les versions d'une série.
 *
 * Audit `prescription.created` : origine, série, version, consultation (jamais une valeur clinique).
 */
final class CreatePrescriptionHandler implements CommandHandler
{
    public const COMMAND = 'CreatePrescription';

    public function __construct(private readonly OpticalPrescriptions $prescriptions) {}

    public function permissions(): array
    {
        return ['clinical.write'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La création d\'une prescription depuis un poste hors-ligne n\'est pas encore ouverte.');
        }

        $p = $command->payload;
        $prescriptionId = (string) $p['prescription_id'];
        $seriesId = (string) $p['series_id'];
        $versionNo = (int) $p['version_no'];
        $origin = (string) $p['origin'];
        $patientId = (string) $p['patient_id'];
        $consultationId = $p['consultation_id'] === null ? null : (string) $p['consultation_id'];
        $supersedesId = $p['supersedes_id'] === null ? null : (string) $p['supersedes_id'];

        $timezone = (string) $locks->clinic()->timezone;
        $patient = $locks->patient($patientId);
        $consultation = $consultationId === null ? null : $locks->consultation($consultationId);
        $clinicId = $context->clinicId;

        // --- États qui ne sont pas des erreurs de saisie (404 / 409) --------------------------------------------
        $previous = null;
        if ($supersedesId !== null) {
            $previous = DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('id', $supersedesId)->first();
            if ($previous === null) {
                throw BusinessError::notFound('Prescription à corriger introuvable dans ce cabinet.');
            }
            if ($previous->status !== 'VALIDATED') {
                throw BusinessError::stateRefused('Cette prescription est encore un brouillon : modifiez-la directement plutôt que d\'en créer une version.', (string) $previous->status);
            }
            $latest = (int) DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('series_id', $previous->series_id)->max('version_no');
            if ($latest !== (int) $previous->version_no) {
                throw BusinessError::stateRefused('Une version plus récente de cette prescription existe déjà : partez de la dernière version.', (string) $previous->status);
            }
        } elseif ($origin === 'INTERNAL') {
            if ($consultationId !== null && $consultation === null) {
                throw BusinessError::notFound('Consultation introuvable dans ce cabinet.');
            }
            $existing = $consultationId === null ? null : DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->orderByDesc('version_no')->first();
            if ($existing !== null) {
                throw BusinessError::stateRefused('Une prescription existe déjà pour cette consultation : créez-en une nouvelle version à partir de la dernière.', (string) $existing->status);
            }
        }

        // --- Erreurs de saisie, toutes ensemble ----------------------------------------------------------------
        $errors = new FieldErrors;
        if ($patient === null) {
            $errors->add('patient_id', 'Patient introuvable dans ce cabinet.');
        }
        if (DB::table('optical_prescriptions')->where('id', $prescriptionId)->exists()) {
            $errors->add('prescription_id', 'Cet identifiant de prescription est déjà utilisé.');
        }

        if ($previous === null) {
            if ($versionNo !== 1) {
                $errors->add('version_no', 'La première version d\'une prescription porte le numéro 1.');
            }
            if (DB::table('optical_prescriptions')->where('series_id', $seriesId)->exists()) {
                $errors->add('series_id', 'Cet identifiant de série est déjà utilisé.');
            }
        } else {
            if ($seriesId !== (string) $previous->series_id) {
                $errors->add('series_id', 'Une nouvelle version reste dans la série de la prescription corrigée.');
            }
            if ($versionNo !== (int) $previous->version_no + 1) {
                $errors->add('version_no', 'Le numéro de version attendu est '.((int) $previous->version_no + 1).'.');
            }
            if ($patientId !== (string) $previous->patient_id) {
                $errors->add('patient_id', 'Une nouvelle version concerne le même patient.');
            }
            if ($origin !== (string) $previous->origin) {
                $errors->add('origin', 'Une nouvelle version garde l\'origine de la prescription corrigée.');
            }
            if ($consultationId !== ($previous->consultation_id === null ? null : (string) $previous->consultation_id)) {
                $errors->add('consultation_id', 'Une nouvelle version reste rattachée à la même consultation.');
            }
        }

        if ($origin === 'INTERNAL') {
            if ($consultation !== null && $consultation->patient_id !== $patientId) {
                $errors->add('patient_id', 'Cette consultation concerne un autre patient.');
            }
        } elseif ($consultationId !== null) {
            $errors->add('consultation_id', 'Une ordonnance externe n\'est rattachée à aucune consultation du cabinet.');
        }

        $today = Instant::fromEpochMilliseconds((int) $context->now->format('Uv'))->civilDate($timezone)->toString();
        $normalized = OpticalPrescriptionRules::normalize(
            $p,
            $origin,
            $this->prescriptions->codes(),
            ['usage_code' => null, 'lens_type_code' => null, 'treatments' => []],
            $today,
            $errors,
        );
        $this->rejectForeignChildIds($p, $errors);
        $this->checkExternalFile($clinicId, $normalized['external_file_id'] ?? null, $errors);
        if (! $errors->isEmpty()) {
            throw BusinessError::validation($errors->all());
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('optical_prescriptions')->insert([
            'id' => $prescriptionId,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'series_id' => $seriesId,
            'origin' => $origin,
            'external_file_id' => $normalized['external_file_id'],
            'external_prescriber' => $normalized['external_prescriber'],
            'external_prescribed_on' => $normalized['external_prescribed_on'],
            'consultation_id' => $consultationId,
            'version_no' => $versionNo,
            'status' => 'DRAFT',
            'usage_group' => 'OPTICAL_USAGE',
            'usage_code' => $normalized['usage_code'],
            'usage_other' => $normalized['usage_other'],
            'lens_type_group' => 'LENS_TYPE',
            'lens_type_code' => $normalized['lens_type_code'],
            'lens_type_other' => $normalized['lens_type_other'],
            'lens_index' => $normalized['lens_index'],
            'lens_index_other' => $normalized['lens_index_other'],
            'pd_total' => $normalized['pd_total'],
            'pd_od' => $normalized['pd_od'],
            'pd_og' => $normalized['pd_og'],
            'pd_inconsistency_reason' => $normalized['pd_inconsistency_reason'],
            'notes' => $normalized['notes'],
            'validated_at' => null,
            'validated_by' => null,
            'supersedes_id' => $supersedesId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->prescriptions->insertEyes($clinicId, $prescriptionId, $normalized['eyes'], $now);
        $this->prescriptions->insertTreatments($clinicId, $prescriptionId, $normalized['treatments'], $now);

        return new CommandOutcome(
            result: ['prescription_id' => $prescriptionId, 'series_id' => $seriesId, 'version_no' => $versionNo, 'status' => 'DRAFT'],
            resultType: 'optical_prescriptions',
            resultId: $prescriptionId,
            audit: [new AuditEntry('prescription.created', 'optical_prescriptions', $prescriptionId, null, [
                'origin' => $origin,
                'series_id' => $seriesId,
                'version_no' => $versionNo,
                'consultation_id' => $consultationId,
            ])],
            changes: [new ChangeRecord('optical_prescriptions', $prescriptionId, $versionNo, ChangeVisibility::Clinical, ChangeKind::Created)],
        );
    }

    /**
     * Identifiants des yeux et des traitements : déjà pris ailleurs → 422 par champ (clés primaires globales).
     *
     * @param  array<string, mixed>  $payload
     */
    private function rejectForeignChildIds(array $payload, FieldErrors $errors): void
    {
        StepRows::flagTaken('optical_prescription_eyes', StepRows::idsAt($payload['eyes'] ?? null), [], 'eyes', $errors);
        StepRows::flagTaken('optical_prescription_treatments', StepRows::idsAt($payload['treatments'] ?? null), [], 'treatments', $errors);
    }

    /**
     * Le fichier de l'ordonnance externe (photo, scan) doit exister dans ce cabinet.
     */
    private function checkExternalFile(string $clinicId, mixed $fileId, FieldErrors $errors): void
    {
        if (is_string($fileId) && ! DB::table('stored_files')->where('clinic_id', $clinicId)->where('id', $fileId)->exists()) {
            $errors->add('external_file_id', 'Fichier introuvable dans ce cabinet.');
        }
    }
}
