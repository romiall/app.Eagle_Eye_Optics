<?php

namespace App\Domain\Inventory\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;

/**
 * Règles pures de la REMISE au patient et du RETOUR physique (I01, I02 ; fichier 03 : « Remise : quantité remise non
 * déjà livrée », « Retour physique ≤ quantité remise non retournée » ; IT15). Ni base, ni verrou : l'action les applique
 * sur les lignes relues sous ses verrous.
 *
 *   reste à remettre   = quantité facturée − quantités déjà remises (remises confirmées)
 *   reste retournable  = quantité de la ligne remise − quantités déjà retournées
 *
 * Choix des réserves d'une ligne remise (`allocate`) : la réserve désignée si la commande en nomme une, sinon les
 * réserves ouvertes de la ligne de facture (filtrées par lot ou position s'ils sont nommés), expiration la plus proche
 * d'abord (FEFO), lots sans date ensuite, puis identifiant. Une remise ne prend JAMAIS plus que la part ouverte d'une
 * réserve (quantité − consommé − libéré) : le stock d'une autre vente n'est jamais touché.
 */
final class DeliveryRules
{
    public static function remaining(FixedDecimal $invoiced, FixedDecimal $alreadyDelivered): FixedDecimal
    {
        $remaining = $invoiced->minus($alreadyDelivered);

        return $remaining->isNegative() ? Quantity::of('0') : $remaining;
    }

    /**
     * Quantité demandée ≤ reste à remettre de la ligne de facture. Refus 422 avec le reste (`remaining`) à proposer.
     */
    public static function assertWithinRemaining(FixedDecimal $requested, FixedDecimal $invoiced, FixedDecimal $alreadyDelivered): void
    {
        $remaining = self::remaining($invoiced, $alreadyDelivered);
        if ($requested->compare($remaining) > 0) {
            throw RuleViolation::invalid(
                'quantity',
                'delivery.exceeds_remaining',
                $remaining->isZero() ? 'Cette ligne est déjà entièrement remise au patient.' : 'Quantité supérieure au reste à remettre ('.self::label($remaining).').',
                ['remaining' => $remaining->canonical()],
            );
        }
    }

    /**
     * Répartit la quantité remise d'une ligne sur ses réserves ouvertes.
     *
     * @param  list<array{id: string, position_id: string, lot_id: ?string, open: FixedDecimal, expires_on: ?CivilDate}>  $reservations  réserves ACTIVES de la ligne de facture
     * @return list<array{reservation_id: string, position_id: string, lot_id: ?string, quantity: FixedDecimal}>
     *
     * @throws RuleViolation STOCK_UNAVAILABLE si les réserves ouvertes ne suffisent pas (`available` à proposer)
     */
    public static function allocate(FixedDecimal $requested, array $reservations, ?string $reservationId, ?string $lotId, ?string $positionId): array
    {
        Quantity::forLine($requested, false);
        $candidates = array_values(array_filter($reservations, static fn (array $reservation): bool => ! $reservation['open']->isZero() && ! $reservation['open']->isNegative()
            && ($reservationId === null || $reservation['id'] === $reservationId)
            && ($lotId === null || $reservation['lot_id'] === $lotId)
            && ($positionId === null || $reservation['position_id'] === $positionId)));
        usort($candidates, static function (array $left, array $right): int {
            $byDate = match (true) {
                $left['expires_on'] === null && $right['expires_on'] === null => 0,
                $left['expires_on'] === null => 1,
                $right['expires_on'] === null => -1,
                default => $left['expires_on']->compare($right['expires_on']),
            };

            return $byDate !== 0 ? $byDate : strcmp($left['id'], $right['id']);
        });

        $available = Quantity::of('0');
        foreach ($candidates as $candidate) {
            $available = $available->plus($candidate['open']);
        }
        if ($requested->compare($available) > 0) {
            throw new RuleViolation(
                RuleViolation::STOCK_UNAVAILABLE,
                'delivery.reservation_insufficient',
                'quantity',
                $available->isZero()
                    ? 'Aucune réserve ouverte pour cette ligne : l\'article n\'est pas réservé pour cette vente (ou déjà remis).'
                    : 'Quantité supérieure à la réserve ouverte de cette ligne ('.self::label($available).').',
                ['available' => $available->canonical()],
            );
        }

        $allocations = [];
        $left = $requested;
        foreach ($candidates as $candidate) {
            if ($left->isZero()) {
                break;
            }
            $take = $candidate['open']->compare($left) < 0 ? $candidate['open'] : $left;
            $allocations[] = ['reservation_id' => $candidate['id'], 'position_id' => $candidate['position_id'], 'lot_id' => $candidate['lot_id'], 'quantity' => $take];
            $left = $left->minus($take);
        }

        return $allocations;
    }

    public static function returnable(FixedDecimal $delivered, FixedDecimal $alreadyReturned): FixedDecimal
    {
        $returnable = $delivered->minus($alreadyReturned);

        return $returnable->isNegative() ? Quantity::of('0') : $returnable;
    }

    /**
     * Retour ≤ quantité remise non encore retournée de la ligne remise. Refus 422 avec le reste (`returnable`).
     */
    public static function assertReturnable(FixedDecimal $requested, FixedDecimal $delivered, FixedDecimal $alreadyReturned): void
    {
        Quantity::forLine($requested, false);
        $returnable = self::returnable($delivered, $alreadyReturned);
        if ($requested->compare($returnable) > 0) {
            throw RuleViolation::invalid(
                'quantity',
                'return.exceeds_delivered',
                $returnable->isZero() ? 'Toute la quantité remise sur cette ligne est déjà retournée.' : 'Quantité supérieure à la quantité remise non retournée ('.self::label($returnable).').',
                ['returnable' => $returnable->canonical()],
            );
        }
    }

    /**
     * Destination d'un retour : seul un article vendable peut être remis en stock (CHECK `ck_physical_returns_02`), et
     * jamais dans un lot expiré au jour local du retour.
     */
    public static function assertRestockable(string $conditionCode, bool $acceptedForRestock, ?string $lotStatus, ?CivilDate $lotExpiresOn, CivilDate $localDate): void
    {
        if (! $acceptedForRestock) {
            return;
        }
        if ($conditionCode !== 'SELLABLE') {
            throw RuleViolation::invalid('accepted_for_restock', 'return.restock_requires_sellable', 'Seul un article contrôlé vendable est remis en stock.');
        }
        if ($lotExpiresOn !== null && $lotExpiresOn->compare($localDate) < 0) {
            throw new RuleViolation(RuleViolation::LOT_EXPIRED, 'return.lot_expired', 'accepted_for_restock', 'Le lot de cet article est expiré : il ne retourne pas dans le stock vendable.', ['expires_on' => $lotExpiresOn->toString()]);
        }
        if ($lotStatus !== null && $lotStatus !== 'SELLABLE') {
            throw RuleViolation::invalid('accepted_for_restock', 'return.lot_quarantined', 'Le lot de cet article est en quarantaine : il ne retourne pas dans le stock vendable.');
        }
    }

    /** « 1,5 » pour 1.500, « 2 » pour 2.000 (affichage des refus). */
    public static function label(FixedDecimal $quantity): string
    {
        $text = rtrim(rtrim($quantity->canonical(), '0'), '.');

        return str_replace('.', ',', $text === '' ? '0' : $text);
    }
}
