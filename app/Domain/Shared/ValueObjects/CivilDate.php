<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Date civile `YYYY-MM-DD` (colonnes DATE), calculée dans le fuseau du cabinet, jamais déduite d'un minuit UTC
 * (contrat 23). L'arithmétique est entière (numéro de jour), sans fuseau ni heure d'été.
 *
 * Mois et années : calendrier civil, le jour est ramené au dernier jour du mois d'arrivée quand il n'existe pas
 * (31 janvier + 1 mois = 28 ou 29 février ; 29 février + 1 an = 28 février). Bornes du type DATE de MySQL :
 * 1000-01-01 à 9999-12-31.
 */
final readonly class CivilDate
{
    private function __construct(public int $year, public int $month, public int $day) {}

    public static function of(int $year, int $month, int $day, string $field = 'date'): self
    {
        if ($year < 1000 || $year > 9999) {
            throw RuleViolation::invalid($field, 'date.out_of_range', 'Date hors des bornes admises (années 1000 à 9999).');
        }
        if ($month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            throw RuleViolation::invalid($field, 'date.invalid', 'Cette date n\'existe pas dans le calendrier.');
        }

        return new self($year, $month, $day);
    }

    /**
     * Saisie d'une date : ISO « 2026-10-15 » ou française « 15/10/2026 » (jour et mois sur un ou deux chiffres).
     * Vide → null : une date inconnue n'est jamais remplacée par une date inventée.
     */
    public static function parse(?string $text, string $field = 'date'): ?self
    {
        $value = Text::trim($text ?? '');
        if ($value === '') {
            return null;
        }
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $iso) === 1) {
            return self::of((int) $iso[1], (int) $iso[2], (int) $iso[3], $field);
        }
        if (preg_match('/^([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})$/', $value, $french) === 1) {
            return self::of((int) $french[3], (int) $french[2], (int) $french[1], $field);
        }

        throw RuleViolation::invalid($field, 'date.format', 'Date invalide : JJ/MM/AAAA ou AAAA-MM-JJ.');
    }

    public static function fromString(string $iso, string $field = 'date'): self
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $iso, $parts) !== 1) {
            throw RuleViolation::invalid($field, 'date.format', 'Date invalide : JJ/MM/AAAA ou AAAA-MM-JJ.');
        }

        return self::of((int) $parts[1], (int) $parts[2], (int) $parts[3], $field);
    }

    /**
     * Jour civil d'un instant UTC dans le fuseau du cabinet (Africa/Douala par défaut de configuration).
     */
    public static function fromInstant(Instant $instant, string $timezone, string $field = 'date'): self
    {
        $seconds = intdiv($instant->epochMilliseconds - self::floorMod($instant->epochMilliseconds, 1000), 1000);
        $local = (new DateTimeImmutable('@'.$seconds))->setTimezone(new DateTimeZone($timezone));

        return self::of((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'), $field);
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => self::isLeapYear($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public function toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public function addDays(int $days, string $field = 'date'): self
    {
        [$year, $month, $day] = self::civilFromDays($this->dayNumber() + $days);

        return self::of($year, $month, $day, $field);
    }

    public function addMonths(int $months, string $field = 'date'): self
    {
        $index = $this->year * 12 + ($this->month - 1) + $months;
        $year = intdiv($index - self::floorMod($index, 12), 12);
        $month = self::floorMod($index, 12) + 1;
        if ($year < 1000 || $year > 9999) {
            throw RuleViolation::invalid($field, 'date.out_of_range', 'Date hors des bornes admises (années 1000 à 9999).');
        }

        return self::of($year, $month, min($this->day, self::daysInMonth($year, $month)), $field);
    }

    public function addYears(int $years, string $field = 'date'): self
    {
        return $this->addMonths(12 * $years, $field);
    }

    public function compare(self $other): int
    {
        return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
    }

    /**
     * Âge en années révolues à une date de référence : l'anniversaire d'un 29 février compte le 1er mars d'une
     * année non bissextile. Refus si la référence précède la naissance.
     */
    public function ageOn(self $reference, string $field = 'birth_date'): int
    {
        if ($reference->compare($this) < 0) {
            throw RuleViolation::invalid($field, 'date.after_reference', 'La date de naissance est postérieure à la date de référence.');
        }
        $age = $reference->year - $this->year;
        if ([$reference->month, $reference->day] < [$this->month, $this->day]) {
            $age--;
        }

        return $age;
    }

    /** Numéro de jour depuis le 1970-01-01 (algorithme « days_from_civil », calendrier grégorien proleptique). */
    public function dayNumber(): int
    {
        $year = $this->month <= 2 ? $this->year - 1 : $this->year;
        $era = intdiv($year, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($this->month > 2 ? $this->month - 3 : $this->month + 9) + 2, 5) + $this->day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146097 + $dayOfEra - 719468;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function civilFromDays(int $days): array
    {
        $shifted = $days + 719468;
        $era = intdiv($shifted - self::floorMod($shifted, 146097), 146097);
        $dayOfEra = $shifted - $era * 146097;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096), 365);
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $monthIndex = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $monthIndex + 2, 5) + 1;
        $month = $monthIndex < 10 ? $monthIndex + 3 : $monthIndex - 9;

        return [$yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }

    private static function floorMod(int $value, int $divisor): int
    {
        return (($value % $divisor) + $divisor) % $divisor;
    }
}
