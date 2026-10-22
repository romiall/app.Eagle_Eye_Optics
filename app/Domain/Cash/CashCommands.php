<?php

namespace App\Domain\Cash;

use App\Application\CommandDefinition;
use App\Domain\Cash\Handlers\CloseCashSessionHandler;
use App\Domain\Cash\Handlers\OpenCashSessionHandler;
use App\Domain\Shared\CommandSource;

/**
 * Sessions de caisse (B05) enregistrées auprès du chemin central (F07). Ce sont deux commandes du catalogue F06
 * (charge d'un poste validée par `schema`, corps de la route directe par `directSchema`). Elles admettent la source
 * d'un poste dans le registre, comme l'exige le test de conformité au catalogue, mais leurs actions refusent
 * explicitement toute source autre que la route directe tant que la synchronisation ne les juge pas (O03, O05).
 */
final class CashCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        $both = [CommandSource::Direct, CommandSource::Sync];

        return [
            new CommandDefinition(
                OpenCashSessionHandler::COMMAND,
                OpenCashSessionHandler::class,
                $both,
                'commands/OpenCashSession.v1.json',
                true,
                'commands/OpenCashSession.v1.json#/$defs/DirectRequest',
            ),
            new CommandDefinition(
                CloseCashSessionHandler::COMMAND,
                CloseCashSessionHandler::class,
                $both,
                'commands/CloseCashSession.v1.json',
                true,
                'commands/CloseCashSession.v1.json#/$defs/DirectRequest',
            ),
        ];
    }
}
