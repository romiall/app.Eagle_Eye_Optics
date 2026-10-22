<?php

namespace App\Domain\Clinical;

use App\Domain\Clinical\Rules\CenteringStep;
use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\RefractionStep;
use App\Domain\Clinical\Rules\VisualAcuityStep;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Rubriques MESURÉES du dossier (C02) : VISUAL_ACUITY, REFRACTION et CENTERING. Validation de l'état résultant
 * (`Rules/*Step`), contrôle des identifiants de ligne, écriture par REMPLACEMENT COMPLET et comparaison avec l'état
 * enregistré, sur le modèle de `NarrativeSteps`.
 *
 * Comparaison par identifiant de ligne : une ligne inchangée n'est pas touchée, une ligne dont la clé d'unicité
 * change (contexte / distance / correction / œil d'une acuité ; stade / œil d'une mesure) est supprimée puis recréée
 * avec le même identifiant, et TOUTES les suppressions précèdent les mises à jour puis les insertions : deux lignes
 * peuvent échanger leur clé sans heurter l'unicité. Les valeurs sont écrites telles que validées (chaînes décimales
 * exactes) : rien n'est arrondi, déduit ni converti.
 */
final class MeasuredSteps
{
    private const ACUITIES = 'visual_acuities';

    private const MEASUREMENTS = 'refraction_measurements';

    private const AMETROPIAS = 'consultation_ametropias';

    private const CENTERING = 'autorefractor_centering';

    public function __construct(
        private readonly ClinicalTerms $terms,
        private readonly VisualAcuityScales $scales,
        private readonly NarrativeSteps $narrative,
        private readonly ConsultationReader $reader,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(stdClass $consultation, string $step, array $data, FieldErrors $errors): array
    {
        $clinicId = (string) $consultation->clinic_id;

        return match ($step) {
            ClinicalSteps::VISUAL_ACUITY => ['acuities' => VisualAcuityStep::normalize(
                $data,
                $this->scales->activeIds($clinicId),
                $this->scales->knownIds($clinicId),
                $this->storedAcuities($consultation),
                $errors,
            )],
            ClinicalSteps::REFRACTION => RefractionStep::normalize(
                $data,
                $this->terms->activeCodes('AMETROPIA'),
                $this->narrative->storedCodes(self::AMETROPIAS, $consultation),
                $errors,
            ),
            ClinicalSteps::CENTERING => CenteringStep::normalize($data, $this->storedCenteringId($consultation), $errors),
            default => throw new InvalidArgumentException('Rubrique non mesurée : '.$step),
        };
    }

    /**
     * Un identifiant de ligne déjà utilisé AILLEURS (autre consultation, autre cabinet) est refusé par champ (422), jamais
     * une erreur SQL : les clés primaires sont globales.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function rejectForeignIds(stdClass $consultation, string $step, array $data, FieldErrors $errors, string $path = 'step.data'): void
    {
        switch ($step) {
            case ClinicalSteps::VISUAL_ACUITY:
                StepRows::flagTaken(self::ACUITIES, StepRows::idsAt($data['acuities'] ?? null), StepRows::ownIds(self::ACUITIES, $consultation), $path.'.acuities', $errors);
                break;
            case ClinicalSteps::REFRACTION:
                StepRows::flagTaken(self::MEASUREMENTS, StepRows::idsAt($data['measurements'] ?? null), StepRows::ownIds(self::MEASUREMENTS, $consultation), $path.'.measurements', $errors);
                $this->narrative->rejectFlat(self::AMETROPIAS, $consultation, $data['ametropias'] ?? null, $path.'.ametropias', $errors);
                break;
            case ClinicalSteps::CENTERING:
                $id = $data['centering_id'] ?? null;
                if (is_string($id) && Ulid::isValid($id)) {
                    StepRows::flagTaken(self::CENTERING, ['centering_id' => $id], StepRows::ownIds(self::CENTERING, $consultation), $path, $errors, '');
                }
                break;
        }
    }

    /**
     * Vrai si la rubrique enregistrée est déjà exactement l'état résultant (l'ordre des listes ne compte pas).
     *
     * @param  array<string, mixed>  $normalized
     */
    public function isUnchanged(stdClass $consultation, string $step, array $normalized): bool
    {
        return CanonicalJson::hash(StepRows::canonical($this->reader->step($consultation, $step))) === CanonicalJson::hash(StepRows::canonical($normalized));
    }

    /**
     * Écrit les lignes enfants de la rubrique et rend les colonnes de `consultations` à mettre à jour.
     *
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public function write(stdClass $consultation, string $step, array $normalized, string $now): array
    {
        switch ($step) {
            case ClinicalSteps::VISUAL_ACUITY:
                $this->syncRows(self::ACUITIES, $consultation, StepRows::rows($normalized['acuities'] ?? []), ['context', 'distance', 'correction', 'eye'], ['scale_id', 'raw_value', 'normalized_value'], ConsultationReader::acuityRow(...), $now);

                return [];
            case ClinicalSteps::REFRACTION:
                $this->syncRows(self::MEASUREMENTS, $consultation, StepRows::rows($normalized['measurements'] ?? []), ['stage', 'eye'], ['sphere', 'cylinder', 'axis', 'addition'], ConsultationReader::measurementRow(...), $now);
                $this->narrative->syncTerms(self::AMETROPIAS, 'AMETROPIA', $consultation, StepRows::rows($normalized['ametropias'] ?? []), $now);

                return ['refraction_observations' => $normalized['refraction_observations'] ?? null];
            case ClinicalSteps::CENTERING:
                $this->writeCentering($consultation, $normalized, $now);

                return [];
            default:
                throw new InvalidArgumentException('Rubrique non mesurée : '.$step);
        }
    }

    /**
     * Le centrage est UNE ligne par consultation : sans valeur, pas de ligne (identifiant null) ; avec des valeurs,
     * insertion ou mise à jour de la ligne dont l'identifiant a été fourni par le poste.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function writeCentering(stdClass $consultation, array $normalized, string $now): void
    {
        $clinicId = (string) $consultation->clinic_id;
        $consultationId = (string) $consultation->id;
        $existing = DB::table(self::CENTERING)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->first();
        $id = $normalized['centering_id'] ?? null;

        if ($id === null) {
            if ($existing instanceof stdClass) {
                DB::table(self::CENTERING)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->delete();
            }

            return;
        }

        $values = [
            'pd_total' => $normalized['pd_total'] ?? null,
            'pd_od' => $normalized['pd_od'] ?? null,
            'pd_og' => $normalized['pd_og'] ?? null,
            'inconsistency_reason' => $normalized['inconsistency_reason'] ?? null,
        ];
        if (! $existing instanceof stdClass) {
            DB::table(self::CENTERING)->insert($values + [
                'id' => (string) $id,
                'clinic_id' => $clinicId,
                'consultation_id' => $consultationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table(self::CENTERING)->where('clinic_id', $clinicId)->where('id', (string) $existing->id)->update($values + ['updated_at' => $now]);
    }

    /**
     * Comparaison par identifiant : supprime, met à jour, insère (dans cet ordre).
     *
     * @param  list<array<string, mixed>>  $incoming  lignes de l'état résultant (avec `id`)
     * @param  list<string>  $keyColumns  colonnes dont le changement recrée la ligne (clé d'unicité)
     * @param  list<string>  $valueColumns  colonnes mises à jour sur place
     * @param  callable(stdClass): array<string, mixed>  $shape  ligne de la base au format de l'état résultant
     */
    private function syncRows(string $table, stdClass $consultation, array $incoming, array $keyColumns, array $valueColumns, callable $shape, string $now): void
    {
        $clinicId = (string) $consultation->clinic_id;
        $consultationId = (string) $consultation->id;

        $existing = [];
        foreach (DB::table($table)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->get() as $row) {
            $existing[(string) $row->id] = $shape($row);
        }
        $byId = [];
        foreach ($incoming as $row) {
            $byId[(string) $row['id']] = $row;
        }

        $delete = [];
        $update = [];
        $insert = [];
        foreach ($existing as $id => $old) {
            $new = $byId[$id] ?? null;
            if ($new === null || self::differs($old, $new, $keyColumns)) {
                $delete[] = (string) $id;
            } elseif (self::differs($old, $new, $valueColumns)) {
                $update[(string) $id] = array_intersect_key($new, array_flip($valueColumns));
            }
        }
        $columns = array_flip(['id', ...$keyColumns, ...$valueColumns]);
        foreach ($byId as $id => $new) {
            $old = $existing[$id] ?? null;
            if ($old === null || self::differs($old, $new, $keyColumns)) {
                $insert[] = array_intersect_key($new, $columns) + [
                    'clinic_id' => $clinicId,
                    'consultation_id' => $consultationId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($delete !== []) {
            DB::table($table)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->whereIn('id', $delete)->delete();
        }
        foreach ($update as $id => $values) {
            DB::table($table)->where('clinic_id', $clinicId)->where('id', (string) $id)->update($values + ['updated_at' => $now]);
        }
        if ($insert !== []) {
            DB::table($table)->insert($insert);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $columns
     */
    private static function differs(array $old, array $new, array $columns): bool
    {
        foreach ($columns as $column) {
            if (($old[$column] ?? null) !== ($new[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{scale_id: string, normalized_value: string|null}>
     */
    private function storedAcuities(stdClass $consultation): array
    {
        $stored = [];
        foreach (DB::table(self::ACUITIES)->where('clinic_id', $consultation->clinic_id)->where('consultation_id', $consultation->id)->get(['id', 'scale_id', 'normalized_value']) as $row) {
            $stored[(string) $row->id] = [
                'scale_id' => (string) $row->scale_id,
                'normalized_value' => $row->normalized_value === null ? null : (string) $row->normalized_value,
            ];
        }

        return $stored;
    }

    private function storedCenteringId(stdClass $consultation): ?string
    {
        $id = DB::table(self::CENTERING)->where('clinic_id', $consultation->clinic_id)->where('consultation_id', $consultation->id)->value('id');

        return $id === null ? null : (string) $id;
    }
}
