<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;

/**
 * Montants XAF : entiers FCFA, jamais de flottant (fichier 03). Bornes des colonnes BIGINT du schéma :
 * 0..10^12 pour un montant, -10^12..10^12 pour un solde signé. Une somme hors bornes est refusée, jamais tronquée.
 */
final class Money
{
    public const MAX = 1_000_000_000_000;

    /** Espace fine insécable U+202F entre groupes de chiffres, espace insécable avant l'unité. */
    private const GROUP_SEPARATOR = "\u{202F}";

    public static function amount(int $value, string $field = 'amount'): int
    {
        if ($value < 0 || $value > self::MAX) {
            throw RuleViolation::invalid($field, 'money.out_of_range', 'Montant hors des bornes admises (0 à 1 000 milliards de FCFA).');
        }

        return $value;
    }

    public static function positive(int $value, string $field = 'amount'): int
    {
        if ($value <= 0) {
            throw RuleViolation::invalid($field, 'money.positive_required', 'Le montant doit être strictement positif.');
        }

        return self::amount($value, $field);
    }

    public static function signed(int $value, string $field = 'amount'): int
    {
        if ($value < -self::MAX || $value > self::MAX) {
            throw RuleViolation::invalid($field, 'money.out_of_range', 'Montant hors des bornes admises (0 à 1 000 milliards de FCFA).');
        }

        return $value;
    }

    /**
     * Saisie d'un montant en FCFA : chiffres seuls (« 80000 ») ou groupés par trois avec une espace, une espace
     * insécable ou une espace fine (« 80 000 »). Ni virgule, ni point, ni signe : le franc CFA n'a pas de
     * subdivision, et « 80.000 » serait ambigu. Vide → null, jamais 0.
     */
    public static function parse(?string $text, string $field = 'amount'): ?int
    {
        $value = Text::trim($text ?? '');
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:[\x{0020}\x{00A0}\x{202F}][0-9]{3})+)\z/u', $value) !== 1) {
            throw RuleViolation::invalid($field, 'money.format', 'Montant invalide : un nombre entier de FCFA, par exemple 80 000.');
        }
        $digits = ltrim((string) preg_replace('/[^0-9]/', '', $value), '0');
        if (strlen($digits) > 13) {
            throw RuleViolation::invalid($field, 'money.out_of_range', 'Montant hors des bornes admises (0 à 1 000 milliards de FCFA).');
        }

        return self::amount((int) ($digits === '' ? '0' : $digits), $field);
    }

    /**
     * Affichage français : « 80 000 FCFA », « -50 000 FCFA » (espaces fines insécables entre groupes).
     */
    public static function format(int $amount): string
    {
        $digits = (string) abs($amount);
        $groups = [];
        for ($end = strlen($digits); $end > 0; $end -= 3) {
            $start = max(0, $end - 3);
            array_unshift($groups, substr($digits, $start, $end - $start));
        }

        return ($amount < 0 ? '-' : '').implode(self::GROUP_SEPARATOR, $groups)."\u{00A0}FCFA";
    }
}
