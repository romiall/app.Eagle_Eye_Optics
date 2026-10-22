<?php

namespace App\Domain\Billing\Reports;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\DayWindow;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;

/**
 * Jour civil d'une lecture financière (liste des factures d'un jour, caisse du jour) : la date demandée
 * (`AAAA-MM-JJ`), sinon aujourd'hui, toujours au FUSEAU DU CABINET (jamais minuit UTC), et sa fenêtre UTC (début
 * inclus, fin exclue) pour comparer des instants stockés en UTC. Une date illisible est refusée en 422 sur le champ
 * de la requête, jamais remplacée en silence par aujourd'hui.
 */
final class ReportDay
{
    private function __construct(public readonly CivilDate $day, public readonly string $start, public readonly string $end) {}

    /**
     * Date demandée, ou aujourd'hui si elle est absente ou vide.
     */
    public static function resolve(string $timezone, CarbonImmutable $now, mixed $date, string $field = 'date'): self
    {
        if ($date === null || $date === '') {
            $day = Instant::fromEpochMilliseconds((int) $now->format('Uv'))->civilDate($timezone);
        } else {
            $day = self::parse($date, $field);
        }
        [$start, $end] = DayWindow::utc($day, $timezone);

        return new self($day, $start, $end);
    }

    /**
     * Date facultative : null si elle est absente ou vide (aucun filtre).
     */
    public static function optional(string $timezone, mixed $date, string $field): ?self
    {
        if ($date === null || $date === '') {
            return null;
        }
        $day = self::parse($date, $field);
        [$start, $end] = DayWindow::utc($day, $timezone);

        return new self($day, $start, $end);
    }

    private static function parse(mixed $date, string $field): CivilDate
    {
        try {
            return CivilDate::fromString(is_string($date) ? $date : '', $field);
        } catch (RuleViolation $violation) {
            throw BusinessError::validation([$field => [$violation->getMessage()]]);
        }
    }
}
