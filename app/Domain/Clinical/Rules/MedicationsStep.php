<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\Ulid;

/**
 * Rubrique MEDICATIONS (modèle DP §9 ; fichier 02, « Médicaments et orientation ») : plusieurs lignes de
 * médicaments en SAISIE LIBRE CONTRÔLÉE (aucune base de médicaments n'est fournie ni inventée).
 *
 * Règles (jamais corrigées en silence) :
 *  - une ligne : nom (1 à 180 caractères) et forme (collyre, pommade, comprimé, autre) obligatoires ; « Autre »
 *    exige la précision de la forme, qui n'est admise que pour « Autre » ;
 *  - dosage / concentration, fréquence, durée en texte et instructions sont facultatifs et conservés tels que
 *    saisis : AUCUNE posologie, fréquence ni durée n'est déduite ou complétée ;
 *  - la durée structurée (nombre et unité jour / semaine / mois) va par paire : les deux ou aucune (CHECK de la
 *    base), le nombre est strictement positif ; elle sert au calcul d'un contrôle, choisi par le professionnel ;
 *  - la position d'une ligne est son rang dans la liste (1, 2, …) : elle n'est jamais saisie ; l'ordre est conservé ;
 *  - un médicament peut référencer le catalogue du cabinet (`catalog_item_id`, qui doit exister) ou rester en texte
 *    libre. Prescrire ne crée aucune vente et ne décrémente aucun stock.
 */
final class MedicationsStep
{
    public const FORMS = ['DROPS', 'OINTMENT', 'TABLET', 'OTHER'];

    public const DURATION_UNITS = ['DAY', 'WEEK', 'MONTH'];

    public const ORDERS_MAX = 20;

    public const DURATION_VALUE_MAX = 4294967295;

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, true>  $knownCatalogIds  identifiants d'articles du catalogue du cabinet parmi ceux fournis
     * @return array{orders: list<array{id: string, catalog_item_id: string|null, name: string, form: string, form_other: string|null, strength: string|null, dosage: string|null, frequency: string|null, duration_text: string|null, duration_value: int|null, duration_unit: string|null, instructions: string|null}>}
     */
    public static function normalize(array $data, array $knownCatalogIds, FieldErrors $errors, string $path = 'step.data'): array
    {
        $items = $data['orders'] ?? [];
        $at = $path.'.orders';
        if (! is_array($items) || ! array_is_list($items)) {
            $errors->add($at, 'Une liste est attendue.');

            return ['orders' => []];
        }
        if (count($items) > self::ORDERS_MAX) {
            $errors->add($at, 'Pas plus de '.self::ORDERS_MAX.' médicaments dans une consultation.');
        }

        $rows = [];
        $ids = [];
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

            $catalogId = $item['catalog_item_id'] ?? null;
            if ($catalogId !== null) {
                if (! is_string($catalogId) || ! Ulid::isValid($catalogId)) {
                    $errors->add($here.'.catalog_item_id', 'Identifiant d\'article invalide.');
                    $valid = false;
                } elseif (! isset($knownCatalogIds[$catalogId])) {
                    $errors->add($here.'.catalog_item_id', 'Article inconnu du catalogue du cabinet : saisissez le médicament en texte libre.');
                    $valid = false;
                }
            }

            $name = ClinicalInput::line($item['name'] ?? null, $here.'.name', $errors, 180);
            if ($name === null) {
                if (ClinicalInput::isBlank($item['name'] ?? null)) {
                    $errors->add($here.'.name', 'Le nom du médicament est obligatoire.');
                }
                $valid = false;
            }

            $form = $item['form'] ?? null;
            if (! is_string($form) || ! in_array($form, self::FORMS, true)) {
                $errors->add($here.'.form', 'Forme inconnue : collyre, pommade, comprimé ou autre.');
                $valid = false;
                $form = null;
            }
            $formOther = ClinicalInput::line($item['form_other'] ?? null, $here.'.form_other', $errors, 100);
            if ($form === 'OTHER' && $formOther === null) {
                if (ClinicalInput::isBlank($item['form_other'] ?? null)) {
                    $errors->add($here.'.form_other', 'Précisez la forme « Autre ».');
                }
                $valid = false;
            } elseif ($form !== null && $form !== 'OTHER' && $formOther !== null) {
                $errors->add($here.'.form_other', 'Une précision n\'est admise que pour la forme « Autre ».');
                $valid = false;
            } elseif ($formOther === null && ! ClinicalInput::isBlank($item['form_other'] ?? null)) {
                $valid = false;
            }

            $strength = ClinicalInput::line($item['strength'] ?? null, $here.'.strength', $errors, 100);
            $dosage = ClinicalInput::text($item['dosage'] ?? null, $here.'.dosage', $errors);
            $frequency = ClinicalInput::line($item['frequency'] ?? null, $here.'.frequency', $errors, 180);
            $durationText = ClinicalInput::line($item['duration_text'] ?? null, $here.'.duration_text', $errors, 180);
            $instructions = ClinicalInput::text($item['instructions'] ?? null, $here.'.instructions', $errors);
            foreach ([['strength', $strength], ['dosage', $dosage], ['frequency', $frequency], ['duration_text', $durationText], ['instructions', $instructions]] as [$field, $value]) {
                if ($value === null && ! ClinicalInput::isBlank($item[$field] ?? null)) {
                    $valid = false;
                }
            }

            $durationValue = $item['duration_value'] ?? null;
            $durationUnit = $item['duration_unit'] ?? null;
            if ($durationValue !== null && (! is_int($durationValue) || $durationValue < 1 || $durationValue > self::DURATION_VALUE_MAX)) {
                $errors->add($here.'.duration_value', 'La durée est un nombre entier strictement positif.');
                $valid = false;
                $durationValue = null;
            }
            if ($durationUnit !== null && (! is_string($durationUnit) || ! in_array($durationUnit, self::DURATION_UNITS, true))) {
                $errors->add($here.'.duration_unit', 'Unité de durée inconnue : jour, semaine ou mois.');
                $valid = false;
                $durationUnit = null;
            }
            if (($durationValue === null) !== ($durationUnit === null) && $valid) {
                $errors->add($here.'.'.($durationValue === null ? 'duration_value' : 'duration_unit'), 'La durée structurée comprend le nombre ET l\'unité, ou aucun des deux.');
                $valid = false;
            }

            if ($valid) {
                $rows[] = [
                    'id' => (string) $id,
                    'catalog_item_id' => is_string($catalogId) ? $catalogId : null,
                    'name' => (string) $name,
                    'form' => (string) $form,
                    'form_other' => $formOther,
                    'strength' => $strength,
                    'dosage' => $dosage,
                    'frequency' => $frequency,
                    'duration_text' => $durationText,
                    'duration_value' => is_int($durationValue) ? $durationValue : null,
                    'duration_unit' => is_string($durationUnit) ? $durationUnit : null,
                    'instructions' => $instructions,
                ];
            }
        }

        return ['orders' => $rows];
    }
}
