<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;

/**
 * Instant UTC à la milliseconde, forme contractuelle fixe `2026-10-14T09:30:00.000Z` (`common.json#Instant`).
 * Porté par un nombre entier de millisecondes depuis 1970 ; le jour civil se calcule dans le fuseau du cabinet
 * (`CivilDate::fromInstant`).
 */
final readonly class Instant
{
    private function __construct(public int $epochMilliseconds) {}

    public static function fromEpochMilliseconds(int $milliseconds): self
    {
        return new self($milliseconds);
    }

    public static function parse(?string $text, string $field = 'occurred_at'): ?self
    {
        if ($text === null || $text === '') {
            return null;
        }
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})\.([0-9]{3})Z$/', $text, $parts) !== 1) {
            throw RuleViolation::invalid($field, 'instant.format', 'Instant invalide : forme AAAA-MM-JJTHH:MM:SS.mmmZ en UTC.');
        }
        $date = CivilDate::of((int) $parts[1], (int) $parts[2], (int) $parts[3], $field);
        [$hour, $minute, $second] = [(int) $parts[4], (int) $parts[5], (int) $parts[6]];
        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw RuleViolation::invalid($field, 'instant.format', 'Instant invalide : forme AAAA-MM-JJTHH:MM:SS.mmmZ en UTC.');
        }

        return new self((($date->dayNumber() * 24 + $hour) * 60 + $minute) * 60_000 + $second * 1000 + (int) $parts[7]);
    }

    public function toString(): string
    {
        $milliseconds = (($this->epochMilliseconds % 86_400_000) + 86_400_000) % 86_400_000;
        $days = intdiv($this->epochMilliseconds - $milliseconds, 86_400_000);
        $date = CivilDate::of(1970, 1, 1)->addDays($days);

        return sprintf(
            '%sT%02d:%02d:%02d.%03dZ',
            $date->toString(),
            intdiv($milliseconds, 3_600_000),
            intdiv($milliseconds % 3_600_000, 60_000),
            intdiv($milliseconds % 60_000, 1000),
            $milliseconds % 1000,
        );
    }

    public function civilDate(string $timezone, string $field = 'date'): CivilDate
    {
        return CivilDate::fromInstant($this, $timezone, $field);
    }
}
