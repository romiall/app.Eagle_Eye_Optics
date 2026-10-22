<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\ValueObjects\FixedDecimal;

/**
 * Réserve de stock écrite (`stock_reservations`) : centrale (`deviceAllocationId` null) ou sous le quota local d'un
 * poste. Les identifiants sont ceux des lignes écrites, reprenables par une réponse ou un ACK.
 */
final readonly class PlannedReservation
{
    public function __construct(
        public string $reservationId,
        public string $invoiceLineId,
        public string $itemId,
        public string $stockPositionId,
        public ?string $stockLotId,
        public ?string $deviceAllocationId,
        public FixedDecimal $quantity,
    ) {}
}
