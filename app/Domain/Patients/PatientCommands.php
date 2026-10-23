<?php

namespace App\Domain\Patients;

use App\Application\CommandDefinition;
use App\Domain\Patients\Handlers\CancelVisitHandler;
use App\Domain\Patients\Handlers\CreatePatientHandler;
use App\Domain\Patients\Handlers\MergePatientsHandler;
use App\Domain\Patients\Handlers\RegisterVisitHandler;
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
                RegisterVisitHandler::COMMAND,
                RegisterVisitHandler::class,
                $both,
                'commands/RegisterVisit.v1.json',
                true,
                'commands/RegisterVisit.v1.json#/$defs/DirectRequest',
            ),
            new CommandDefinition(
                UpdatePatientHandler::COMMAND,
                UpdatePatientHandler::class,
                $both,
                'commands/UpdatePatient.v1.json',
                true,
                'commands/UpdatePatient.v1.json#/$defs/DirectRequest',
            ),
            // Annulation d'une visite avant l'examen (P03) : en ligne seulement, hors du journal d'un poste ; corps de
            // la route directe validé contre sa requête HTTP (aucune charge de poste au catalogue F06).
            new CommandDefinition(
                CancelVisitHandler::COMMAND,
                CancelVisitHandler::class,
                [CommandSource::Direct],
                null,
                true,
                'schemas/requests/http.json#/$defs/CancelVisitRequest',
            ),
            // Fusion manuelle de deux fiches (L1D08), par la gérante après revue : en ligne seulement, corps de la
            // route directe validé contre sa requête HTTP.
            new CommandDefinition(
                MergePatientsHandler::COMMAND,
                MergePatientsHandler::class,
                [CommandSource::Direct],
                null,
                true,
                'schemas/requests/http.json#/$defs/MergePatientsRequest',
            ),
        ];
    }
}
