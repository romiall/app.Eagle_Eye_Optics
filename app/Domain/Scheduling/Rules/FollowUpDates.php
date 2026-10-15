<?php

namespace App\Domain\Scheduling\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;

/**
 * Dates d'un contrôle recommandé (fichier 02, `follow_up_plans`, AT22) : date de départ choisie par le
 * professionnel (consultation, début ou fin de traitement, date manuelle) plus un délai en jours, mois ou années,
 * au calendrier civil. Aucune durée de traitement n'est supposée : sans date de départ, pas de calcul.
 */
final class FollowUpDates
{
    public static function recommended(CivilDate $anchor, int $value, string $unit): CivilDate
    {
        if ($value < 1) {
            throw RuleViolation::invalid('interval_value', 'follow_up.interval_positive', 'Le délai du contrôle doit être d\'au moins 1.');
        }

        return match ($unit) {
            'DAY' => $anchor->addDays($value, 'recommended_date'),
            'MONTH' => $anchor->addMonths($value, 'recommended_date'),
            'YEAR' => $anchor->addYears($value, 'recommended_date'),
            default => throw RuleViolation::invalid('interval_unit', 'follow_up.unit_unknown', 'Unité de délai inconnue.'),
        };
    }

    /** Date de rappel interne : avance de 0 ou plus jours ou mois avant la date recommandée. */
    public static function reminder(CivilDate $recommended, int $advance, string $unit): CivilDate
    {
        if ($advance < 0) {
            throw RuleViolation::invalid('advance_value', 'follow_up.advance_negative', 'L\'avance d\'un rappel ne peut pas être négative.');
        }

        return match ($unit) {
            'DAY' => $recommended->addDays(-$advance, 'reminder_date'),
            'MONTH' => $recommended->addMonths(-$advance, 'reminder_date'),
            default => throw RuleViolation::invalid('advance_unit', 'follow_up.unit_unknown', 'Unité de délai inconnue.'),
        };
    }
}
