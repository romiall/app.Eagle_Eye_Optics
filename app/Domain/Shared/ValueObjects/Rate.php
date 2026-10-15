<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;

/**
 * Taux en pourcentage `DECIMAL(7,4)` (`tax_rules.rate_percent`, `invoice_lines.tax_rate_snapshot`), de 0 à 100.
 * Aucun taux fiscal n'est fourni par le code : la gérante le valide (fichier 07, `tax_rules`).
 */
final class Rate
{
    public const SCALE = 4;

    public static function parse(?string $text, string $field = 'rate_percent'): ?FixedDecimal
    {
        $rate = FixedDecimal::parse($text, self::SCALE, 3, false, $field);
        if ($rate !== null) {
            self::assertBounds($rate, $field);
        }

        return $rate;
    }

    public static function of(string $canonical, string $field = 'rate_percent'): FixedDecimal
    {
        return self::assertBounds(FixedDecimal::fromString($canonical, self::SCALE), $field);
    }

    private static function assertBounds(FixedDecimal $rate, string $field): FixedDecimal
    {
        if ($rate->units < 0 || $rate->units > 100 * (10 ** self::SCALE)) {
            throw RuleViolation::invalid($field, 'rate.out_of_range', 'Un taux est compris entre 0 et 100 %.');
        }

        return $rate;
    }
}
