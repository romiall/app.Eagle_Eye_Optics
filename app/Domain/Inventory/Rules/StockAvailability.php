<?php

namespace App\Domain\Inventory\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;

/**
 * Disponibilités de stock (fichiers 03 et 19, vues `vw_stock_position_balances` et `vw_stock_lot_balances`) :
 *
 *   disponible_central = physique - réservations_centrales - quotas_locaux_non_consommés
 *   disponible_local   = quota_alloué - consommé - restitué - réservations_locales_actives
 *
 * Une réservation faite sous quota appartient déjà au quota : elle n'est jamais soustraite une seconde fois du
 * central (IT15). Une remise synchronisée sous quota diminue le physique ET le quota résiduel dans la même
 * transaction. Toutes les quantités sont des `DECIMAL(12,3)` exacts.
 */
final class StockAvailability
{
    /**
     * Peut être négatif si les données sont incohérentes : la valeur est rendue telle quelle pour que le
     * rapprochement la signale, mais aucune réservation ne peut alors être accordée.
     */
    public static function central(FixedDecimal $physical, FixedDecimal $centralReserved, FixedDecimal $offlineAllocated): FixedDecimal
    {
        return $physical->minus($centralReserved)->minus($offlineAllocated);
    }

    /** Part d'un quota de poste non encore consommée ni restituée (`device_stock_allocations`). */
    public static function remainingQuota(FixedDecimal $allocated, FixedDecimal $consumed, FixedDecimal $returned): FixedDecimal
    {
        $remaining = $allocated->minus($consumed)->minus($returned);
        if ($remaining->isNegative()) {
            throw RuleViolation::invalid('allocation', 'stock.allocation_overdrawn', 'Le quota est consommé et restitué au-delà de son allocation.');
        }

        return $remaining;
    }

    public static function local(FixedDecimal $allocated, FixedDecimal $consumed, FixedDecimal $returned, FixedDecimal $localReserved): FixedDecimal
    {
        return self::remainingQuota($allocated, $consumed, $returned)->minus($localReserved);
    }

    /**
     * Réserver ou remettre : la quantité demandée ne dépasse jamais le disponible ; le refus indique ce qui reste.
     */
    public static function assertCanTake(FixedDecimal $requested, FixedDecimal $available, string $rule = 'stock.unavailable'): void
    {
        Quantity::forLine($requested, false);
        if ($requested->compare($available) > 0) {
            throw new RuleViolation(
                RuleViolation::STOCK_UNAVAILABLE,
                $rule,
                'quantity',
                'Quantité demandée supérieure au stock disponible.',
                ['available' => $available->isNegative() ? Quantity::of('0')->canonical() : $available->canonical()],
            );
        }
    }

    /**
     * Remise faite hors-ligne sous quota, appliquée au serveur à la synchronisation : sortie du physique et
     * consommation du quota ensemble. Rend [physique, consommé] après application.
     *
     * @return array{0: FixedDecimal, 1: FixedDecimal}
     */
    public static function applySyncedQuotaDelivery(FixedDecimal $physical, FixedDecimal $allocated, FixedDecimal $consumed, FixedDecimal $returned, FixedDecimal $quantity): array
    {
        self::assertCanTake($quantity, self::remainingQuota($allocated, $consumed, $returned), 'stock.quota_exceeded');
        $physicalAfter = $physical->minus($quantity);
        if ($physicalAfter->isNegative()) {
            throw new RuleViolation(RuleViolation::STOCK_UNAVAILABLE, 'stock.physical_negative', 'quantity', 'La sortie rendrait le stock physique négatif.', ['available' => $physical->canonical()]);
        }

        return [$physicalAfter, Quantity::bounded($consumed->units + $quantity->units, 'consumed_quantity')];
    }
}
