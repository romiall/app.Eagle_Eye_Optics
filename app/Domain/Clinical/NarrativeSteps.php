<?php

namespace App\Domain\Clinical;

use App\Domain\Clinical\Rules\AnamnesisStep;
use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\ConclusionStep;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\HistoriesStep;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Rubriques NARRATIVES du dossier (ANAMNESIS, HISTORIES, CONCLUSION) : validation de l'état résultant,
 * contrôle des identifiants de ligne, écriture par REMPLACEMENT COMPLET de la rubrique et comparaison avec l'état
 * déjà enregistré.
 *
 * Remplacement complet : les lignes absentes de la charge sont supprimées (c'est ainsi qu'on efface une valeur).
 * L'écriture compare par identifiant de ligne, jamais « tout supprimer puis tout réinsérer » : une ligne
 * inchangée n'est pas touchée, une ligne dont la clé d'unicité change (code du terme, groupe d'antécédents) est
 * supprimée puis recréée, et TOUTES les suppressions précèdent les mises à jour puis les insertions, pour que
 * l'échange de deux codes entre deux lignes ne heurte aucune contrainte d'unicité.
 *
 * Toutes les requêtes sont paramétrées ; les noms de table sont des constantes de ce fichier.
 */
final class NarrativeSteps
{
    private const COMPLAINTS = 'consultation_complaints';

    private const REFERRAL_REASONS = 'consultation_referral_reasons';

    private const HISTORIES = 'consultation_histories';

    private const HISTORY_TERMS = 'consultation_history_terms';

    public function __construct(private readonly ClinicalTerms $terms, private readonly ConsultationReader $reader) {}

    /**
     * État résultant validé de la rubrique (au format de `ConsultationReader::step`, listes comprises).
     *
     * Un code désactivé depuis la saisie reste ré-enregistrable pour la ligne qui le porte déjà (même identifiant,
     * même code) : voir `TermList`. Toute ligne nouvelle exige un code actif.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(stdClass $consultation, string $step, array $data, FieldErrors $errors): array
    {
        return match ($step) {
            ClinicalSteps::ANAMNESIS => AnamnesisStep::normalize($data, $this->terms->activeCodes('COMPLAINT'), $errors, 'step.data', $this->storedCodes(self::COMPLAINTS, $consultation)),
            ClinicalSteps::HISTORIES => ['histories' => HistoriesStep::normalize($data, $this->historyCodes(), $errors, 'step.data', $this->storedHistoryCodes($consultation))],
            ClinicalSteps::CONCLUSION => ConclusionStep::normalize($data, $this->terms->activeCodes('REFERRAL_REASON'), $errors, 'step.data', $this->storedCodes(self::REFERRAL_REASONS, $consultation)),
            default => throw new InvalidArgumentException('Rubrique non narrative : '.$step),
        };
    }

    /**
     * Codes des lignes déjà enregistrées d'une table de termes de la consultation (identifiant → code).
     *
     * @return array<string, string>
     */
    public function storedCodes(string $table, stdClass $consultation): array
    {
        $codes = [];
        foreach (DB::table($table)->where('clinic_id', $consultation->clinic_id)->where('consultation_id', $consultation->id)->get(['id', 'term_code']) as $row) {
            $codes[(string) $row->id] = (string) $row->term_code;
        }

        return $codes;
    }

    /**
     * Termes d'antécédents déjà enregistrés, par groupe (identifiant → code).
     *
     * @return array<string, array<string, string>>
     */
    private function storedHistoryCodes(stdClass $consultation): array
    {
        $codes = [];
        $terms = DB::table(self::HISTORY_TERMS.' as t')
            ->join(self::HISTORIES.' as h', static function ($join): void {
                $join->on('h.clinic_id', '=', 't.clinic_id')->on('h.id', '=', 't.history_id');
            })
            ->where('h.clinic_id', $consultation->clinic_id)
            ->where('h.consultation_id', $consultation->id)
            ->get(['t.id', 't.term_code', 'h.history_group']);
        foreach ($terms as $term) {
            $codes[(string) $term->history_group][(string) $term->id] = (string) $term->term_code;
        }

        return $codes;
    }

    /**
     * Un identifiant de ligne déjà utilisé AILLEURS (autre consultation, autre cabinet) est refusé par champ : la
     * clé primaire est globale, et le refus doit être un 422 lisible, jamais une erreur SQL. Les lignes de CETTE
     * consultation peuvent évidemment être réutilisées.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function rejectForeignIds(stdClass $consultation, string $step, array $data, FieldErrors $errors, string $path = 'step.data'): void
    {
        switch ($step) {
            case ClinicalSteps::ANAMNESIS:
                $this->rejectFlat(self::COMPLAINTS, $consultation, $data['complaints'] ?? null, $path.'.complaints', $errors);
                break;
            case ClinicalSteps::CONCLUSION:
                $this->rejectFlat(self::REFERRAL_REASONS, $consultation, $data['referral_reasons'] ?? null, $path.'.referral_reasons', $errors);
                break;
            case ClinicalSteps::HISTORIES:
                $this->rejectHistories($consultation, $data['histories'] ?? null, $path.'.histories', $errors);
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
        return CanonicalJson::hash(self::canonical($this->reader->step($consultation, $step))) === CanonicalJson::hash(self::canonical($normalized));
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
            case ClinicalSteps::ANAMNESIS:
                $this->syncTerms(self::COMPLAINTS, 'COMPLAINT', $consultation, self::rows($normalized['complaints'] ?? []), $now);

                return [
                    'symptoms' => $normalized['symptoms'],
                    'wears_glasses' => $normalized['wears_glasses'],
                    'glasses_duration' => $normalized['glasses_duration'],
                    'previous_correction_available' => $normalized['previous_correction_available'],
                ];
            case ClinicalSteps::CONCLUSION:
                $this->syncTerms(self::REFERRAL_REASONS, 'REFERRAL_REASON', $consultation, self::rows($normalized['referral_reasons'] ?? []), $now);

                return [
                    'conclusion' => $normalized['conclusion'],
                    'referral' => $normalized['referral'],
                    'referral_urgency' => $normalized['referral_urgency'],
                ];
            case ClinicalSteps::HISTORIES:
                $this->syncHistories($consultation, self::rows($normalized['histories'] ?? []), $now);

                return [];
            default:
                throw new InvalidArgumentException('Rubrique non ouverte : '.$step);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function historyCodes(): array
    {
        $codes = [];
        foreach (HistoriesStep::GROUPS as $group) {
            $codes[$group] = $this->terms->activeCodes($group);
        }

        return $codes;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  lignes `{id, term_code, other_text}` de l'état résultant
     */
    public function syncTerms(string $table, string $group, stdClass $consultation, array $rows, string $now): void
    {
        $clinicId = (string) $consultation->clinic_id;
        $consultationId = (string) $consultation->id;

        /** @var array<string, stdClass> $existing */
        $existing = DB::table($table)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->get()->keyBy('id')->all();
        $incoming = [];
        foreach ($rows as $row) {
            $incoming[(string) $row['id']] = $row;
        }

        $delete = [];
        $update = [];
        foreach ($existing as $id => $old) {
            $new = $incoming[$id] ?? null;
            if ($new === null || $new['term_code'] !== $old->term_code) {
                $delete[] = (string) $id;
            } elseif ($new['other_text'] !== $old->other_text) {
                $update[(string) $id] = $new['other_text'];
            }
        }
        $insert = [];
        foreach ($incoming as $id => $new) {
            $old = $existing[$id] ?? null;
            if ($old === null || $new['term_code'] !== $old->term_code) {
                $insert[] = [
                    'id' => $id,
                    'clinic_id' => $clinicId,
                    'consultation_id' => $consultationId,
                    'term_group' => $group,
                    'term_code' => $new['term_code'],
                    'other_text' => $new['other_text'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($delete !== []) {
            DB::table($table)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->whereIn('id', $delete)->delete();
        }
        foreach ($update as $id => $otherText) {
            DB::table($table)->where('clinic_id', $clinicId)->where('id', $id)->update(['other_text' => $otherText, 'updated_at' => $now]);
        }
        if ($insert !== []) {
            DB::table($table)->insert($insert);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $histories
     */
    private function syncHistories(stdClass $consultation, array $histories, string $now): void
    {
        $clinicId = (string) $consultation->clinic_id;
        $consultationId = (string) $consultation->id;

        /** @var array<string, stdClass> $existing */
        $existing = DB::table(self::HISTORIES)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->get()->keyBy('id')->all();
        /** @var array<string, array<string, stdClass>> $oldTerms */
        $oldTerms = [];
        if ($existing !== []) {
            foreach (DB::table(self::HISTORY_TERMS)->where('clinic_id', $clinicId)->whereIn('history_id', array_map('strval', array_keys($existing)))->get() as $term) {
                $oldTerms[(string) $term->history_id][(string) $term->id] = $term;
            }
        }
        $incoming = [];
        foreach ($histories as $history) {
            $incoming[(string) $history['id']] = $history;
        }

        $deleteTerms = [];
        $deleteHistories = [];
        foreach ($existing as $id => $old) {
            $new = $incoming[$id] ?? null;
            if ($new === null || $new['history_group'] !== $old->history_group) {
                $deleteHistories[] = (string) $id;
                array_push($deleteTerms, ...array_map('strval', array_keys($oldTerms[(string) $id] ?? [])));
            }
        }

        $updateHistories = [];
        $updateTerms = [];
        $insertHistories = [];
        $insertTerms = [];
        foreach ($incoming as $id => $new) {
            $id = (string) $id;
            $old = $existing[$id] ?? null;
            $group = (string) $new['history_group'];
            $terms = self::rows($new['terms'] ?? []);

            if ($old === null || $old->history_group !== $group) {
                $insertHistories[] = [
                    'id' => $id,
                    'clinic_id' => $clinicId,
                    'consultation_id' => $consultationId,
                    'history_group' => $group,
                    'known_none' => $new['known_none'],
                    'comments' => $new['comments'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                foreach ($terms as $term) {
                    $insertTerms[] = $this->termRow($clinicId, $id, $group, $term, $now);
                }

                continue;
            }

            if ((bool) $old->known_none !== $new['known_none'] || $old->comments !== $new['comments']) {
                $updateHistories[$id] = ['known_none' => $new['known_none'], 'comments' => $new['comments'], 'updated_at' => $now];
            }
            $currentTerms = $oldTerms[$id] ?? [];
            $incomingTerms = [];
            foreach ($terms as $term) {
                $incomingTerms[(string) $term['id']] = $term;
            }
            foreach ($currentTerms as $termId => $current) {
                $next = $incomingTerms[$termId] ?? null;
                if ($next === null || $next['term_code'] !== $current->term_code) {
                    $deleteTerms[] = (string) $termId;
                } elseif ($next['other_text'] !== $current->other_text) {
                    $updateTerms[(string) $termId] = $next['other_text'];
                }
            }
            foreach ($terms as $term) {
                $current = $currentTerms[(string) $term['id']] ?? null;
                if ($current === null || $current->term_code !== $term['term_code']) {
                    $insertTerms[] = $this->termRow($clinicId, $id, $group, $term, $now);
                }
            }
        }

        if ($deleteTerms !== []) {
            DB::table(self::HISTORY_TERMS)->where('clinic_id', $clinicId)->whereIn('id', $deleteTerms)->delete();
        }
        if ($deleteHistories !== []) {
            DB::table(self::HISTORIES)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->whereIn('id', $deleteHistories)->delete();
        }
        foreach ($updateHistories as $id => $columns) {
            DB::table(self::HISTORIES)->where('clinic_id', $clinicId)->where('id', $id)->update($columns);
        }
        foreach ($updateTerms as $id => $otherText) {
            DB::table(self::HISTORY_TERMS)->where('clinic_id', $clinicId)->where('id', $id)->update(['other_text' => $otherText, 'updated_at' => $now]);
        }
        if ($insertHistories !== []) {
            DB::table(self::HISTORIES)->insert($insertHistories);
        }
        if ($insertTerms !== []) {
            DB::table(self::HISTORY_TERMS)->insert($insertTerms);
        }
    }

    /**
     * @param  array<string, mixed>  $term
     * @return array<string, mixed>
     */
    private function termRow(string $clinicId, string $historyId, string $group, array $term, string $now): array
    {
        return [
            'id' => $term['id'],
            'clinic_id' => $clinicId,
            'history_id' => $historyId,
            'term_group' => $group,
            'term_code' => $term['term_code'],
            'other_text' => $term['other_text'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function rejectFlat(string $table, stdClass $consultation, mixed $items, string $path, FieldErrors $errors): void
    {
        $incoming = self::idsAt($items);
        if ($incoming === []) {
            return;
        }
        $own = DB::table($table)->where('clinic_id', $consultation->clinic_id)->where('consultation_id', $consultation->id)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $this->flagTaken($table, $incoming, $own, $path, $errors);
    }

    private function rejectHistories(stdClass $consultation, mixed $items, string $path, FieldErrors $errors): void
    {
        $incoming = self::idsAt($items);
        if ($incoming !== []) {
            $own = DB::table(self::HISTORIES)->where('clinic_id', $consultation->clinic_id)->where('consultation_id', $consultation->id)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
            $this->flagTaken(self::HISTORIES, $incoming, $own, $path, $errors);
        }

        // Identifiants des termes de tous les éléments : le terme d'une consultation peut changer d'élément.
        $termIds = [];
        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (is_array($item)) {
                    foreach (self::idsAt($item['terms'] ?? null) as $termIndex => $id) {
                        $termIds[$index.'.terms.'.$termIndex] = $id;
                    }
                }
            }
        }
        if ($termIds === []) {
            return;
        }
        $own = DB::table(self::HISTORY_TERMS.' as t')
            ->join(self::HISTORIES.' as h', static function ($join): void {
                $join->on('h.clinic_id', '=', 't.clinic_id')->on('h.id', '=', 't.history_id');
            })
            ->where('h.clinic_id', $consultation->clinic_id)
            ->where('h.consultation_id', $consultation->id)
            ->pluck('t.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $this->flagTaken(self::HISTORY_TERMS, $termIds, $own, $path, $errors);
    }

    /**
     * @param  array<int|string, string>  $incoming  identifiant de ligne par clé de chemin (« 2 » ou « 1.terms.0 »)
     * @param  array<array-key, string>  $own  identifiants des lignes de cette consultation (réutilisables)
     */
    private function flagTaken(string $table, array $incoming, array $own, string $path, FieldErrors $errors): void
    {
        $present = DB::table($table)->whereIn('id', array_values(array_unique($incoming)))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $taken = array_diff($present, $own);
        foreach ($incoming as $key => $id) {
            if (in_array($id, $taken, true)) {
                $errors->add($path.'.'.$key.'.id', 'Cet identifiant est déjà utilisé : générez un identifiant neuf pour une nouvelle ligne.');
            }
        }
    }

    /**
     * Identifiants ULID valides d'une liste de lignes brutes, par position.
     *
     * @return array<int, string>
     */
    private static function idsAt(mixed $items): array
    {
        $ids = [];
        if (is_array($items) && array_is_list($items)) {
            foreach ($items as $index => $item) {
                if (is_array($item) && is_string($item['id'] ?? null) && Ulid::isValid($item['id'])) {
                    $ids[$index] = $item['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        $rows = [];
        if (is_array($value)) {
            foreach ($value as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Forme comparable : les listes de lignes (portant `id`) sont triées par identifiant, à tous les niveaux.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $mapped = array_map(self::canonical(...), $value);
        if (array_is_list($mapped) && $mapped !== [] && is_array($mapped[0]) && isset($mapped[0]['id'])) {
            usort($mapped, static fn (mixed $a, mixed $b): int => strcmp((string) (is_array($a) ? $a['id'] : ''), (string) (is_array($b) ? $b['id'] : '')));
        }

        return $mapped;
    }
}
