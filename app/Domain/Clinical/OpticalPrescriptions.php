<?php

namespace App\Domain\Clinical;

use App\Domain\Shared\ValueObjects\FixedDecimal;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecture et écriture des prescriptions optiques (C04) : `optical_prescriptions` et ses enfants (`optical_prescription_eyes`,
 * `optical_prescription_treatments`). Toujours limitée au cabinet ; les nombres sont rendus en forme canonique (« 1.25 »,
 * « -0.50 », « 0.00 »), jamais arrondis.
 *
 * Une prescription VALIDÉE est immuable (triggers de la base : ni la ligne, ni ses enfants ne changent plus) ; ses
 * versions successives forment une SÉRIE (`series_id`, `version_no`, `supersedes_id`).
 */
final class OpticalPrescriptions
{
    public function __construct(private readonly ClinicalTerms $terms) {}

    /**
     * Codes ACTIFS des référentiels de la prescription.
     *
     * @return array{usage: list<string>, lens_type: list<string>, treatment: list<string>}
     */
    public function codes(): array
    {
        return [
            'usage' => $this->terms->activeCodes('OPTICAL_USAGE'),
            'lens_type' => $this->terms->activeCodes('LENS_TYPE'),
            'treatment' => $this->terms->activeCodes('LENS_TREATMENT'),
        ];
    }

    public function find(string $clinicId, string $prescriptionId): ?stdClass
    {
        $row = DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('id', $prescriptionId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Prescription complète au format de la ressource `Prescription` (instants encore au format de la base).
     *
     * @return array<string, mixed>|null
     */
    public function view(string $clinicId, string $prescriptionId): ?array
    {
        $row = $this->find($clinicId, $prescriptionId);

        return $row === null ? null : $this->shape($row);
    }

    /**
     * Toutes les versions des prescriptions d'une consultation, de la plus récente à la plus ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public function listForConsultation(string $clinicId, string $consultationId): array
    {
        $rows = DB::table('optical_prescriptions')
            ->where('clinic_id', $clinicId)
            ->where('consultation_id', $consultationId)
            ->orderByDesc('version_no')
            ->orderByDesc('id')
            ->get();

        return array_values($rows->map(fn (stdClass $row): array => $this->shape($row))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(stdClass $row): array
    {
        $eyes = DB::table('optical_prescription_eyes')
            ->where('clinic_id', $row->clinic_id)
            ->where('prescription_id', $row->id)
            ->orderBy('eye')
            ->get()
            ->map(static fn (stdClass $eye): array => [
                'id' => (string) $eye->id,
                'eye' => (string) $eye->eye,
                'no_correction' => (bool) $eye->no_correction,
                'sphere' => self::decimal($eye->sphere),
                'cylinder' => self::decimal($eye->cylinder),
                'axis' => $eye->axis === null ? null : (int) $eye->axis,
                'addition' => self::decimal($eye->addition),
                'fitting_height' => self::decimal($eye->fitting_height),
            ])
            ->all();

        $treatments = DB::table('optical_prescription_treatments as t')
            ->join('clinical_terms as ref', static function ($join): void {
                $join->on('ref.group_code', '=', 't.term_group')->on('ref.code', '=', 't.term_code');
            })
            ->where('t.clinic_id', $row->clinic_id)
            ->where('t.prescription_id', $row->id)
            ->orderBy('ref.sort_order')
            ->orderBy('t.id')
            ->get(['t.id', 't.term_code', 't.other_text'])
            ->map(static fn (stdClass $term): array => ['id' => (string) $term->id, 'term_code' => (string) $term->term_code, 'other_text' => $term->other_text])
            ->all();

        // Nom d'affichage du compte qui a validé, lu dans le même cabinet : l'ordonnance s'imprime alors depuis
        // tout compte autorisé, sans deviner un nom. Ce n'est pas un nom figé (le schéma n'a pas de colonne pour
        // cela) : le document PDF original, lui, est figé à sa génération.
        $validatorName = $row->validated_by === null ? null : DB::table('users')
            ->where('clinic_id', $row->clinic_id)
            ->where('id', $row->validated_by)
            ->value('display_name');

        $documentId = DB::table('generated_documents')
            ->where('clinic_id', $row->clinic_id)
            ->where('document_type', 'OPTICAL_PRESCRIPTION')
            ->where('prescription_id', $row->id)
            ->orderBy('created_at')
            ->value('id');

        return [
            'id' => (string) $row->id,
            'series_id' => (string) $row->series_id,
            'version_no' => (int) $row->version_no,
            'supersedes_id' => $row->supersedes_id === null ? null : (string) $row->supersedes_id,
            'patient_id' => (string) $row->patient_id,
            'origin' => (string) $row->origin,
            'consultation_id' => $row->consultation_id === null ? null : (string) $row->consultation_id,
            'external_prescriber' => $row->external_prescriber === null ? null : (string) $row->external_prescriber,
            'external_prescribed_on' => $row->external_prescribed_on === null ? null : (string) $row->external_prescribed_on,
            'external_file_id' => $row->external_file_id === null ? null : (string) $row->external_file_id,
            'status' => (string) $row->status,
            'usage_code' => $row->usage_code === null ? null : (string) $row->usage_code,
            'usage_other' => $row->usage_other,
            'lens_type_code' => $row->lens_type_code === null ? null : (string) $row->lens_type_code,
            'lens_type_other' => $row->lens_type_other,
            'lens_index' => $row->lens_index === null ? null : (string) $row->lens_index,
            'lens_index_other' => $row->lens_index_other,
            'pd_total' => self::decimal($row->pd_total),
            'pd_od' => self::decimal($row->pd_od),
            'pd_og' => self::decimal($row->pd_og),
            'pd_inconsistency_reason' => $row->pd_inconsistency_reason,
            'notes' => $row->notes,
            'eyes' => array_values($eyes),
            'treatments' => array_values($treatments),
            'validated_at' => $row->validated_at === null ? null : (string) $row->validated_at,
            'validated_by' => $row->validated_by === null ? null : (string) $row->validated_by,
            'validated_by_name' => $validatorName === null ? null : (string) $validatorName,
            'document_id' => $documentId === null ? null : (string) $documentId,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * Valeurs déjà enregistrées d'un brouillon qui restent valides même si leur code est désactivé depuis.
     *
     * @param  array<string, mixed>  $view
     * @return array{usage_code: string|null, lens_type_code: string|null, treatments: array<string, string>}
     */
    public function stored(array $view): array
    {
        $treatments = [];
        foreach ($view['treatments'] as $treatment) {
            $treatments[(string) $treatment['id']] = (string) $treatment['term_code'];
        }

        return ['usage_code' => $view['usage_code'], 'lens_type_code' => $view['lens_type_code'], 'treatments' => $treatments];
    }

    /**
     * Écrit les yeux d'une prescription BROUILLON (deux lignes, OD puis OG).
     *
     * @param  list<array<string, mixed>>  $eyes
     */
    public function insertEyes(string $clinicId, string $prescriptionId, array $eyes, string $now): void
    {
        $rows = [];
        foreach ($eyes as $eye) {
            $rows[] = [
                'id' => $eye['id'],
                'clinic_id' => $clinicId,
                'prescription_id' => $prescriptionId,
                'eye' => $eye['eye'],
                'no_correction' => $eye['no_correction'],
                'sphere' => $eye['sphere'],
                'cylinder' => $eye['cylinder'],
                'axis' => $eye['axis'],
                'addition' => $eye['addition'],
                'fitting_height' => $eye['fitting_height'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('optical_prescription_eyes')->insert($rows);
        }
    }

    /**
     * Écrit les traitements d'une prescription BROUILLON.
     *
     * @param  list<array<string, mixed>>  $treatments
     */
    public function insertTreatments(string $clinicId, string $prescriptionId, array $treatments, string $now): void
    {
        $rows = [];
        foreach ($treatments as $treatment) {
            $rows[] = [
                'id' => $treatment['id'],
                'clinic_id' => $clinicId,
                'prescription_id' => $prescriptionId,
                'term_group' => 'LENS_TREATMENT',
                'term_code' => $treatment['term_code'],
                'other_text' => $treatment['other_text'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('optical_prescription_treatments')->insert($rows);
        }
    }

    /**
     * Supprime les enfants d'un BROUILLON (avant de les réécrire) : sur une prescription validée, les triggers
     * de la base refuseraient, ce que les actions n'atteignent jamais (statut contrôlé avant).
     */
    public function deleteChildren(string $clinicId, string $prescriptionId, bool $eyes, bool $treatments): void
    {
        if ($eyes) {
            DB::table('optical_prescription_eyes')->where('clinic_id', $clinicId)->where('prescription_id', $prescriptionId)->delete();
        }
        if ($treatments) {
            DB::table('optical_prescription_treatments')->where('clinic_id', $clinicId)->where('prescription_id', $prescriptionId)->delete();
        }
    }

    /**
     * Identifiants des enfants d'une prescription (pour distinguer « les siens » d'un identifiant pris ailleurs).
     *
     * @return array{eyes: list<string>, treatments: list<string>}
     */
    public function childIds(string $clinicId, string $prescriptionId): array
    {
        $ids = static fn (string $table): array => array_values(DB::table($table)
            ->where('clinic_id', $clinicId)
            ->where('prescription_id', $prescriptionId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());

        return ['eyes' => $ids('optical_prescription_eyes'), 'treatments' => $ids('optical_prescription_treatments')];
    }

    private static function decimal(mixed $value): ?string
    {
        return $value === null ? null : FixedDecimal::fromString((string) $value, 2)->canonical();
    }
}
