<?php

namespace App\Domain\Documents;

use App\Application\CommandDefinition;
use App\Domain\Documents\Handlers\StoreDocumentContentHandler;
use App\Domain\Shared\CommandSource;

/**
 * Commandes des documents générés (D01) : dépôt de l'original PDF, EN LIGNE seulement (le poste hors-ligne garde
 * ses originaux dans son coffre et les déposera à la synchronisation, O05).
 */
final class DocumentCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        return [
            new CommandDefinition(
                StoreDocumentContentHandler::COMMAND,
                StoreDocumentContentHandler::class,
                [CommandSource::Direct],
                null,
                true,
                'schemas/requests/http.json#/$defs/StoreDocumentContentCommand',
            ),
        ];
    }
}
