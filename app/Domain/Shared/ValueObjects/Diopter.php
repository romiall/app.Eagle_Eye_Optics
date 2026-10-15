<?php

namespace App\Domain\Shared\ValueObjects;

/**
 * Mesure optique `DECIMAL(6,2)` (sphère, cylindre, addition, écarts pupillaires, hauteur) : signée, 2 décimales.
 * Une mesure absente vaut null (« non mesuré ») et n'est jamais remplacée par « 0.00 », qui est une mesure.
 * Les bornes cliniques (pas de 0,25, plages usuelles) ne sont pas fixées ici : elles attendent Q06.
 */
final class Diopter
{
    public const SCALE = 2;

    public const INTEGER_DIGITS = 4;

    public static function parse(?string $text, string $field): ?FixedDecimal
    {
        return FixedDecimal::parse($text, self::SCALE, self::INTEGER_DIGITS, true, $field);
    }
}
