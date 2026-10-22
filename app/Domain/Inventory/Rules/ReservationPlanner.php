<?php

namespace App\Domain\Inventory\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;

/**
 * Plan d'une réserve de stock CENTRALE pour une ligne de vente (fichier 03 : « Émettre une facture réserve les
 * articles gérés en stock ; cette réserve est une allocation et ne décrémente pas le stock physique » ; fichier 19 :
 * disponible central = physique − réservations centrales − quotas locaux). Fonction pure : elle reçoit les
 * disponibilités LUES SOUS VERROU et décide où prélever, sans rien écrire.
 *
 * Deux modes, selon que l'article a des lots :
 *  - sans lot (et sans péremption exigée) : prélèvement sur les positions, par identifiant croissant ;
 *  - avec lots (ou péremption exigée, donc lot obligatoire) : lots vendables et non expirés à la date LOCALE de
 *    remise, expiration la plus proche d'abord (FEFO), puis lots sans date ; une quantité qui dépasse un lot passe
 *    au lot suivant, dans la limite de la position qui le porte. Un lot en quarantaine ou expiré n'est jamais
 *    réservé. Un lot qui expire le jour même reste vendable ce jour-là.
 *
 * Le refus indique la quantité réellement disponible : STOCK_UNAVAILABLE, ou LOT_EXPIRED quand c'est la péremption
 * qui prive de stock. Aucun stock disponible négatif n'est jamais produit.
 */
final class ReservationPlanner
{
    /**
     * @param  list<array{id: string, available: FixedDecimal}>  $positions  positions de l'article (disponible central)
     * @param  list<array{id: string, position_id: string, available: FixedDecimal, status: string, expires_on: ?CivilDate}>  $lots  lots de l'article
     * @return list<array{position_id: string, lot_id: ?string, quantity: FixedDecimal}>
     *
     * @throws RuleViolation
     */
    public static function plan(FixedDecimal $requested, bool $requiresExpiry, array $positions, array $lots, CivilDate $localDate): array
    {
        Quantity::forLine($requested, false, 'quantity');

        if ($lots === [] && ! $requiresExpiry) {
            return self::fromPositions($requested, $positions);
        }

        return self::fromLots($requested, $positions, $lots, $localDate);
    }

    /**
     * @param  list<array{id: string, available: FixedDecimal}>  $positions
     * @return list<array{position_id: string, lot_id: ?string, quantity: FixedDecimal}>
     */
    private static function fromPositions(FixedDecimal $requested, array $positions): array
    {
        usort($positions, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        $remaining = $requested;
        $total = FixedDecimal::fromUnits(0, Quantity::SCALE);
        $plan = [];
        foreach ($positions as $position) {
            if ($position['available']->units <= 0) {
                continue;
            }
            $total = $total->plus($position['available']);
            if ($remaining->isZero()) {
                continue;
            }
            $take = $position['available']->compare($remaining) < 0 ? $position['available'] : $remaining;
            $plan[] = ['position_id' => $position['id'], 'lot_id' => null, 'quantity' => $take];
            $remaining = $remaining->minus($take);
        }

        if (! $remaining->isZero()) {
            StockAvailability::assertCanTake($requested, $total);
        }

        return $plan;
    }

    /**
     * @param  list<array{id: string, available: FixedDecimal}>  $positions
     * @param  list<array{id: string, position_id: string, available: FixedDecimal, status: string, expires_on: ?CivilDate}>  $lots
     * @return list<array{position_id: string, lot_id: ?string, quantity: FixedDecimal}>
     */
    private static function fromLots(FixedDecimal $requested, array $positions, array $lots, CivilDate $localDate): array
    {
        $positionAvailable = [];
        foreach ($positions as $position) {
            $positionAvailable[$position['id']] = $position['available'];
        }

        $deliverable = [];
        $expiredWithStock = false;
        foreach ($lots as $lot) {
            if ($lot['available']->units <= 0) {
                continue;
            }
            if ($lot['status'] !== 'SELLABLE') {
                continue;
            }
            if ($lot['expires_on'] !== null && $lot['expires_on']->compare($localDate) < 0) {
                $expiredWithStock = true;

                continue;
            }
            $deliverable[$lot['id']] = $lot;
        }

        $order = LotRules::fefoOrder(array_map(
            static fn (array $lot): array => ['id' => $lot['id'], 'expires_on' => $lot['expires_on']],
            array_values($deliverable),
        ));

        $remaining = $requested;
        $reachable = FixedDecimal::fromUnits(0, Quantity::SCALE);
        $plan = [];
        foreach ($order as $lotId) {
            $lot = $deliverable[$lotId];
            $positionLeft = $positionAvailable[$lot['position_id']] ?? FixedDecimal::fromUnits(0, Quantity::SCALE);
            if ($positionLeft->units <= 0) {
                continue;
            }
            $offer = $lot['available']->compare($positionLeft) < 0 ? $lot['available'] : $positionLeft;
            $reachable = $reachable->plus($offer);
            if ($remaining->isZero()) {
                continue;
            }
            $take = $offer->compare($remaining) < 0 ? $offer : $remaining;
            $plan[] = ['position_id' => $lot['position_id'], 'lot_id' => $lot['id'], 'quantity' => $take];
            $positionAvailable[$lot['position_id']] = $positionLeft->minus($take);
            $remaining = $remaining->minus($take);
        }

        if (! $remaining->isZero()) {
            if ($expiredWithStock) {
                throw new RuleViolation(
                    RuleViolation::LOT_EXPIRED,
                    'lot.expired',
                    'quantity',
                    'Le stock restant de cet article est expiré à la date de remise : seule la quantité non expirée peut être réservée.',
                    ['available' => $reachable->canonical()],
                );
            }
            StockAvailability::assertCanTake($requested, $reachable);
        }

        return $plan;
    }
}
