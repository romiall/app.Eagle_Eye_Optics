<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;
use InvalidArgumentException;

/**
 * Nombre décimal exact à échelle fixe, image d'une colonne `DECIMAL(p,s)` : la valeur est un entier d'unités
 * (1.250 en échelle 3 vaut 1250 unités). Aucun flottant n'intervient, ni à la lecture, ni au calcul.
 *
 * Forme canonique (transport, empreintes, comparaison avec MySQL) : partie entière sans zéro non significatif,
 * point, exactement `scale` décimales, signe « - » seulement pour une valeur négative non nulle : « 1.500 »,
 * « -0.75 », « 0.00 ». C'est la forme que MySQL rend pour une colonne DECIMAL.
 */
final readonly class FixedDecimal
{
    private function __construct(public int $units, public int $scale) {}

    public static function fromUnits(int $units, int $scale): self
    {
        if ($scale < 0 || $scale > 6) {
            throw new InvalidArgumentException('Échelle décimale hors de 0..6.');
        }

        return new self($units, $scale);
    }

    /**
     * Lecture d'une saisie : virgule ou point décimal, signe « + », « - » ou « − » (U+2212) facultatif, blancs de
     * bord admis. Trop de décimales ou de chiffres entiers : refus, jamais de troncature ni d'arrondi. Vide → null,
     * jamais zéro.
     */
    public static function parse(?string $text, int $scale, int $integerDigits, bool $signed, string $field): ?self
    {
        if ($integerDigits + $scale > 18) {
            throw new InvalidArgumentException('Précision décimale supérieure à 18 chiffres.');
        }
        $value = Text::trim($text ?? '');
        if ($value === '') {
            return null;
        }
        if (preg_match('/^([+\-\x{2212}]?)([0-9]+)(?:[.,]([0-9]+))?$/u', $value, $matches) !== 1) {
            throw RuleViolation::invalid($field, 'decimal.format', 'Nombre invalide : chiffres, avec une virgule ou un point décimal.');
        }
        $negative = $matches[1] === '-' || $matches[1] === "\u{2212}";
        $integer = ltrim($matches[2], '0');
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > $scale) {
            throw RuleViolation::invalid($field, 'decimal.scale', "Au plus {$scale} décimale(s) : la valeur n'est ni arrondie ni tronquée.", ['scale' => $scale]);
        }
        if (strlen($integer) > $integerDigits) {
            throw RuleViolation::invalid($field, 'decimal.out_of_range', 'Valeur hors des bornes admises.', ['integer_digits' => $integerDigits]);
        }
        $units = (int) ($integer === '' ? '0' : $integer) * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');
        if ($negative && $units !== 0 && ! $signed) {
            throw RuleViolation::invalid($field, 'decimal.negative', 'Une valeur négative n\'est pas admise ici.');
        }

        return new self($negative ? -$units : $units, $scale);
    }

    /**
     * Valeur de confiance déjà canonique ou rendue par MySQL (« 1.500 », « 2 ») : complétée à l'échelle demandée.
     */
    public static function fromString(string $text, int $scale): self
    {
        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/', $text, $matches) !== 1 || strlen($matches[3] ?? '') > $scale) {
            throw new InvalidArgumentException("Décimal non canonique : {$text}");
        }
        $units = (int) $matches[2] * (10 ** $scale) + (int) str_pad($matches[3] ?? '', $scale, '0');

        return new self($matches[1] === '-' ? -$units : $units, $scale);
    }

    public function canonical(): string
    {
        $absolute = (string) abs($this->units);
        if ($this->scale === 0) {
            return ($this->units < 0 ? '-' : '').$absolute;
        }
        $padded = str_pad($absolute, $this->scale + 1, '0', STR_PAD_LEFT);
        $integer = substr($padded, 0, -$this->scale);
        $fraction = substr($padded, -$this->scale);

        return ($this->units < 0 ? '-' : '').$integer.'.'.$fraction;
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    public function isInteger(): bool
    {
        return $this->units % (10 ** $this->scale) === 0;
    }

    public function compare(self $other): int
    {
        $this->assertSameScale($other);

        return $this->units <=> $other->units;
    }

    public function plus(self $other): self
    {
        $this->assertSameScale($other);

        return new self($this->units + $other->units, $this->scale);
    }

    public function minus(self $other): self
    {
        $this->assertSameScale($other);

        return new self($this->units - $other->units, $this->scale);
    }

    private function assertSameScale(self $other): void
    {
        if ($other->scale !== $this->scale) {
            throw new InvalidArgumentException('Décimaux d\'échelles différentes.');
        }
    }
}
