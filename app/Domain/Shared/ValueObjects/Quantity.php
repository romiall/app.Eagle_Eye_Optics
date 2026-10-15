<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;

/**
 * Quantité `DECIMAL(12,3)` (lignes de facture et d'avoir, stock, quotas) : 9 chiffres entiers, 3 décimales,
 * jamais négative ici (le signe d'un mouvement de stock est porté par son sens).
 */
final class Quantity
{
    public const SCALE = 3;

    public const INTEGER_DIGITS = 9;

    /** Plus grande quantité représentable : 999 999 999,999 (en millièmes). */
    public const MAX_UNITS = 999_999_999_999;

    public static function parse(?string $text, string $field = 'quantity'): ?FixedDecimal
    {
        return FixedDecimal::parse($text, self::SCALE, self::INTEGER_DIGITS, false, $field);
    }

    /**
     * Valeur de confiance (forme canonique ou rendue par MySQL), bornée comme la colonne `DECIMAL(12,3)` : au plus
     * 999 999 999,999 en valeur absolue (un écart de stock peut être négatif).
     */
    public static function of(string $canonical, string $field = 'quantity'): FixedDecimal
    {
        $quantity = FixedDecimal::fromString($canonical, self::SCALE);
        if (abs($quantity->units) > self::MAX_UNITS) {
            throw RuleViolation::invalid($field, 'decimal.out_of_range', 'Valeur hors des bornes admises.', ['integer_digits' => self::INTEGER_DIGITS]);
        }

        return $quantity;
    }

    /**
     * Quantité d'une ligne : strictement positive (CHECK quantity > 0), dans les bornes de la colonne, et entière
     * pour un article qui l'exige (`catalog_items.quantity_integer_only`).
     */
    public static function forLine(FixedDecimal $quantity, bool $integerOnly, string $field = 'quantity'): FixedDecimal
    {
        if ($quantity->units <= 0) {
            throw RuleViolation::invalid($field, 'quantity.positive_required', 'La quantité doit être strictement positive.');
        }
        if ($quantity->units > self::MAX_UNITS) {
            throw RuleViolation::invalid($field, 'decimal.out_of_range', 'Valeur hors des bornes admises.', ['integer_digits' => self::INTEGER_DIGITS]);
        }
        if ($integerOnly && ! $quantity->isInteger()) {
            throw RuleViolation::invalid($field, 'quantity.integer_required', 'Cet article se vend à l\'unité : quantité entière obligatoire.');
        }

        return $quantity;
    }

    /**
     * Résultat d'un calcul de stock : même borne que la colonne, jamais négatif.
     */
    public static function bounded(int $units, string $field): FixedDecimal
    {
        if ($units < 0) {
            throw RuleViolation::invalid($field, 'quantity.negative_result', 'Le calcul donnerait une quantité négative.');
        }
        if ($units > self::MAX_UNITS) {
            throw RuleViolation::invalid($field, 'decimal.out_of_range', 'Valeur hors des bornes admises.', ['integer_digits' => self::INTEGER_DIGITS]);
        }

        return FixedDecimal::fromUnits($units, self::SCALE);
    }
}
