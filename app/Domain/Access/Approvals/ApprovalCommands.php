<?php

namespace App\Domain\Access\Approvals;

use App\Application\CommandDefinition;
use App\Domain\Access\Handlers\DecideApprovalHandler;
use App\Domain\Access\Handlers\RequestApprovalHandler;
use App\Domain\Shared\CommandSource;

/**
 * Commandes des approbations exactes (B06) enregistrées auprès du chemin central (F07) : `RequestApproval` et
 * `DecideApproval`, deux commandes du catalogue F06 (charge d'un poste validée par `schema`, corps de la route directe
 * par `directSchema`). Elles admettent la source d'un poste dans le registre, comme l'exige le test de conformité au
 * catalogue, mais leurs actions refusent explicitement toute autre source que la route directe : une gérante présente
 * hors-ligne autorisera par son profil préparé quand le coffre et la synchronisation existeront (O01 à O05).
 */
final class ApprovalCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        $both = [CommandSource::Direct, CommandSource::Sync];

        return [
            new CommandDefinition(
                RequestApprovalHandler::COMMAND,
                RequestApprovalHandler::class,
                $both,
                'commands/RequestApproval.v1.json',
                true,
                'commands/RequestApproval.v1.json#/$defs/DirectRequest',
            ),
            new CommandDefinition(
                DecideApprovalHandler::COMMAND,
                DecideApprovalHandler::class,
                $both,
                'commands/DecideApproval.v1.json',
                true,
                'commands/DecideApproval.v1.json#/$defs/DirectRequest',
            ),
        ];
    }
}
