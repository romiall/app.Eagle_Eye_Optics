<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\Ulid;

/**
 * Rubrique HISTORIES (modèle DP §5) : au plus un élément par groupe d'antécédents (oculaires, médicaux,
 * familiaux oculaires), état OBSERVÉ pendant cette consultation.
 *
 * - L'absence d'élément pour un groupe signifie « non renseigné », distinct d'« Aucun connu ».
 * - « Aucun connu » est `known_none = true` et exclut tous les autres éléments du groupe ; il n'est jamais un terme.
 * - Chaque terme appartient au groupe de son élément ; « Autre » exige un texte ; pas de doublon.
 * - Un élément vide (ni « Aucun connu », ni terme, ni commentaire) est admis tel quel : il est enregistré comme un
 *   élément sans réponse, distinct de l'absence d'élément. Écart connu, documenté dans clinical.md (non corrigé) : les
 *   deux se lisent « non renseigné » ; l'écran n'envoie jamais d'élément vide (`isHistoryGroupFilled`).
 */
final class HistoriesStep
{
    public const GROUPS = ['OCULAR_HISTORY', 'MEDICAL_HISTORY', 'FAMILY_HISTORY'];

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, list<string>>  $codesByGroup  codes actifs de chaque groupe d'antécédents
     * @param  array<string, array<string, string>>  $existingTerms  termes déjà enregistrés, par groupe (identifiant → code) : un code désactivé depuis reste ré-enregistrable pour la même ligne
     * @return list<array{id: string, history_group: string, known_none: bool, comments: string|null, terms: list<array{id: string, term_code: string, other_text: string|null}>}>
     */
    public static function normalize(array $data, array $codesByGroup, FieldErrors $errors, string $path = 'step.data', array $existingTerms = []): array
    {
        $items = $data['histories'] ?? [];
        $at = $path.'.histories';
        if (! is_array($items) || ! array_is_list($items)) {
            $errors->add($at, 'Une liste est attendue.');

            return [];
        }

        $rows = [];
        $groups = [];
        $historyIds = [];
        $termIds = [];
        foreach ($items as $index => $item) {
            $here = $at.'.'.$index;
            if (! is_array($item)) {
                $errors->add($here, 'Un objet est attendu.');

                continue;
            }

            $validId = null;
            $id = $item['id'] ?? null;
            if (! is_string($id) || ! Ulid::isValid($id)) {
                $errors->add($here.'.id', 'Identifiant invalide.');
            } elseif (isset($historyIds[$id])) {
                $errors->add($here.'.id', 'Cet identifiant figure déjà dans la rubrique.');
            } else {
                $historyIds[$id] = true;
                $validId = $id;
            }

            $validGroup = null;
            $group = $item['history_group'] ?? null;
            if (! is_string($group) || ! in_array($group, self::GROUPS, true)) {
                $errors->add($here.'.history_group', 'Groupe d\'antécédents inconnu.');
            } elseif (isset($groups[$group])) {
                $errors->add($here.'.history_group', 'Ce groupe est déjà présent : un seul élément par groupe.');
            } else {
                $groups[$group] = true;
                $validGroup = $group;
            }

            $knownNone = $item['known_none'] ?? null;
            $flagValid = is_bool($knownNone);
            if (! is_bool($knownNone)) {
                $errors->add($here.'.known_none', 'Répondez oui ou non.');
                $knownNone = false;
            }
            $comments = ClinicalInput::text($item['comments'] ?? null, $here.'.comments', $errors);

            $rawTerms = $item['terms'] ?? [];
            $codes = is_string($group) ? ($codesByGroup[$group] ?? []) : [];
            $terms = TermList::normalize($rawTerms, $codes, $here.'.terms', $errors, $termIds, is_string($group) ? ($existingTerms[$group] ?? []) : []);
            $exclusive = true;
            if ($knownNone && is_array($rawTerms) && $rawTerms !== []) {
                $errors->add($here.'.terms', '« Aucun connu » exclut tous les autres éléments du groupe : retirez-les ou décochez « Aucun connu ».');
                $exclusive = false;
            }

            if ($validId !== null && $validGroup !== null && $flagValid && $exclusive) {
                $rows[] = ['id' => $validId, 'history_group' => $validGroup, 'known_none' => $knownNone, 'comments' => $comments, 'terms' => $terms];
            }
        }

        return $rows;
    }
}
