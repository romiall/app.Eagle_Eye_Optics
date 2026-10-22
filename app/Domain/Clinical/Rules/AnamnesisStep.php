<?php

namespace App\Domain\Clinical\Rules;

/**
 * Rubrique ANAMNESIS (modèle DP §4) : motifs de consultation en sélection multiple, symptômes en texte libre,
 * port de lunettes oui/non/non renseigné, et, SEULEMENT si le patient porte des lunettes, la durée de port (texte)
 * et la disponibilité de l'ancienne correction (oui/non/non renseigné).
 *
 * Une durée ou une ancienne correction renseignées alors que le patient ne porte pas (ou pas encore dit qu'il
 * porte) des lunettes sont REFUSÉES (422), jamais effacées en silence : perdre une saisie clinique sans le dire
 * est pire que la signaler. Aucune valeur n'est déduite : une réponse non posée reste null.
 */
final class AnamnesisStep
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $complaintCodes  codes actifs du groupe COMPLAINT
     * @param  array<string, string>  $existingComplaints  motifs déjà enregistrés (identifiant → code), ré-enregistrables même désactivés depuis
     * @return array{symptoms: string|null, wears_glasses: bool|null, glasses_duration: string|null, previous_correction_available: bool|null, complaints: list<array{id: string, term_code: string, other_text: string|null}>}
     */
    public static function normalize(array $data, array $complaintCodes, FieldErrors $errors, string $path = 'step.data', array $existingComplaints = []): array
    {
        $symptoms = ClinicalInput::text($data['symptoms'] ?? null, $path.'.symptoms', $errors);
        $wears = ClinicalInput::triState($data['wears_glasses'] ?? null, $path.'.wears_glasses', $errors);
        $duration = ClinicalInput::text($data['glasses_duration'] ?? null, $path.'.glasses_duration', $errors);
        $previous = ClinicalInput::triState($data['previous_correction_available'] ?? null, $path.'.previous_correction_available', $errors);

        if ($wears !== true) {
            if ($duration !== null) {
                $errors->add($path.'.glasses_duration', 'La durée de port n\'a de sens que si le patient porte des lunettes : répondez « oui » ou effacez-la.');
            }
            if ($previous !== null) {
                $errors->add($path.'.previous_correction_available', 'L\'ancienne correction n\'a de sens que si le patient porte des lunettes : répondez « oui » ou effacez-la.');
            }
        }

        $usedIds = [];
        $complaints = TermList::normalize($data['complaints'] ?? [], $complaintCodes, $path.'.complaints', $errors, $usedIds, $existingComplaints);

        return [
            'symptoms' => $symptoms,
            'wears_glasses' => $wears,
            'glasses_duration' => $duration,
            'previous_correction_available' => $previous,
            'complaints' => $complaints,
        ];
    }
}
