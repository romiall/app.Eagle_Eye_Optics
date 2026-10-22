<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\Ulid;

/**
 * Rubrique VISUAL_ACUITY (modèle DP §6 et §7 ; fichier 02, « Examens et réfraction ») : les acuités de loin et de
 * près, sans et avec correction, pour OD, OG et OU (douze valeurs, toutes facultatives) et l'acuité après
 * correction OD, OG, OU (trois valeurs, sans distinction loin/près).
 *
 * Règles (jamais corrigées en silence) :
 *  - une ligne = une valeur MESURÉE : une valeur non mesurée n'a pas de ligne (absence = non mesuré, jamais zéro) ;
 *  - préliminaire : distance loin ou près, sans ou avec correction ; après réfraction : distance « non précisée » et
 *    avec correction (CHECK de la base) ; une seule ligne par (contexte, distance, correction, œil) ;
 *  - l'échelle est celle du cabinet (`visual_acuity_scales`, active) ; une échelle désactivée depuis reste
 *    ré-enregistrable pour une ligne qui l'utilise déjà ;
 *  - la valeur lue (`raw_value`, 1 à 100 caractères) est CONSERVÉE telle que saisie : « 10/10 », « 6/6 », « P2 »,
 *    « CLD », « 0 » (zéro est une valeur) ;
 *  - `normalized_value` (valeur numérique comparable) n'est JAMAIS calculé ni accepté : aucune conversion entre
 *    échelles n'est validée (Q06). Il reste null ; seule une valeur déjà enregistrée (insérée hors de ce chemin) est
 *    conservée telle quelle.
 */
final class VisualAcuityStep
{
    public const CONTEXTS = ['PRELIMINARY', 'POST_REFRACTION'];

    public const DISTANCES = ['FAR', 'NEAR', 'UNSPECIFIED'];

    public const CORRECTIONS = ['WITHOUT', 'WITH'];

    public const EYES = ['OD', 'OG', 'OU'];

    public const RAW_MAX = 100;

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $activeScaleIds  échelles actives du cabinet
     * @param  list<string>  $knownScaleIds  toutes les échelles du cabinet (actives ou non)
     * @param  array<string, array{scale_id: string, normalized_value: string|null}>  $existing  lignes déjà enregistrées (identifiant → échelle, valeur normalisée)
     * @return list<array{id: string, context: string, distance: string, correction: string, eye: string, scale_id: string, raw_value: string, normalized_value: string|null}>
     */
    public static function normalize(array $data, array $activeScaleIds, array $knownScaleIds, array $existing, FieldErrors $errors, string $path = 'step.data'): array
    {
        $items = $data['acuities'] ?? [];
        $at = $path.'.acuities';
        if (! is_array($items) || ! array_is_list($items)) {
            $errors->add($at, 'Une liste est attendue.');

            return [];
        }

        $rows = [];
        $ids = [];
        $combinations = [];
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

            $context = $item['context'] ?? null;
            if (! is_string($context) || ! in_array($context, self::CONTEXTS, true)) {
                $errors->add($here.'.context', 'Contexte d\'acuité inconnu.');
                $valid = false;
            }
            $distance = $item['distance'] ?? null;
            if (! is_string($distance) || ! in_array($distance, self::DISTANCES, true)) {
                $errors->add($here.'.distance', 'Distance inconnue.');
                $valid = false;
            }
            $correction = $item['correction'] ?? null;
            if (! is_string($correction) || ! in_array($correction, self::CORRECTIONS, true)) {
                $errors->add($here.'.correction', 'Correction inconnue.');
                $valid = false;
            }
            $eye = $item['eye'] ?? null;
            if (! is_string($eye) || ! in_array($eye, self::EYES, true)) {
                $errors->add($here.'.eye', 'Œil inconnu.');
                $valid = false;
            }

            if ($valid) {
                if ($context === 'PRELIMINARY' && ! in_array($distance, ['FAR', 'NEAR'], true)) {
                    $errors->add($here.'.distance', 'Une acuité préliminaire est de loin ou de près.');
                    $valid = false;
                } elseif ($context === 'POST_REFRACTION' && $distance !== 'UNSPECIFIED') {
                    $errors->add($here.'.distance', 'L\'acuité après correction ne distingue pas loin et près.');
                    $valid = false;
                }
                if ($context === 'POST_REFRACTION' && $correction !== 'WITH') {
                    $errors->add($here.'.correction', 'L\'acuité après correction est toujours « avec correction ».');
                    $valid = false;
                }
            }
            if ($valid) {
                $key = $context.'|'.$distance.'|'.$correction.'|'.$eye;
                if (isset($combinations[$key])) {
                    $errors->add($here.'.eye', 'Cette acuité figure déjà : une seule valeur par œil, distance et correction.');
                    $valid = false;
                } else {
                    $combinations[$key] = true;
                }
            }

            $scale = $item['scale_id'] ?? null;
            if (! is_string($scale) || ! Ulid::isValid($scale)) {
                $errors->add($here.'.scale_id', 'Choisissez l\'échelle utilisée.');
                $valid = false;
            } else {
                $stored = is_string($id) ? ($existing[$id]['scale_id'] ?? null) : null;
                $usable = in_array($scale, $activeScaleIds, true) || ($stored === $scale && in_array($scale, $knownScaleIds, true));
                if (! $usable) {
                    $errors->add($here.'.scale_id', 'Échelle inconnue ou inactive : choisissez une échelle du cabinet.');
                    $valid = false;
                }
            }

            $raw = $item['raw_value'] ?? null;
            $rawValue = ClinicalInput::line($raw, $here.'.raw_value', $errors, self::RAW_MAX);
            if ($rawValue === null) {
                // Un texte invalide a déjà son message ; une valeur absente ou blanche n'est pas une acuité mesurée.
                if (ClinicalInput::isBlank($raw)) {
                    $errors->add($here.'.raw_value', 'La valeur lue est obligatoire : une acuité non mesurée n\'a pas de ligne.');
                }
                $valid = false;
            }

            $normalized = $item['normalized_value'] ?? null;
            if ($normalized !== null) {
                $storedNormalized = is_string($id) ? ($existing[$id]['normalized_value'] ?? null) : null;
                if (! is_string($normalized) || $normalized !== $storedNormalized) {
                    $errors->add($here.'.normalized_value', 'Aucune conversion entre échelles n\'est validée : laissez cette valeur vide, la valeur lue est conservée telle quelle.');
                    $valid = false;
                }
            }

            if ($valid) {
                $rows[] = [
                    'id' => (string) $id,
                    'context' => (string) $context,
                    'distance' => (string) $distance,
                    'correction' => (string) $correction,
                    'eye' => (string) $eye,
                    'scale_id' => (string) $scale,
                    'raw_value' => (string) $rawValue,
                    'normalized_value' => is_string($normalized) ? $normalized : null,
                ];
            }
        }

        return $rows;
    }
}
