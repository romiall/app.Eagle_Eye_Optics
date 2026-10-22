<?php

namespace App\Domain\Clinical;

use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\FieldErrors;
use InvalidArgumentException;
use stdClass;

/**
 * Point d'entrée UNIQUE des sept rubriques du dossier pour les actions de domaine (enregistrement d'une rubrique,
 * correction, finalisation) : il oriente chaque rubrique vers son moteur — narratives (`NarrativeSteps`), mesurées
 * (`MeasuredSteps`) ou médicaments (`MedicationSteps`) — qui partagent la même sémantique de REMPLACEMENT COMPLET.
 *
 * Un moteur ne fait jamais confiance à la charge : `normalize` valide l'état résultant (toutes les erreurs, par
 * champ, avant la première écriture), `rejectForeignIds` refuse les identifiants de ligne déjà pris ailleurs,
 * `isUnchanged` reconnaît un enregistrement sans effet, `write` écrit les lignes enfants et rend les colonnes de
 * `consultations` à mettre à jour.
 */
final class ClinicalStepStore
{
    public function __construct(
        private readonly NarrativeSteps $narrative,
        private readonly MeasuredSteps $measured,
        private readonly MedicationSteps $medications,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(stdClass $consultation, string $step, array $data, FieldErrors $errors): array
    {
        return match ($step) {
            ClinicalSteps::ANAMNESIS, ClinicalSteps::HISTORIES, ClinicalSteps::CONCLUSION => $this->narrative->normalize($consultation, $step, $data, $errors),
            ClinicalSteps::VISUAL_ACUITY, ClinicalSteps::REFRACTION, ClinicalSteps::CENTERING => $this->measured->normalize($consultation, $step, $data, $errors),
            ClinicalSteps::MEDICATIONS => $this->medications->normalize($consultation, $data, $errors),
            default => throw new InvalidArgumentException('Rubrique inconnue : '.$step),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function rejectForeignIds(stdClass $consultation, string $step, array $data, FieldErrors $errors, string $path = 'step.data'): void
    {
        match ($step) {
            ClinicalSteps::ANAMNESIS, ClinicalSteps::HISTORIES, ClinicalSteps::CONCLUSION => $this->narrative->rejectForeignIds($consultation, $step, $data, $errors, $path),
            ClinicalSteps::VISUAL_ACUITY, ClinicalSteps::REFRACTION, ClinicalSteps::CENTERING => $this->measured->rejectForeignIds($consultation, $step, $data, $errors, $path),
            ClinicalSteps::MEDICATIONS => $this->medications->rejectForeignIds($consultation, $data, $errors, $path),
            default => throw new InvalidArgumentException('Rubrique inconnue : '.$step),
        };
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function isUnchanged(stdClass $consultation, string $step, array $normalized): bool
    {
        return match ($step) {
            ClinicalSteps::ANAMNESIS, ClinicalSteps::HISTORIES, ClinicalSteps::CONCLUSION => $this->narrative->isUnchanged($consultation, $step, $normalized),
            ClinicalSteps::VISUAL_ACUITY, ClinicalSteps::REFRACTION, ClinicalSteps::CENTERING => $this->measured->isUnchanged($consultation, $step, $normalized),
            ClinicalSteps::MEDICATIONS => $this->medications->isUnchanged($consultation, $normalized),
            default => throw new InvalidArgumentException('Rubrique inconnue : '.$step),
        };
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public function write(stdClass $consultation, string $step, array $normalized, string $now): array
    {
        switch ($step) {
            case ClinicalSteps::ANAMNESIS:
            case ClinicalSteps::HISTORIES:
            case ClinicalSteps::CONCLUSION:
                return $this->narrative->write($consultation, $step, $normalized, $now);
            case ClinicalSteps::VISUAL_ACUITY:
            case ClinicalSteps::REFRACTION:
            case ClinicalSteps::CENTERING:
                return $this->measured->write($consultation, $step, $normalized, $now);
            case ClinicalSteps::MEDICATIONS:
                $this->medications->write($consultation, $normalized, $now);

                return [];
            default:
                throw new InvalidArgumentException('Rubrique inconnue : '.$step);
        }
    }
}
