<?php

namespace App\Domain\Clinical\Rules;

/**
 * Rubrique CONCLUSION (modèle DP §10) : conclusion en texte libre, orientation vers un ophtalmologiste
 * oui/non/non renseigné, niveau d'urgence et motifs d'orientation.
 *
 * - Orientation « oui » : le niveau est OBLIGATOIRE (contrainte de la base et règle métier) ; les motifs sont
 *   choisis parmi le référentiel (« Autre » exige un texte). Aucun motif n'est exigé à l'enregistrement.
 * - Orientation « non » ou non renseignée : ni niveau ni motifs. Ils sont REFUSÉS (422) et non effacés en
 *   silence : le professionnel qui change d'avis retire lui-même ce qu'il avait saisi.
 */
final class ConclusionStep
{
    public const URGENCIES = ['NOT_URGENT', 'RECOMMENDED', 'RAPID', 'EMERGENCY'];

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $reasonCodes  codes actifs du groupe REFERRAL_REASON
     * @return array{conclusion: string|null, referral: bool|null, referral_urgency: string|null, referral_reasons: list<array{id: string, term_code: string, other_text: string|null}>}
     */
    public static function normalize(array $data, array $reasonCodes, FieldErrors $errors, string $path = 'step.data'): array
    {
        $conclusion = ClinicalInput::text($data['conclusion'] ?? null, $path.'.conclusion', $errors);
        $referral = ClinicalInput::triState($data['referral'] ?? null, $path.'.referral', $errors);

        $urgency = $data['referral_urgency'] ?? null;
        $urgencyUnknown = $urgency !== null && (! is_string($urgency) || ! in_array($urgency, self::URGENCIES, true));
        if ($urgencyUnknown) {
            $errors->add($path.'.referral_urgency', 'Niveau d\'urgence inconnu.');
            $urgency = null;
        }

        $rawReasons = $data['referral_reasons'] ?? [];
        $usedIds = [];
        $reasons = TermList::normalize($rawReasons, $reasonCodes, $path.'.referral_reasons', $errors, $usedIds);

        if ($referral === true) {
            if ($urgency === null && ! $urgencyUnknown) {
                $errors->add($path.'.referral_urgency', 'Le niveau d\'urgence est obligatoire quand le patient est orienté.');
            }
        } else {
            if ($urgency !== null) {
                $errors->add($path.'.referral_urgency', 'Le niveau d\'urgence n\'a de sens que pour une orientation « oui » : effacez-le ou répondez « oui ».');
            }
            if (is_array($rawReasons) && $rawReasons !== []) {
                $errors->add($path.'.referral_reasons', 'Les motifs d\'orientation n\'ont de sens que pour une orientation « oui » : retirez-les ou répondez « oui ».');
            }
        }

        return [
            'conclusion' => $conclusion,
            'referral' => $referral,
            'referral_urgency' => $urgency,
            'referral_reasons' => $reasons,
        ];
    }
}
