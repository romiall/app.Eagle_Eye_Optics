<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\ValueObjects\FixedDecimal;

/**
 * Quantité d'un article à réserver pour une ligne de facture. Un article « sur mesure » ou une prestation n'a jamais
 * de demande de réserve : seul un article géré en stock (`stock_mode = STOCKED`) en produit une.
 */
final readonly class ReservationRequest
{
    public function __construct(
        public string $invoiceLineId,
        public string $itemId,
        public FixedDecimal $quantity,
        public bool $requiresExpiry,
    ) {}
}
