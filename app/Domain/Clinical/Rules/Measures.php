<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Diopter;
use App\Domain\Shared\ValueObjects\FixedDecimal;

/**
 * Saisies mesurées des rubriques cliniques (C02) : sphère, cylindre, addition, écarts pupillaires (mm) et axe.
 *
 * Plages et pas DOCUMENTÉS (aucune borne clinique n'est inventée : elles attendent Q06) :
 *  - plage = celle de la colonne `DECIMAL(6,2)` (au plus 4 chiffres entiers, signe admis) ; une mesure plausible
 *    n'est jamais exclue ;
 *  - pas = 2 décimales (0,01) ; une valeur à trois décimales ou plus est REFUSÉE, jamais arrondie ni tronquée ;
 *  - axe = entier de 0 à 180 degrés ;
 *  - zéro est une valeur, distincte de « non mesuré » (null) ; le cylindre positif n'est JAMAIS transposé en négatif.
 *
 * La valeur est reçue en chaîne décimale exacte (contrat `Diopter`) et rendue en forme canonique (« 1.25 »,
 * « -0.50 », « 0.00 ») : celle que MySQL rend pour la colonne. Aucun flottant n'intervient.
 */
final class Measures
{
    public const AXIS_MAX = 180;

    public static function diopter(mixed $value, string $path, FieldErrors $errors): ?FixedDecimal
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $errors->add($path, 'Une mesure en chaîne décimale exacte est attendue (jamais un nombre à virgule flottante).');

            return null;
        }
        try {
            $parsed = Diopter::parse($value, $path);
        } catch (RuleViolation $violation) {
            $errors->add($path, $violation->getMessage());

            return null;
        }
        if ($parsed === null) {
            $errors->add($path, 'Une mesure vide s\'exprime par null (non mesuré), jamais par une chaîne vide.');
        }

        return $parsed;
    }

    public static function axis(mixed $value, string $path, FieldErrors $errors): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value)) {
            $errors->add($path, 'L\'axe est un entier de 0 à '.self::AXIS_MAX.' degrés.');

            return null;
        }
        if ($value < 0 || $value > self::AXIS_MAX) {
            $errors->add($path, 'L\'axe est compris entre 0 et '.self::AXIS_MAX.' degrés.');

            return null;
        }

        return $value;
    }

    /**
     * Forme canonique d'une mesure (« 1.25 »), ou null.
     */
    public static function canonical(?FixedDecimal $value): ?string
    {
        return $value?->canonical();
    }
}
