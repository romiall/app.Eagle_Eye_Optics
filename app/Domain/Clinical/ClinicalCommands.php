<?php

namespace App\Domain\Clinical;

use App\Application\CommandDefinition;
use App\Domain\Clinical\Handlers\SaveClinicalStepHandler;
use App\Domain\Clinical\Handlers\StartConsultationHandler;
use App\Domain\Shared\CommandSource;

/**
 * Commandes de la consultation clinique enregistrées auprès du chemin central (F07) : ce sont des commandes du
 * catalogue F06 (charge d'un poste validée par `schema`, corps de la route directe par `directSchema`).
 *
 * Premier jalon (C01) : démarrage de l'examen et enregistrement des rubriques narratives. `CompleteConsultation`
 * et `AmendConsultation` (C03) ne sont PAS enregistrées : leurs routes restent PLANNED au contrat.
 */
final class ClinicalCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        $both = [CommandSource::Direct, CommandSource::Sync];

        return [
            new CommandDefinition(
                StartConsultationHandler::COMMAND,
                StartConsultationHandler::class,
                $both,
                'commands/StartConsultation.v1.json',
                true,
                'commands/StartConsultation.v1.json#/$defs/DirectRequest',
            ),
            new CommandDefinition(
                SaveClinicalStepHandler::COMMAND,
                SaveClinicalStepHandler::class,
                $both,
                'commands/SaveClinicalStep.v1.json',
                true,
                'commands/SaveClinicalStep.v1.json#/$defs/DirectRequest',
            ),
        ];
    }
}
