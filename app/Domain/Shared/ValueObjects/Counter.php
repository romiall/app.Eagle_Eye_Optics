<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;
use Brick\Math\BigInteger;

/**
 * Compteur `BIGINT UNSIGNED` (séquence d'appareil, borne de plage, curseur) : chaîne de chiffres sans zéro non
 * significatif dans le transport et le cœur signé (contrat 23), jamais un nombre JavaScript hors plage sûre.
 */
final readonly class Counter
{
    public const MAX = '18446744073709551615';

    private function __construct(public string $value) {}

    public static function parse(?string $text, string $field = 'device_sequence'): self
    {
        if ($text === null || preg_match('/^(0|[1-9][0-9]{0,19})$/', $text) !== 1) {
            throw RuleViolation::invalid($field, 'counter.format', 'Compteur invalide : chiffres sans zéro initial.');
        }
        if (BigInteger::of($text)->isGreaterThan(BigInteger::of(self::MAX))) {
            throw RuleViolation::invalid($field, 'counter.out_of_range', 'Compteur au-delà de la borne BIGINT UNSIGNED.');
        }

        return new self($text);
    }

    public function next(string $field = 'device_sequence'): self
    {
        $next = BigInteger::of($this->value)->plus(1);
        if ($next->isGreaterThan(BigInteger::of(self::MAX))) {
            throw RuleViolation::invalid($field, 'counter.out_of_range', 'Compteur au-delà de la borne BIGINT UNSIGNED.');
        }

        return new self((string) $next);
    }

    public function compare(self $other): int
    {
        return BigInteger::of($this->value)->compareTo(BigInteger::of($other->value));
    }
}
