<?php

namespace App\Domain\Access;

use App\Application\CommandDefinition;
use App\Domain\Access\Handlers\CreateStaffMemberHandler;
use App\Domain\Access\Handlers\ResetStaffPasswordHandler;
use App\Domain\Access\Handlers\SetStaffPermissionOverrideHandler;
use App\Domain\Access\Handlers\UpdateClinicSettingsHandler;
use App\Domain\Access\Handlers\UpdateStaffMemberHandler;
use App\Domain\Shared\CommandSource;

/**
 * Commandes d'administration des comptes et du cabinet enregistrées auprès du chemin central (F07).
 *
 * Ce sont des opérations EN LIGNE seulement (route directe, jamais le journal d'un poste) : pas de schéma de
 * commande dans contracts/commands/ (les 26 commandes de F06 sont celles qui existent hors-ligne), pas de clé
 * d'idempotence imposée. Leur idempotence tient à la version attendue (`expected_version`, exigée sauf à la
 * création) et à l'unicité de `login` et `email` ; la validation reste celle de l'action qui les appelle.
 */
final class AccessCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        $onlineOnly = [CommandSource::Direct];

        return [
            new CommandDefinition(CreateStaffMemberHandler::COMMAND, CreateStaffMemberHandler::class, $onlineOnly),
            new CommandDefinition(UpdateStaffMemberHandler::COMMAND, UpdateStaffMemberHandler::class, $onlineOnly),
            new CommandDefinition(SetStaffPermissionOverrideHandler::COMMAND, SetStaffPermissionOverrideHandler::class, $onlineOnly),
            new CommandDefinition(ResetStaffPasswordHandler::COMMAND, ResetStaffPasswordHandler::class, $onlineOnly),
            new CommandDefinition(UpdateClinicSettingsHandler::COMMAND, UpdateClinicSettingsHandler::class, $onlineOnly),
        ];
    }
}
