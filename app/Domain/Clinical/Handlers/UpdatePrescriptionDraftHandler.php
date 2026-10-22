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
use App\Domain\Clinical\RevisionSnapshot;
use App\Domain\Clinical\StepRows;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\OpticalPrescriptionRules;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Modification d'une prescription optique encore BROUILLON (route `PATCH /prescriptions/{id}`, EN LIGNE seulement : hors
 * ligne, une correction est une nouvelle version, `CreatePrescription` avec `supersedes_id`).
 *
 * - Jeton de concurrence : `expected_updated_at` (la table n'a pas de colonne `version`, que le contrat 23 interdit
 *   d'inventer) ; un écart est un 409 `VERSION_CONFLICT` avec `details.current_updated_at`, jamais un « dernier écrit
 *   gagne ». Le jeton a la précision de la milliseconde du contrat ; toutes les mutations d'un cabinet étant
 *   sérialisées par son verrou, deux modifications ne se confondent que si elles tombent dans la même milliseconde.
 * - Une prescription VALIDÉE est immuable : 409 `STATE_TRANSITION_REFUSED` (les triggers de la base la protègent aussi).
 * - `changes` ne contient que des champs de contenu (`OpticalPrescriptionRules::EDITABLE`) ; l'origine, le patient, la
 *   consultation, la série et la version ne changent jamais. Les yeux et les traitements, quand ils figurent dans
 *   `changes`, REMPLACENT les actuels. Le contenu résultant est revalidé en entier ; un résultat identique à l'état
 *   enregistré n'écrit rien.
 * - Audit `prescription.updated` : noms des champs modifiés, jamais leurs valeurs.
 */
final class UpdatePrescriptionDraftHandler implements CommandHandler
{
    public const COMMAND = 'UpdatePrescriptionDraft';

    public function __construct(private readonly OpticalPrescriptions $prescriptions) {}

    public function permissions(): array
    {
        return ['clinical.write'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La modification d\'un brouillon depuis un poste hors-ligne n\'est pas ouverte : créez une nouvelle version.');
        }

        $payload = $command->payload;
        $prescriptionId = (string) $payload['prescription_id'];
        $clinicId = $context->clinicId;
        $timezone = (string) $locks->clinic()->timezone;

        $shape = new FieldErrors;
        $expected = $payload['expected_updated_at'] ?? null;
        if (! is_string($expected) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z\z/', $expected) !== 1) {
            $shape->add('expected_updated_at', 'Instant UTC à la milliseconde attendu (jeton de concurrence lu avec la prescription).');
        }
        $changes = $payload['changes'] ?? null;
        if (! is_array($changes) || $changes === [] || array_is_list($changes)) {
            $shape->add('changes', 'Au moins un champ à modifier est attendu.');
        } else {
            foreach (array_keys($changes) as $field) {
                if (! in_array($field, OpticalPrescriptionRules::EDITABLE, true)) {
                    $shape->add('changes.'.$field, 'Ce champ ne se modifie pas : l\'origine, le patient, la consultation, la série et la version sont fixés à la création.');
                }
            }
        }
        if (! $shape->isEmpty()) {
            throw BusinessError::validation($shape->all());
        }

        $row = $this->prescriptions->find($clinicId, $prescriptionId);
        if ($row === null) {
            throw BusinessError::notFound();
        }
        if ($row->status !== 'DRAFT') {
            throw BusinessError::stateRefused('Cette prescription est validée : elle ne se modifie plus. Créez-en une nouvelle version.', (string) $row->status);
        }
        $token = RevisionSnapshot::instant(CarbonImmutable::parse((string) $row->updated_at, 'UTC'));
        if ($expected !== $token) {
            throw new BusinessError('VERSION_CONFLICT', 'Cette prescription a été modifiée entre-temps : actualisez avant de la modifier.', 409, [], null, [], ['current_updated_at' => $token]);
        }

        /** @var array<string, mixed> $current */
        $current = $this->prescriptions->view($clinicId, $prescriptionId) ?? [];
        $content = [];
        foreach (OpticalPrescriptionRules::EDITABLE as $field) {
            $content[$field] = $current[$field] ?? null;
        }
        /** @var array<string, mixed> $changes */
        $merged = array_merge($content, $changes);

        $local = new FieldErrors;
        $today = Instant::fromEpochMilliseconds((int) $context->now->format('Uv'))->civilDate($timezone)->toString();
        $normalized = OpticalPrescriptionRules::normalize($merged, (string) $row->origin, $this->prescriptions->codes(), $this->prescriptions->stored($current), $today, $local);
        $own = $this->prescriptions->childIds($clinicId, $prescriptionId);
        if (array_key_exists('eyes', $changes)) {
            StepRows::flagTaken('optical_prescription_eyes', StepRows::idsAt($merged['eyes'] ?? null), $own['eyes'], 'eyes', $local);
        }
        if (array_key_exists('treatments', $changes)) {
            StepRows::flagTaken('optical_prescription_treatments', StepRows::idsAt($merged['treatments'] ?? null), $own['treatments'], 'treatments', $local);
        }
        $fileId = $normalized['external_file_id'] ?? null;
        if (is_string($fileId) && $fileId !== ($current['external_file_id'] ?? null) && ! DB::table('stored_files')->where('clinic_id', $clinicId)->where('id', $fileId)->exists()) {
            $local->add('external_file_id', 'Fichier introuvable dans ce cabinet.');
        }
        if (! $local->isEmpty()) {
            $errors = [];
            foreach ($local->all() as $key => $messages) {
                $errors['changes.'.$key] = $messages;
            }
            throw BusinessError::validation($errors);
        }

        // Enregistrement sans effet : le contenu résultant est exactement le contenu enregistré.
        $before = [];
        foreach (OpticalPrescriptionRules::EDITABLE as $field) {
            $before[$field] = $current[$field] ?? null;
        }
        $after = [];
        foreach (OpticalPrescriptionRules::EDITABLE as $field) {
            $after[$field] = $normalized[$field] ?? null;
        }
        if (CanonicalJson::hash(StepRows::canonical($before)) === CanonicalJson::hash(StepRows::canonical($after))) {
            return new CommandOutcome(
                result: ['prescription_id' => $prescriptionId, 'updated_at' => $token],
                resultType: 'optical_prescriptions',
                resultId: $prescriptionId,
            );
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('id', $prescriptionId)->update([
            'external_file_id' => $normalized['external_file_id'],
            'external_prescriber' => $normalized['external_prescriber'],
            'external_prescribed_on' => $normalized['external_prescribed_on'],
            'usage_code' => $normalized['usage_code'],
            'usage_other' => $normalized['usage_other'],
            'lens_type_code' => $normalized['lens_type_code'],
            'lens_type_other' => $normalized['lens_type_other'],
            'lens_index' => $normalized['lens_index'],
            'lens_index_other' => $normalized['lens_index_other'],
            'pd_total' => $normalized['pd_total'],
            'pd_od' => $normalized['pd_od'],
            'pd_og' => $normalized['pd_og'],
            'pd_inconsistency_reason' => $normalized['pd_inconsistency_reason'],
            'notes' => $normalized['notes'],
            'updated_at' => $now,
        ]);
        $replaceEyes = array_key_exists('eyes', $changes);
        $replaceTreatments = array_key_exists('treatments', $changes);
        $this->prescriptions->deleteChildren($clinicId, $prescriptionId, $replaceEyes, $replaceTreatments);
        if ($replaceEyes) {
            $this->prescriptions->insertEyes($clinicId, $prescriptionId, $normalized['eyes'], $now);
        }
        if ($replaceTreatments) {
            $this->prescriptions->insertTreatments($clinicId, $prescriptionId, $normalized['treatments'], $now);
        }

        $updatedAt = RevisionSnapshot::instant($context->now);

        return new CommandOutcome(
            result: ['prescription_id' => $prescriptionId, 'updated_at' => $updatedAt],
            resultType: 'optical_prescriptions',
            resultId: $prescriptionId,
            audit: [new AuditEntry('prescription.updated', 'optical_prescriptions', $prescriptionId, null, [
                'version_no' => (int) $row->version_no,
                'fields' => array_values(array_keys($changes)),
            ])],
            changes: [new ChangeRecord('optical_prescriptions', $prescriptionId, (int) $row->version_no, ChangeVisibility::Clinical, ChangeKind::Updated)],
        );
    }
}
