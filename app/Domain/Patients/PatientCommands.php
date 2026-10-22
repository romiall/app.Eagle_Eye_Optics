<?php

namespace App\Domain\Patients;

use App\Application\CommandDefinition;
use App\Domain\Patients\Handlers\CreatePatientHandler;
use App\Domain\Patients\Handlers\UpdatePatientHandler;
use App\Domain\Shared\CommandSource;

/**
 * Commandes du dossier patient (P01) enregistrées auprès du chemin central (F07). Ce sont des commandes du
 * catalogue F06 : charge de poste validée par `schema`, corps de la route directe par `directSchema`.
 */
final class PatientCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        $both = [CommandSource::Direct, CommandSource::Sync];

        return [
            new CommandDefinition(
                CreatePatientHandler::COMMAND,
                CreatePatientHandler::class,
                $both,
                'commands/CreatePatient.v1.json',
                true,
                'commands/CreatePatient.v1.json#/$defs/DirectRequest',
            ),
            new CommandDefinition(
                UpdatePatientHandler::COMMAND,
                UpdatePatientHandler::class,
                $both,
                'commands/UpdatePatient.v1.json',
                true,
                'commands/UpdatePatient.v1.json#/$defs/DirectRequest',
            ),
        ];
    }
}
