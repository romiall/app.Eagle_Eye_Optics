<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\RuleViolation;
use RuntimeException;

/**
 * Réserve refusée pour UNE ligne (stock insuffisant, lot expiré ou en quarantaine, quota épuisé). L'action qui a
 * demandé la réserve la traduit en refus d'API en désignant le champ de la ligne dans SA charge : le service de stock
 * ne connaît ni la forme de la requête ni l'index de la ligne.
 */
final class ReservationRefused extends RuntimeException
{
    public function __construct(public readonly RuleViolation $violation, public readonly string $invoiceLineId)
    {
        parent::__construct($violation->getMessage(), 0, $violation);
    }

    /**
     * Même statut que `RuleViolation::toBusinessError()` (LOT_EXPIRED et saisie en 422, stock en 409), avec les
     * valeurs actualisées à proposer (`available`) et la ligne en cause.
     */
    public function toBusinessError(string $field): BusinessError
    {
        $status = in_array($this->violation->errorCode, [RuleViolation::VALIDATION_FAILED, RuleViolation::LOT_EXPIRED], true) ? 422 : 409;

        return new BusinessError(
            $this->violation->errorCode,
            $this->violation->getMessage(),
            $status,
            [$field => [$this->violation->getMessage()]],
            $this->violation,
            [],
            $this->violation->details + ['invoice_line_id' => $this->invoiceLineId],
        );
    }
}
