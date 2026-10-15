<?php

namespace App\Application;

use App\Domain\Shared\CommandContext;

/**
 * Observateur des points du chemin central (voir Checkpoint). En production c'est NullCommandBusProbe : aucun
 * effet. Une sonde qui lève une exception annule la transaction comme n'importe quelle erreur de l'action.
 */
interface CommandBusProbe
{
    public function reached(Checkpoint $checkpoint, CommandContext $context): void;
}
