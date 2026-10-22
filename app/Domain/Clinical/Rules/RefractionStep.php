<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\Ulid;

/**
 * Rubrique REFRACTION (modèle DP §6 et §7 ; fichier 02) : mesures de l'autoréfracteur, de la réfraction
 * objective et de la réfraction subjective, séparées par œil (OD, OG), puis observations libres et amétropies
 * choisies par le professionnel.
 *
 * Règles (jamais corrigées en silence) :
 *  - les trois stades sont DISTINCTS et ne se fusionnent jamais : une ligne par (stade, œil), six au plus ;
 *  - une ligne porte au moins une valeur mesurée ; une valeur non mesurée est null (jamais zéro) et une œil non
 *    mesuré n'a pas de ligne ; zéro est une valeur ;
 *  - sphère, cylindre, addition : `Measures::diopter` (2 décimales, jamais arrondi) ; axe : entier de 0 à 180 ;
 *  - l'addition n'existe que pour la réfraction SUBJECTIVE (CHECK de la base) ;
 *  - aucune inférence : le cylindre positif n'est pas transposé, l'axe n'est pas déduit, les amétropies ne
 *    dépendent d'aucun chiffre (elles appartiennent au professionnel). Un cylindre non nul sans axe est
 *    admis à l'enregistrement d'une MESURE ; il bloque seulement la validation d'une prescription (fichier 02).
 */
final class RefractionStep
{
    public const STAGES = ['AUTOREFRACTOR', 'OBJECTIVE', 'SUBJECTIVE'];

    public const EYES = ['OD', 'OG'];

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $ametropiaCodes  codes actifs du groupe AMETROPIA
     * @param  array<string, string>  $existingAmetropias  amétropies déjà enregistrées (identifiant → code)
     * @return array{measurements: list<array{id: string, stage: string, eye: string, sphere: string|null, cylinder: string|null, axis: int|null, addition: string|null}>, refraction_observations: string|null, ametropias: list<array{id: string, term_code: string, other_text: string|null}>}
     */
    public static function normalize(array $data, array $ametropiaCodes, array $existingAmetropias, FieldErrors $errors, string $path = 'step.data'): array
    {
        $measurements = self::measurements($data['measurements'] ?? [], $errors, $path.'.measurements');
        $observations = ClinicalInput::text($data['refraction_observations'] ?? null, $path.'.refraction_observations', $errors);
        $usedIds = [];
        $ametropias = TermList::normalize($data['ametropias'] ?? [], $ametropiaCodes, $path.'.ametropias', $errors, $usedIds, $existingAmetropias);

        return [
            'measurements' => $measurements,
            'refraction_observations' => $observations,
            'ametropias' => $ametropias,
        ];
    }

    /**
     * @return list<array{id: string, stage: string, eye: string, sphere: string|null, cylinder: string|null, axis: int|null, addition: string|null}>
     */
    private static function measurements(mixed $items, FieldErrors $errors, string $at): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $errors->add($at, 'Une liste est attendue.');

            return [];
        }

        $rows = [];
        $ids = [];
        $pairs = [];
        foreach ($items as $index => $item) {
            $here = $at.'.'.$index;
            if (! is_array($item)) {
                $errors->add($here, 'Un objet est attendu.');

                continue;
            }
            $valid = true;

            $id = $item['id'] ?? null;
            if (! is_string($id) || ! Ulid::isValid($id)) {
                $errors->add($here.'.id', 'Identifiant invalide.');
                $valid = false;
            } elseif (isset($ids[$id])) {
                $errors->add($here.'.id', 'Cet identifiant figure déjà dans la rubrique.');
                $valid = false;
            } else {
                $ids[$id] = true;
            }

            $stage = $item['stage'] ?? null;
            if (! is_string($stage) || ! in_array($stage, self::STAGES, true)) {
                $errors->add($here.'.stage', 'Stade de mesure inconnu.');
                $valid = false;
            }
            $eye = $item['eye'] ?? null;
            if (! is_string($eye) || ! in_array($eye, self::EYES, true)) {
                $errors->add($here.'.eye', 'Œil inconnu : œil droit (OD) ou œil gauche (OG).');
                $valid = false;
            }
            if ($valid) {
                $pair = $stage.'|'.$eye;
                if (isset($pairs[$pair])) {
                    $errors->add($here.'.eye', 'Cette mesure figure déjà : une seule ligne par stade et par œil.');
                    $valid = false;
                } else {
                    $pairs[$pair] = true;
                }
            }

            $sphere = Measures::diopter($item['sphere'] ?? null, $here.'.sphere', $errors);
            $cylinder = Measures::diopter($item['cylinder'] ?? null, $here.'.cylinder', $errors);
            $axis = Measures::axis($item['axis'] ?? null, $here.'.axis', $errors);
            $addition = Measures::diopter($item['addition'] ?? null, $here.'.addition', $errors);
            // Une valeur fournie mais refusée n'a pas de forme canonique : son erreur est déjà signalée.
            $measureInvalid = ($sphere === null && ($item['sphere'] ?? null) !== null)
                || ($cylinder === null && ($item['cylinder'] ?? null) !== null)
                || ($axis === null && ($item['axis'] ?? null) !== null)
                || ($addition === null && ($item['addition'] ?? null) !== null);
            if ($measureInvalid) {
                $valid = false;
            }

            if ($addition !== null && $stage !== 'SUBJECTIVE') {
                $errors->add($here.'.addition', 'L\'addition n\'existe que pour la réfraction subjective.');
                $valid = false;
            }
            if (! $measureInvalid && $sphere === null && $cylinder === null && $axis === null && $addition === null) {
                $errors->add($here, 'Cette ligne ne contient aucune mesure : retirez-la (un œil non mesuré n\'a pas de ligne).');
                $valid = false;
            }

            if ($valid) {
                $rows[] = [
                    'id' => (string) $id,
                    'stage' => (string) $stage,
                    'eye' => (string) $eye,
                    'sphere' => Measures::canonical($sphere),
                    'cylinder' => Measures::canonical($cylinder),
                    'axis' => $axis,
                    'addition' => Measures::canonical($addition),
                ];
            }
        }

        return $rows;
    }
}
