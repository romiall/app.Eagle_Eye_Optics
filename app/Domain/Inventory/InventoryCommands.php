<?php

namespace App\Domain\Inventory;

use App\Application\CommandDefinition;
use App\Domain\Inventory\Handlers\ConfirmDeliveryHandler;
use App\Domain\Inventory\Handlers\RegisterOpeningStockHandler;
use App\Domain\Inventory\Handlers\RegisterPhysicalReturnHandler;
use App\Domain\Inventory\Handlers\RegisterStockMovementHandler;
use App\Domain\Shared\CommandSource;

/**
 * Commandes du stock enregistrées auprès du chemin central (F07). Le stock initial est une opération EN LIGNE
 * seulement, hors du catalogue des 26 commandes d'un poste (contrat F06 : `POST /stock/opening`, requête de
 * `schemas/requests/http.json`) : elle ne déclare pas de schéma de commande et valide elle-même son corps. La clé
 * d'idempotence est obligatoire (les mouvements portent l'identifiant de la commande, `stock_movements.idempotency_id`).
 *
 * Trois commandes du catalogue F06 (I01, I02), disponibles hors-ligne à terme : `ConfirmDelivery` (remise au patient,
 * module `sales`), `RegisterPhysicalReturn` et `RegisterStockMovement` (module `stock`). Comme les commandes de la
 * facturation, elles admettent la source d'un poste dans le registre (conformité au catalogue) mais refusent
 * explicitement toute source autre que la route directe tant que les quotas de stock ne sont pas jugés (O05).
 *
 * Il n'existe volontairement AUCUNE commande « réserver du stock » : la réserve est un service interne d'IssueInvoice,
 * consommé par ConfirmDelivery (`ReservationService`).
 */
final class InventoryCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        $both = [CommandSource::Direct, CommandSource::Sync];

        return [
            new CommandDefinition(
                RegisterOpeningStockHandler::COMMAND,
                RegisterOpeningStockHandler::class,
                [CommandSource::Direct],
                null,
                true,
            ),
            new CommandDefinition(
                ConfirmDeliveryHandler::COMMAND,
                ConfirmDeliveryHandler::class,
                $both,
                'commands/ConfirmDelivery.v1.json',
                true,
                'commands/ConfirmDelivery.v1.json#/$defs/DirectRequest',
            ),
            new CommandDefinition(
                RegisterPhysicalReturnHandler::COMMAND,
                RegisterPhysicalReturnHandler::class,
                $both,
                'commands/RegisterPhysicalReturn.v1.json',
                true,
                'commands/RegisterPhysicalReturn.v1.json#/$defs/DirectRequest',
            ),
            new CommandDefinition(
                RegisterStockMovementHandler::COMMAND,
                RegisterStockMovementHandler::class,
                $both,
                'commands/RegisterStockMovement.v1.json',
                true,
                'commands/RegisterStockMovement.v1.json#/$defs/DirectRequest',
            ),
        ];
    }
}
