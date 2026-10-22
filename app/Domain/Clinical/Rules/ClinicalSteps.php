<?php

namespace App\Domain\Clinical\Rules;

/**
 * Rubriques du dossier de consultation (contrat `clinical-steps.json#StepName`, modèle DP) et états du travail
 * clinique (`consultations.status`).
 *
 * Premier jalon (C01) : seules les rubriques narratives sont OUVERTES à l'enregistrement. Les trois autres
 * (acuités, réfraction, centrage) répondent « pas encore ouverte » jusqu'à C02, sans rien écrire.
 */
final class ClinicalSteps
{
    public const ANAMNESIS = 'ANAMNESIS';

    public const HISTORIES = 'HISTORIES';

    public const VISUAL_ACUITY = 'VISUAL_ACUITY';

    public const REFRACTION = 'REFRACTION';

    public const CENTERING = 'CENTERING';

    public const CONCLUSION = 'CONCLUSION';

    /** Les six rubriques, dans l'ordre du dossier. */
    public const ALL = [self::ANAMNESIS, self::HISTORIES, self::VISUAL_ACUITY, self::REFRACTION, self::CENTERING, self::CONCLUSION];

    /** Rubriques dont l'enregistrement est ouvert dans ce jalon. */
    public const OPEN = [self::ANAMNESIS, self::HISTORIES, self::CONCLUSION];

    public const DRAFT = 'DRAFT';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const COMPLETED = 'COMPLETED';
}
