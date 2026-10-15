<?php

namespace App\Domain\Inventory\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;

/**
 * Lots (collyres) : un lot en quarantaine ou expiré à la date LOCALE de remise n'est pas délivré (fichier 03).
 * Un lot dont la date d'expiration est le jour même reste délivrable ce jour-là. Ordre de sortie : expiration la
 * plus proche d'abord (FEFO), lots sans date ensuite, puis identifiant pour un ordre total et reproductible.
 */
final class LotRules
{
    public static function assertDeliverable(string $status, ?CivilDate $expiresOn, CivilDate $localDate): void
    {
        if ($status !== 'SELLABLE') {
            throw new RuleViolation(RuleViolation::STOCK_UNAVAILABLE, 'lot.quarantined', 'lot_id', 'Ce lot est en quarantaine : il ne peut pas être délivré.');
        }
        if ($expiresOn !== null && $expiresOn->compare($localDate) < 0) {
            throw new RuleViolation(RuleViolation::LOT_EXPIRED, 'lot.expired', 'lot_id', 'Ce lot est expiré à la date de remise.', ['expires_on' => $expiresOn->toString()]);
        }
    }

    /**
     * @param  list<array{id: string, expires_on: ?CivilDate}>  $lots
     * @return list<string> identifiants dans l'ordre de sortie
     */
    public static function fefoOrder(array $lots): array
    {
        usort($lots, static function (array $left, array $right): int {
            $byDate = match (true) {
                $left['expires_on'] === null && $right['expires_on'] === null => 0,
                $left['expires_on'] === null => 1,
                $right['expires_on'] === null => -1,
                default => $left['expires_on']->compare($right['expires_on']),
            };

            return $byDate !== 0 ? $byDate : strcmp($left['id'], $right['id']);
        });

        return array_map(static fn (array $lot): string => $lot['id'], $lots);
    }
}
