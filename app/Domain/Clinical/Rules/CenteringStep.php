<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\FixedDecimal;

/**
 * Rubrique CENTERING (modèle DP §6 ; fichier 02) : écart pupillaire OBJECTIF mesuré (en millimètres), total et/ou
 * monoculaire OD / OG. Une seule ligne par consultation (`autorefractor_centering`).
 *
 * Règles (jamais corrigées en silence) :
 *  - chaque écart est strictement positif (CHECK de la base), 2 décimales, jamais arrondi ;
 *  - le système NE FABRIQUE PAS une DP monoculaire à partir du total, ni l'inverse : chaque valeur est celle qui a
 *    été saisie, les autres restent null ;
 *  - si le total ET les deux écarts monoculaires sont saisis et que OD + OG diffère du total au-delà de la tolérance,
 *    une JUSTIFICATION (`inconsistency_reason`) est obligatoire. La tolérance configurée par le cabinet n'existe
 *    pas encore (Q06) : hypothèse de départ = AUCUNE tolérance (`TOLERANCE_UNITS = 0`, toute différence se justifie).
 *    L'écran propose la somme OD + OG, sans l'écrire à la place de la personne ;
 *  - une justification sans écart saisi n'a pas de sens : refusée ;
 *  - un centrage sans aucune valeur n'a PAS de ligne : son identifiant est null (`centering_id` nullable, G6). Dès
 *    qu'une valeur est saisie, l'identifiant de ligne (ULID) est fourni par le poste et doit être celui de la ligne
 *    déjà enregistrée s'il y en a une.
 */
final class CenteringStep
{
    /** Tolérance entre DP totale et somme OD + OG, en centièmes de millimètre (hypothèse : 0, à valider Q06). */
    public const TOLERANCE_UNITS = 0;

    /**
     * Vrai si l'écart total ET les deux écarts monoculaires sont saisis et que OD + OG diffère du total au-delà de la
     * tolérance (aucun écart n'est jugé tant qu'une des trois valeurs manque : rien n'est fabriqué à partir du total).
     */
    public static function mismatch(?FixedDecimal $total, ?FixedDecimal $od, ?FixedDecimal $og): bool
    {
        return $total !== null && $od !== null && $og !== null && abs($od->plus($og)->minus($total)->units) > self::TOLERANCE_UNITS;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{centering_id: string|null, pd_total: string|null, pd_od: string|null, pd_og: string|null, inconsistency_reason: string|null}
     */
    public static function normalize(array $data, ?string $storedId, FieldErrors $errors, string $path = 'step.data'): array
    {
        $parsed = [];
        $anyInvalid = false;
        foreach (['pd_total', 'pd_od', 'pd_og'] as $field) {
            $raw = $data[$field] ?? null;
            $value = Measures::diopter($raw, $path.'.'.$field, $errors);
            if ($raw !== null && $value === null) {
                // Valeur fournie mais refusée par Measures : son erreur est déjà signalée.
                $anyInvalid = true;
            } elseif ($value !== null && $value->units <= 0) {
                $errors->add($path.'.'.$field, 'L\'écart pupillaire doit être strictement positif (en millimètres).');
                $anyInvalid = true;
                $value = null;
            }
            $parsed[$field] = $value;
        }

        $reason = ClinicalInput::text($data['inconsistency_reason'] ?? null, $path.'.inconsistency_reason', $errors);
        if ($reason === null && ! ClinicalInput::isBlank($data['inconsistency_reason'] ?? null)) {
            $anyInvalid = true;
        }

        $total = $parsed['pd_total'];
        $od = $parsed['pd_od'];
        $og = $parsed['pd_og'];
        $anyValue = $total !== null || $od !== null || $og !== null;

        if (! $anyInvalid && self::mismatch($total, $od, $og) && $reason === null) {
            $errors->add($path.'.inconsistency_reason', 'La somme des écarts OD et OG diffère de l\'écart total : corrigez une valeur ou justifiez l\'écart.');
        }
        if (! $anyInvalid && ! $anyValue && $reason !== null) {
            $errors->add($path.'.inconsistency_reason', 'Une justification n\'a de sens qu\'avec un écart pupillaire saisi : effacez-la ou saisissez la mesure.');
        }

        $id = $data['centering_id'] ?? null;
        $centeringId = null;
        if ($id !== null && (! is_string($id) || ! Ulid::isValid($id))) {
            $errors->add($path.'.centering_id', 'Identifiant invalide.');
        } elseif ($anyValue || $reason !== null) {
            if ($id === null) {
                $errors->add($path.'.centering_id', 'Un identifiant de ligne est obligatoire dès qu\'un écart est saisi (créé par le poste).');
            } elseif ($storedId !== null && $id !== $storedId) {
                $errors->add($path.'.centering_id', 'Cet identifiant n\'est pas celui du centrage déjà enregistré pour cette consultation.');
            } else {
                $centeringId = $id;
            }
        }

        return [
            'centering_id' => $centeringId,
            'pd_total' => $total?->canonical(),
            'pd_od' => $od?->canonical(),
            'pd_og' => $og?->canonical(),
            'inconsistency_reason' => $reason,
        ];
    }
}
