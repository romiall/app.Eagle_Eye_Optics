<?php

namespace App\Domain\Clinical\Rules;

/**
 * Rubriques du dossier de consultation (contrat `clinical-steps.json#StepName`, modèle DP) et états du travail
 * clinique (`consultations.status`).
 *
 * Les sept rubriques sont ouvertes à l'enregistrement : narratives (C01), mesurées (C02 : acuités, réfraction,
 * centrage) et médicaments (C04, DP §9). L'ordre de `ALL` est celui du dossier ; il est repris par la lecture,
 * le snapshot d'une révision et l'écran.
 */
final class ClinicalSteps
{
    public const ANAMNESIS = 'ANAMNESIS';

    public const HISTORIES = 'HISTORIES';

    public const VISUAL_ACUITY = 'VISUAL_ACUITY';

    public const REFRACTION = 'REFRACTION';

    public const CENTERING = 'CENTERING';

    public const MEDICATIONS = 'MEDICATIONS';

    public const CONCLUSION = 'CONCLUSION';

    /** Les sept rubriques, dans l'ordre du dossier. */
    public const ALL = [self::ANAMNESIS, self::HISTORIES, self::VISUAL_ACUITY, self::REFRACTION, self::CENTERING, self::MEDICATIONS, self::CONCLUSION];

    /** Rubriques dont l'enregistrement est ouvert (toutes, depuis C02 et C04). */
    public const OPEN = self::ALL;

    public const DRAFT = 'DRAFT';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const COMPLETED = 'COMPLETED';
}
