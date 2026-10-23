<?php

namespace App\Domain\Scheduling;

use App\Domain\Scheduling\Rules\FollowUpDates;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;

/**
 * Calcul d'un contrôle recommandé (S02, AT22 ; fichier 02 « Rendez-vous et contrôle ») : la date part de l'origine
 * EXPLICITEMENT choisie par le professionnel (consultation effective, début ou fin de traitement, date manuelle), plus
 * un délai en jours, mois ou années au calendrier civil (31 janvier + 1 mois = dernier jour de février). Aucune
 * décision médicale automatique : le délai (10, 12 ou 14 jours, 3 ou 6 mois, 1 ou 2 ans, autre) est choisi par le
 * professionnel ; sans date de départ, aucun calcul. Une date saisie à la main porte un motif.
 *
 * Règles pures, jumelles de `resources/frontend/domain/scheduling/followUp.ts`.
 */
final class FollowUpCalculator
{
    /**
     * Date recommandée attendue d'un plan calculé ; null pour une date manuelle.
     */
    public static function expected(string $dateOrigin, CivilDate $anchor, ?int $intervalValue, ?string $intervalUnit): ?CivilDate
    {
        if ($dateOrigin === 'MANUAL') {
            return null;
        }
        if ($intervalValue === null || $intervalUnit === null) {
            throw RuleViolation::invalid('interval_value', 'follow_up.interval_required', 'Indiquez le délai du contrôle (nombre et unité) pour une date calculée.');
        }

        return FollowUpDates::recommended($anchor, $intervalValue, $intervalUnit);
    }

    /**
     * Date du rappel interne : la date recommandée moins l'avance choisie, ou la date recommandée sans avance.
     */
    public static function reminderDate(CivilDate $recommended, ?int $advanceValue, ?string $advanceUnit): CivilDate
    {
        if ($advanceValue === null || $advanceUnit === null) {
            return $recommended;
        }

        return FollowUpDates::reminder($recommended, $advanceValue, $advanceUnit);
    }
}
