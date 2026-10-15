<?php

namespace App\Application;

use App\Domain\Shared\CommandContext;

final class NullCommandBusProbe implements CommandBusProbe
{
    public function reached(Checkpoint $checkpoint, CommandContext $context): void {}
}
