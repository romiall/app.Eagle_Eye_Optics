<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\Ulid;

/**
 * Liste de termes du référentiel `clinical_terms` choisis pendant une consultation (motifs, éléments d'un
 * groupe d'antécédents, motifs d'orientation, amétropies) : chaque ligne est `{id, term_code, other_text}`.
 *
 * Intégrité, jamais corrigée en silence :
 *  - le code existe, est actif et appartient au bon groupe (`$allowed`) ; EXCEPTION : une ligne DÉJÀ enregistrée
 *    sur cette consultation (même identifiant, même code, `$existing`) reste ré-enregistrable même si son code a
 *    été désactivé depuis — sinon toute sauvegarde de la rubrique échouerait tant que la ligne y figure. Une ligne
 *    nouvelle, ou dont le code change, doit porter un code actif ;
 *  - aucun code en double dans la liste, aucun identifiant de ligne en double ;
 *  - « Autre » (`OTHER`) exige un texte ; un texte n'est admis que pour « Autre » ;
 *  - « Aucun connu » (`NONE`) n'est jamais un terme : il s'exprime par `known_none` (d'un groupe d'antécédents).
 *
 * Une ligne invalide est écartée de la sortie et signalée dans `$errors` (le chemin désigne la ligne et le champ).
 * Le message « Précisez le texte de « Autre » » n'est donné que si le texte est réellement absent ou blanc : un
 * texte fourni mais invalide (trop long, caractère de contrôle) a déjà son message propre.
 */
final class TermList
{
    /**
     * @param  list<string>  $allowed  codes actifs du groupe
     * @param  array<string, true>  $usedIds  identifiants de ligne déjà rencontrés dans la rubrique (mis à jour)
     * @param  array<string, string>  $existing  code des lignes déjà enregistrées pour cette liste (identifiant → code)
     * @return list<array{id: string, term_code: string, other_text: string|null}>
     */
    public static function normalize(mixed $items, array $allowed, string $path, FieldErrors $errors, array &$usedIds, array $existing = []): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $errors->add($path, 'Une liste est attendue.');

            return [];
        }

        $rows = [];
        $seenCodes = [];
        foreach ($items as $index => $item) {
            $at = $path.'.'.$index;
            if (! is_array($item)) {
                $errors->add($at, 'Un objet est attendu.');

                continue;
            }

            $validId = null;
            $id = $item['id'] ?? null;
            if (! is_string($id) || ! Ulid::isValid($id)) {
                $errors->add($at.'.id', 'Identifiant invalide.');
            } elseif (isset($usedIds[$id])) {
                $errors->add($at.'.id', 'Cet identifiant figure déjà dans la rubrique.');
            } else {
                $usedIds[$id] = true;
                $validId = $id;
            }

            $validCode = null;
            $code = $item['term_code'] ?? null;
            $stored = $validId !== null && is_string($code) && ($existing[$validId] ?? null) === $code;
            if (is_string($code) && $code === 'NONE' && in_array('NONE', $allowed, true)) {
                $errors->add($at.'.term_code', '« Aucun connu » s\'indique par la case du groupe, jamais comme un élément de la liste.');
            } elseif (! is_string($code) || (! in_array($code, $allowed, true) && ! $stored)) {
                $errors->add($at.'.term_code', 'Terme inconnu, inactif ou d\'un autre groupe.');
            } elseif (isset($seenCodes[$code])) {
                $errors->add($at.'.term_code', 'Ce terme est déjà présent dans la liste.');
            } else {
                $seenCodes[$code] = true;
                $validCode = $code;
            }

            $rawOther = $item['other_text'] ?? null;
            $otherText = ClinicalInput::line($rawOther, $at.'.other_text', $errors);
            $textFits = true;
            if (is_string($code)) {
                if ($code === 'OTHER' && $otherText === null) {
                    // Message de consigne seulement si rien n'a été fourni : un texte invalide a déjà son erreur.
                    if (ClinicalInput::isBlank($rawOther)) {
                        $errors->add($at.'.other_text', 'Précisez le texte de « Autre ».');
                    }
                    $textFits = false;
                } elseif ($code !== 'OTHER' && $otherText !== null) {
                    $errors->add($at.'.other_text', 'Un texte n\'est admis que pour « Autre ».');
                    $textFits = false;
                }
            }

            if ($validId !== null && $validCode !== null && $textFits) {
                $rows[] = ['id' => $validId, 'term_code' => $validCode, 'other_text' => $otherText];
            }
        }

        return $rows;
    }
}
