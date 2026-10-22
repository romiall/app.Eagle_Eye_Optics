<?php

namespace App\Domain\Shared;

use App\Domain\Shared\ValueObjects\CivilDate;
use Carbon\CarbonImmutable;

/**
 * Fenêtre UTC d'un jour civil du cabinet : de minuit local inclus à minuit local suivant exclu, convertie en
 * UTC avec le fuseau du cabinet. Jamais « minuit UTC » : à Douala (UTC+1), le jour du 22 octobre commence le
 * 21 octobre à 23 h UTC. Les instants sont stockés en UTC (`DATETIME(6)`).
 */
final class DayWindow
{
    /**
     * @return array{0: string, 1: string} début inclus et fin exclue, au format `Y-m-d H:i:s.u` (UTC)
     */
    public static function utc(CivilDate $day, string $timezone): array
    {
        $start = new CarbonImmutable($day->toString().' 00:00:00', $timezone);
        $end = $start->addDay();

        return [$start->utc()->format('Y-m-d H:i:s.u'), $end->utc()->format('Y-m-d H:i:s.u')];
    }
}
