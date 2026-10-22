<?php

namespace App\Domain\Clinical;

use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\HistoriesStep;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Lecture d'une consultation dans les tables VIVANTES, au format `AllSteps` du contrat
 * (`clinical-steps.json`) : c'est ce que rendent `GET /consultations/{id}` et les réponses d'écriture, et ce
 * que compare l'enregistrement d'une rubrique pour reconnaître un enregistrement sans effet.
 *
 * Toujours limitée au cabinet. Une rubrique sans ligne est rendue VIDE (null, listes vides), jamais remplie de
 * valeurs par défaut : « non mesuré » n'est pas zéro. Les listes ont un ordre stable (ordre d'affichage du
 * référentiel, puis identifiant).
 */
final class ConsultationReader
{
    private const ACUITY_ORDER = [
        'context' => ['PRELIMINARY', 'POST_REFRACTION'],
        'distance' => ['FAR', 'NEAR', 'UNSPECIFIED'],
        'correction' => ['WITHOUT', 'WITH'],
        'eye' => ['OD', 'OG', 'OU'],
    ];

    private const STAGE_ORDER = ['AUTOREFRACTOR', 'OBJECTIVE', 'SUBJECTIVE'];

    private const EYE_ORDER = ['OD', 'OG'];

    public function find(string $clinicId, string $consultationId): ?stdClass
    {
        $row = DB::table('consultations')->where('clinic_id', $clinicId)->where('id', $consultationId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @return array<string, array<string, mixed>> les six rubriques, dans l'ordre du dossier
     */
    public function steps(stdClass $consultation): array
    {
        $steps = [];
        foreach (ClinicalSteps::ALL as $step) {
            $steps[$step] = $this->step($consultation, $step);
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    public function step(stdClass $consultation, string $step): array
    {
        return match ($step) {
            ClinicalSteps::ANAMNESIS => $this->anamnesis($consultation),
            ClinicalSteps::HISTORIES => $this->histories($consultation),
            ClinicalSteps::VISUAL_ACUITY => $this->visualAcuity($consultation),
            ClinicalSteps::REFRACTION => $this->refraction($consultation),
            ClinicalSteps::CENTERING => $this->centering($consultation),
            ClinicalSteps::CONCLUSION => $this->conclusion($consultation),
            default => throw new InvalidArgumentException('Rubrique inconnue : '.$step),
        };
    }

    /**
     * Révisions figées (métadonnées seulement, jamais le snapshot) : aucune avant la finalisation.
     *
     * @return list<array<string, mixed>>
     */
    public function revisions(stdClass $consultation): array
    {
        $revisions = DB::table('clinical_revisions')
            ->where('clinic_id', $consultation->clinic_id)
            ->where('consultation_id', $consultation->id)
            ->orderBy('revision_no')
            ->get(['id', 'revision_no', 'recorded_at', 'author_id', 'reason', 'previous_revision_id', 'sha256'])
            ->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'revision_no' => (int) $row->revision_no,
                'recorded_at' => (string) $row->recorded_at,
                'author_id' => (string) $row->author_id,
                'reason' => (string) $row->reason,
                'previous_revision_id' => $row->previous_revision_id === null ? null : (string) $row->previous_revision_id,
                'sha256' => (string) $row->sha256,
            ])
            ->all();

        return array_values($revisions);
    }

    /**
     * @return array<string, mixed>
     */
    private function anamnesis(stdClass $c): array
    {
        return [
            'symptoms' => $c->symptoms,
            'wears_glasses' => self::flag($c->wears_glasses),
            'glasses_duration' => $c->glasses_duration,
            'previous_correction_available' => self::flag($c->previous_correction_available),
            'complaints' => $this->terms('consultation_complaints', $c),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function histories(stdClass $c): array
    {
        $histories = DB::table('consultation_histories')
            ->where('clinic_id', $c->clinic_id)
            ->where('consultation_id', $c->id)
            ->get()
            ->all();
        usort($histories, static fn (stdClass $a, stdClass $b): int => array_search($a->history_group, HistoriesStep::GROUPS, true) <=> array_search($b->history_group, HistoriesStep::GROUPS, true));

        $termsByHistory = [];
        if ($histories !== []) {
            $terms = DB::table('consultation_history_terms as t')
                ->join('clinical_terms as ref', static function ($join): void {
                    $join->on('ref.group_code', '=', 't.term_group')->on('ref.code', '=', 't.term_code');
                })
                ->where('t.clinic_id', $c->clinic_id)
                ->whereIn('t.history_id', array_map(static fn (stdClass $history): string => (string) $history->id, $histories))
                ->orderBy('ref.sort_order')
                ->orderBy('t.id')
                ->get(['t.history_id', 't.id', 't.term_code', 't.other_text']);
            foreach ($terms as $term) {
                $termsByHistory[(string) $term->history_id][] = ['id' => (string) $term->id, 'term_code' => (string) $term->term_code, 'other_text' => $term->other_text];
            }
        }

        return [
            'histories' => array_map(static fn (stdClass $history): array => [
                'id' => (string) $history->id,
                'history_group' => (string) $history->history_group,
                'known_none' => (bool) $history->known_none,
                'comments' => $history->comments,
                'terms' => $termsByHistory[(string) $history->id] ?? [],
            ], $histories),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visualAcuity(stdClass $c): array
    {
        $rows = DB::table('visual_acuities')->where('clinic_id', $c->clinic_id)->where('consultation_id', $c->id)->get()->all();
        usort($rows, static function (stdClass $a, stdClass $b): int {
            foreach (self::ACUITY_ORDER as $column => $order) {
                $compare = array_search($a->{$column}, $order, true) <=> array_search($b->{$column}, $order, true);
                if ($compare !== 0) {
                    return $compare;
                }
            }

            return strcmp((string) $a->id, (string) $b->id);
        });

        return [
            'acuities' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'context' => (string) $row->context,
                'distance' => (string) $row->distance,
                'correction' => (string) $row->correction,
                'eye' => (string) $row->eye,
                'scale_id' => (string) $row->scale_id,
                'raw_value' => (string) $row->raw_value,
                'normalized_value' => $row->normalized_value === null ? null : (string) $row->normalized_value,
            ], $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refraction(stdClass $c): array
    {
        $rows = DB::table('refraction_measurements')->where('clinic_id', $c->clinic_id)->where('consultation_id', $c->id)->get()->all();
        usort($rows, static fn (stdClass $a, stdClass $b): int => [array_search($a->stage, self::STAGE_ORDER, true), array_search($a->eye, self::EYE_ORDER, true)]
            <=> [array_search($b->stage, self::STAGE_ORDER, true), array_search($b->eye, self::EYE_ORDER, true)]);

        return [
            'measurements' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'stage' => (string) $row->stage,
                'eye' => (string) $row->eye,
                'sphere' => self::diopter($row->sphere),
                'cylinder' => self::diopter($row->cylinder),
                'axis' => $row->axis === null ? null : (int) $row->axis,
                'addition' => self::diopter($row->addition),
            ], $rows),
            'refraction_observations' => $c->refraction_observations,
            'ametropias' => $this->terms('consultation_ametropias', $c),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function centering(stdClass $c): array
    {
        $row = DB::table('autorefractor_centering')->where('clinic_id', $c->clinic_id)->where('consultation_id', $c->id)->first();
        if (! $row instanceof stdClass) {
            return ['centering_id' => null, 'pd_total' => null, 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null];
        }

        return [
            'centering_id' => (string) $row->id,
            'pd_total' => self::diopter($row->pd_total),
            'pd_od' => self::diopter($row->pd_od),
            'pd_og' => self::diopter($row->pd_og),
            'inconsistency_reason' => $row->inconsistency_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conclusion(stdClass $c): array
    {
        return [
            'conclusion' => $c->conclusion,
            'referral' => self::flag($c->referral),
            'referral_urgency' => $c->referral_urgency,
            'referral_reasons' => $this->terms('consultation_referral_reasons', $c),
        ];
    }

    /**
     * Lignes `{id, term_code, other_text}` d'une table de termes de la consultation, dans l'ordre d'affichage du
     * référentiel. Le nom de table est toujours une constante de ce fichier.
     *
     * @return list<array{id: string, term_code: string, other_text: mixed}>
     */
    private function terms(string $table, stdClass $c): array
    {
        $terms = DB::table($table.' as t')
            ->join('clinical_terms as ref', static function ($join): void {
                $join->on('ref.group_code', '=', 't.term_group')->on('ref.code', '=', 't.term_code');
            })
            ->where('t.clinic_id', $c->clinic_id)
            ->where('t.consultation_id', $c->id)
            ->orderBy('ref.sort_order')
            ->orderBy('t.id')
            ->get(['t.id', 't.term_code', 't.other_text'])
            ->map(static fn (stdClass $row): array => ['id' => (string) $row->id, 'term_code' => (string) $row->term_code, 'other_text' => $row->other_text])
            ->all();

        return array_values($terms);
    }

    private static function flag(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    /**
     * Mesure optique rendue par MySQL (« 1.25 », « -0.50 », « 0.00 ») en forme canonique ; null reste null.
     */
    private static function diopter(mixed $value): ?string
    {
        return $value === null ? null : FixedDecimal::fromString((string) $value, 2)->canonical();
    }
}
