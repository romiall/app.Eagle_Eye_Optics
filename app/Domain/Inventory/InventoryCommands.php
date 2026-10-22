<?php

namespace App\Domain\Inventory;

use App\Application\CommandDefinition;
use App\Domain\Inventory\Handlers\RegisterOpeningStockHandler;
use App\Domain\Shared\CommandSource;

/**
 * Commandes du stock enregistrées auprès du chemin central (F07). Le stock initial est une opération EN LIGNE
 * seulement, hors du catalogue des 26 commandes d'un poste (contrat F06 : `POST /stock/opening`, requête de
 * `schemas/requests/http.json`) : elle ne déclare pas de schéma de commande et valide elle-même son corps. La clé
 * d'idempotence est obligatoire (les mouvements portent l'identifiant de la commande, `stock_movements.idempotency_id`).
 *
 * Il n'existe volontairement AUCUNE commande « réserver du stock » : la réserve est un service interne d'IssueInvoice
 * (et plus tard de ConfirmDelivery), `ReservationService`.
 */
final class InventoryCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        return [
            new CommandDefinition(
                RegisterOpeningStockHandler::COMMAND,
                RegisterOpeningStockHandler::class,
                [CommandSource::Direct],
                null,
                true,
            ),
        ];
    }
}
