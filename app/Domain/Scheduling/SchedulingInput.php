<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;

/**
 * Lectures des valeurs des commandes de l'agenda (S01, S02) : instants, dates civiles, heures locales et textes,
 * chacun refusé champ par champ (422), jamais arrondi ni deviné. Les erreurs s'accumulent dans `$errors` pour être
 * rendues ensemble.
 */
final class SchedulingInput
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function instant(mixed $value, string $field, array &$errors): ?CarbonImmutable
    {
        try {
            $instant = Instant::parse(is_string($value) ? $value : null, $field);
        } catch (RuleViolation $violation) {
            $errors[$field][] = $violation->getMessage();

            return null;
        }
        if ($instant === null) {
            $errors[$field][] = 'Instant obligatoire.';

            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC($instant->epochMilliseconds);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function date(mixed $value, string $field, array &$errors): ?CivilDate
    {
        if (! is_string($value) || $value === '') {
            $errors[$field][] = 'Date obligatoire (AAAA-MM-JJ).';

            return null;
        }
        try {
            return CivilDate::fromString($value, $field);
        } catch (RuleViolation $violation) {
            $errors[$field][] = $violation->getMessage();

            return null;
        }
    }

    /**
     * Heure locale « HH:MM » → minutes depuis minuit (0 à 1439).
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function localTime(mixed $value, string $field, array &$errors): ?int
    {
        if (! is_string($value) || preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value, $match) !== 1) {
            $errors[$field][] = 'Heure invalide : HH:MM attendu.';

            return null;
        }

        return ((int) $match[1]) * 60 + (int) $match[2];
    }

    /**
     * Texte facultatif borné ; caractères de contrôle refusés.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function text(mixed $value, int $maxLength, string $field, array &$errors): ?string
    {
        if ($value !== null && ! is_string($value)) {
            $errors[$field][] = 'Texte attendu.';

            return null;
        }
        try {
            return Text::optional($value, $maxLength, $field);
        } catch (RuleViolation $violation) {
            $errors[$field][] = $violation->getMessage();

            return null;
        }
    }

    /**
     * Texte obligatoire d'au moins `$minLength` caractères.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function reason(mixed $value, int $minLength, int $maxLength, string $field, array &$errors): ?string
    {
        $text = self::text($value, $maxLength, $field, $errors);
        if (! isset($errors[$field]) && ($text === null || mb_strlen($text) < $minLength)) {
            $errors[$field][] = 'Indiquez le motif (au moins '.$minLength.' caractères).';

            return null;
        }

        return $text;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function throwIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }
    }

    /** Minutes depuis minuit → « HH:MM ». */
    public static function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Colonne TIME MySQL (« 08:30:00 ») → minutes depuis minuit. */
    public static function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /** Instant UTC → colonne DATETIME(6). */
    public static function stamp(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s.u');
    }

    /** Colonne DATETIME(6) (UTC) → instant ISO de l'API. */
    public static function iso(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
