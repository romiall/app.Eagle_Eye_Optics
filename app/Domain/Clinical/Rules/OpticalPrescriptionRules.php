<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;

/**
 * Prescription optique (EE009, EE014 ; fichier 02 ; DP §8) : distincte des mesures objectives et subjectives,
 * éditable seulement tant qu'elle est brouillon, immuable une fois validée (triggers de la base).
 *
 * Deux niveaux de règles :
 *  - `normalize` : cohérence d'un BROUILLON (structure, codes du référentiel, nombres exacts, pas de valeur
 *    déduite). Un brouillon peut être incomplet ;
 *  - `readyToValidate` : exigences de la VALIDATION (fichier 21, « Valider prescription » ; fichier 02) : pour chaque
 *    œil, des valeurs explicitement saisies OU la mention d'absence de correction ; un cylindre non nul exige un axe.
 *
 * Aucune mesure n'est copiée depuis la réfraction par le serveur : l'écran envoie les valeurs que le professionnel
 * a choisi de recopier (bouton explicite et confirmation), puis la prescription s'édite indépendamment de l'examen.
 * Aucune valeur n'est déduite : la DP monoculaire n'est jamais fabriquée à partir du total, le cylindre positif
 * n'est jamais transposé, zéro reste une valeur distincte de « non saisi ».
 */
final class OpticalPrescriptionRules
{
    public const ORIGINS = ['INTERNAL', 'EXTERNAL'];

    public const EYES = ['OD', 'OG'];

    /** Indices proposés par le fichier 02 ; « autre » = indice null avec précision libre (`lens_index_other`). */
    public const LENS_INDEXES = ['1.50', '1.56', '1.59', '1.60', '1.61', '1.67', '1.74'];

    public const TREATMENTS_MAX = 20;

    /** Champs de contenu modifiables d'un brouillon (la requête de modification n'en admet pas d'autres). */
    public const EDITABLE = [
        'external_prescriber', 'external_prescribed_on', 'external_file_id',
        'usage_code', 'usage_other', 'lens_type_code', 'lens_type_other', 'lens_index', 'lens_index_other',
        'pd_total', 'pd_od', 'pd_og', 'pd_inconsistency_reason', 'notes', 'eyes', 'treatments',
    ];

    /**
     * @param  array<array-key, mixed>  $data  contenu de la prescription (forme de la charge `CreatePrescription`)
     * @param  array{usage: list<string>, lens_type: list<string>, treatment: list<string>}  $codes  codes ACTIFS des référentiels
     * @param  array{usage_code: string|null, lens_type_code: string|null, treatments: array<string, string>}  $stored  valeurs déjà enregistrées (brouillon en cours de modification)
     * @return array<string, mixed>
     */
    public static function normalize(array $data, string $origin, array $codes, array $stored, string $today, FieldErrors $errors): array
    {
        $external = $origin === 'EXTERNAL';

        // --- Source ----------------------------------------------------------------------------------
        $prescriber = ClinicalInput::line($data['external_prescriber'] ?? null, 'external_prescriber', $errors, 180);
        $prescribedOn = self::civilDate($data['external_prescribed_on'] ?? null, 'external_prescribed_on', $errors);
        $fileId = $data['external_file_id'] ?? null;
        if ($fileId !== null && (! is_string($fileId) || ! Ulid::isValid($fileId))) {
            $errors->add('external_file_id', 'Identifiant de fichier invalide.');
            $fileId = null;
        }
        if ($external) {
            if ($prescriber === null && ClinicalInput::isBlank($data['external_prescriber'] ?? null)) {
                $errors->add('external_prescriber', 'Le prescripteur de l\'ordonnance externe est obligatoire.');
            }
            if ($prescribedOn === null && ClinicalInput::isBlank($data['external_prescribed_on'] ?? null)) {
                $errors->add('external_prescribed_on', 'La date de l\'ordonnance externe est obligatoire.');
            } elseif ($prescribedOn !== null && $prescribedOn > $today) {
                $errors->add('external_prescribed_on', 'La date de l\'ordonnance ne peut pas être dans le futur.');
            }
        } else {
            foreach (['external_prescriber' => $prescriber, 'external_prescribed_on' => $prescribedOn, 'external_file_id' => $fileId] as $field => $value) {
                if ($value !== null) {
                    $errors->add($field, 'Ce champ n\'a de sens que pour une ordonnance externe.');
                }
            }
        }

        // --- Usage, type de verre, indice ----------------------------------------------------------
        [$usageCode, $usageOther] = self::codedChoice($data, 'usage_code', 'usage_other', $codes['usage'], $stored['usage_code'], 'Usage', $errors);
        [$typeCode, $typeOther] = self::codedChoice($data, 'lens_type_code', 'lens_type_other', $codes['lens_type'], $stored['lens_type_code'], 'Type de verre', $errors);

        $index = $data['lens_index'] ?? null;
        if ($index !== null && (! is_string($index) || ! in_array($index, self::LENS_INDEXES, true))) {
            $errors->add('lens_index', 'Indice inconnu : choisissez un indice de la liste, ou « autre » avec une précision.');
            $index = null;
        }
        $indexOther = ClinicalInput::line($data['lens_index_other'] ?? null, 'lens_index_other', $errors);
        if ($index !== null && $indexOther !== null) {
            $errors->add('lens_index_other', 'Une précision d\'indice n\'a de sens que pour « autre » : effacez l\'indice choisi ou la précision.');
            $indexOther = null;
        }

        // --- Centrage ------------------------------------------------------------------------------
        $pd = [];
        foreach (['pd_total', 'pd_od', 'pd_og'] as $field) {
            $raw = $data[$field] ?? null;
            $value = Measures::diopter($raw, $field, $errors);
            if ($value !== null && $value->units <= 0) {
                $errors->add($field, 'L\'écart pupillaire doit être strictement positif (en millimètres).');
                $value = null;
            }
            $pd[$field] = $value;
        }
        $pdReason = ClinicalInput::text($data['pd_inconsistency_reason'] ?? null, 'pd_inconsistency_reason', $errors);
        if (CenteringStep::mismatch($pd['pd_total'], $pd['pd_od'], $pd['pd_og']) && $pdReason === null && ClinicalInput::isBlank($data['pd_inconsistency_reason'] ?? null)) {
            $errors->add('pd_inconsistency_reason', 'La somme des écarts OD et OG diffère de l\'écart total : corrigez une valeur ou justifiez l\'écart.');
        }
        if ($pdReason !== null && $pd['pd_total'] === null && $pd['pd_od'] === null && $pd['pd_og'] === null) {
            $errors->add('pd_inconsistency_reason', 'Une justification n\'a de sens qu\'avec un écart pupillaire saisi.');
        }

        $notes = ClinicalInput::text($data['notes'] ?? null, 'notes', $errors);

        return [
            'origin' => $origin,
            'external_prescriber' => $prescriber,
            'external_prescribed_on' => $prescribedOn,
            'external_file_id' => is_string($fileId) ? $fileId : null,
            'usage_code' => $usageCode,
            'usage_other' => $usageOther,
            'lens_type_code' => $typeCode,
            'lens_type_other' => $typeOther,
            'lens_index' => is_string($index) ? $index : null,
            'lens_index_other' => $indexOther,
            'pd_total' => $pd['pd_total']?->canonical(),
            'pd_od' => $pd['pd_od']?->canonical(),
            'pd_og' => $pd['pd_og']?->canonical(),
            'pd_inconsistency_reason' => $pdReason,
            'notes' => $notes,
            'eyes' => self::eyes($data['eyes'] ?? null, $errors),
            'treatments' => self::treatments($data['treatments'] ?? [], $codes['treatment'], $stored['treatments'], $errors),
        ];
    }

    /**
     * Exigences de la validation (fichier 02 : « Une prescription imprimée requiert des valeurs explicitement saisies
     * ou la mention de l'absence de correction par œil concerné ») sur un contenu déjà normalisé.
     *
     * @param  array<string, mixed>  $normalized
     */
    public static function readyToValidate(array $normalized, FieldErrors $errors): void
    {
        foreach (self::eyeRows($normalized['eyes'] ?? []) as $index => $eye) {
            $values = [$eye['sphere'], $eye['cylinder'], $eye['axis'], $eye['addition']];
            $explicit = count(array_filter($values, static fn (mixed $value): bool => $value !== null)) > 0;
            if (! $eye['no_correction'] && ! $explicit) {
                $errors->add('eyes.'.$index.'.no_correction', 'Œil '.$eye['eye'].' : saisissez les valeurs, ou cochez « Aucune correction » pour cet œil.');
            }
            $cylinder = $eye['cylinder'] === null ? null : FixedDecimal::fromString($eye['cylinder'], 2);
            if ($cylinder !== null && ! $cylinder->isZero() && $eye['axis'] === null) {
                $errors->add('eyes.'.$index.'.axis', 'Œil '.$eye['eye'].' : un cylindre non nul exige un axe (0 à 180 degrés).');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $active
     * @return array{0: string|null, 1: string|null} code, précision
     */
    private static function codedChoice(array $data, string $codeField, string $otherField, array $active, ?string $stored, string $label, FieldErrors $errors): array
    {
        $code = $data[$codeField] ?? null;
        if ($code !== null && (! is_string($code) || (! in_array($code, $active, true) && $code !== $stored))) {
            $errors->add($codeField, $label.' : valeur inconnue, inactive ou d\'un autre référentiel.');
            $code = null;
        }
        $other = ClinicalInput::text($data[$otherField] ?? null, $otherField, $errors);
        if ($code === 'OTHER' && $other === null) {
            if (ClinicalInput::isBlank($data[$otherField] ?? null)) {
                $errors->add($otherField, $label.' : précisez la valeur « Autre ».');
            }
        } elseif ($code !== 'OTHER' && $other !== null) {
            $errors->add($otherField, $label.' : une précision n\'est admise que pour « Autre ».');
            $other = null;
        }

        return [is_string($code) ? $code : null, $other];
    }

    private static function civilDate(mixed $value, string $field, FieldErrors $errors): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $errors->add($field, 'Une date AAAA-MM-JJ est attendue.');

            return null;
        }
        try {
            return CivilDate::fromString($value, $field)->toString();
        } catch (RuleViolation $violation) {
            $errors->add($field, $violation->getMessage());

            return null;
        }
    }

    /**
     * OD puis OG, exactement une ligne chacun.
     *
     * @return list<array{id: string, eye: string, no_correction: bool, sphere: string|null, cylinder: string|null, axis: int|null, addition: string|null, fitting_height: string|null}>
     */
    private static function eyes(mixed $items, FieldErrors $errors): array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) !== 2) {
            $errors->add('eyes', 'Les deux yeux (OD et OG) sont attendus, une ligne chacun.');

            return [];
        }

        $byEye = [];
        $ids = [];
        foreach ($items as $index => $item) {
            $at = 'eyes.'.$index;
            if (! is_array($item)) {
                $errors->add($at, 'Un objet est attendu.');

                continue;
            }
            $valid = true;

            $id = $item['id'] ?? null;
            if (! is_string($id) || ! Ulid::isValid($id)) {
                $errors->add($at.'.id', 'Identifiant invalide.');
                $valid = false;
            } elseif (isset($ids[$id])) {
                $errors->add($at.'.id', 'Cet identifiant figure déjà dans la prescription.');
                $valid = false;
            } else {
                $ids[$id] = true;
            }

            $eye = $item['eye'] ?? null;
            if (! is_string($eye) || ! in_array($eye, self::EYES, true)) {
                $errors->add($at.'.eye', 'Œil inconnu : OD ou OG.');
                $valid = false;
            } elseif (isset($byEye[$eye])) {
                $errors->add($at.'.eye', 'Cet œil figure déjà : une ligne par œil.');
                $valid = false;
            }

            $none = $item['no_correction'] ?? null;
            if (! is_bool($none)) {
                $errors->add($at.'.no_correction', 'Répondez oui ou non.');
                $valid = false;
                $none = false;
            }
            $sphere = Measures::diopter($item['sphere'] ?? null, $at.'.sphere', $errors);
            $cylinder = Measures::diopter($item['cylinder'] ?? null, $at.'.cylinder', $errors);
            $axis = Measures::axis($item['axis'] ?? null, $at.'.axis', $errors);
            $addition = Measures::diopter($item['addition'] ?? null, $at.'.addition', $errors);
            $height = Measures::diopter($item['fitting_height'] ?? null, $at.'.fitting_height', $errors);
            if ($height !== null && $height->units <= 0) {
                $errors->add($at.'.fitting_height', 'La hauteur de montage doit être strictement positive (en millimètres).');
                $height = null;
            }
            foreach (['sphere', 'cylinder', 'axis', 'addition', 'fitting_height'] as $field) {
                $parsed = ['sphere' => $sphere, 'cylinder' => $cylinder, 'axis' => $axis, 'addition' => $addition, 'fitting_height' => $height][$field];
                if ($parsed === null && ($item[$field] ?? null) !== null) {
                    $valid = false;
                }
            }
            if ($none && ($sphere !== null || $cylinder !== null || $axis !== null || $addition !== null)) {
                $errors->add($at.'.no_correction', 'Une valeur est saisie pour cet œil : décochez « Aucune correction » ou effacez les valeurs.');
                $valid = false;
            }

            if ($valid) {
                $byEye[$eye] = [
                    'id' => (string) $id,
                    'eye' => $eye,
                    'no_correction' => $none,
                    'sphere' => $sphere?->canonical(),
                    'cylinder' => $cylinder?->canonical(),
                    'axis' => $axis,
                    'addition' => $addition?->canonical(),
                    'fitting_height' => $height?->canonical(),
                ];
            }
        }

        // Deux lignes valides d'yeux distincts : OD et OG chacun une fois.
        if (! isset($byEye['OD'], $byEye['OG'])) {
            return [];
        }

        return [$byEye['OD'], $byEye['OG']];
    }

    /**
     * @param  list<string>  $active
     * @param  array<string, string>  $stored
     * @return list<array{id: string, term_code: string, other_text: string|null}>
     */
    private static function treatments(mixed $items, array $active, array $stored, FieldErrors $errors): array
    {
        if (is_array($items) && count($items) > self::TREATMENTS_MAX) {
            $errors->add('treatments', 'Pas plus de '.self::TREATMENTS_MAX.' traitements.');
        }
        $used = [];

        return TermList::normalize($items, $active, 'treatments', $errors, $used, $stored);
    }

    /**
     * @return list<array{id: string, eye: string, no_correction: bool, sphere: string|null, cylinder: string|null, axis: int|null, addition: string|null, fitting_height: string|null}>
     */
    private static function eyeRows(mixed $eyes): array
    {
        if (! is_array($eyes)) {
            return [];
        }

        // Lignes déjà normalisées (`normalize`) ou relues de la base (`OpticalPrescriptions::view`) : un champ d'un
        // autre type compte comme non saisi, jamais comme une valeur.
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;
        $rows = [];
        foreach ($eyes as $eye) {
            if (! is_array($eye)) {
                continue;
            }
            $rows[] = [
                'id' => $text($eye['id'] ?? null) ?? '',
                'eye' => $text($eye['eye'] ?? null) ?? '',
                'no_correction' => ($eye['no_correction'] ?? null) === true,
                'sphere' => $text($eye['sphere'] ?? null),
                'cylinder' => $text($eye['cylinder'] ?? null),
                'axis' => is_int($eye['axis'] ?? null) ? $eye['axis'] : null,
                'addition' => $text($eye['addition'] ?? null),
                'fitting_height' => $text($eye['fitting_height'] ?? null),
            ];
        }

        return $rows;
    }
}
