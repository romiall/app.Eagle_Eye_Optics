<?php

namespace Tests\Support\Commands;

use App\Application\Checkpoint;
use App\Application\CommandBusProbe;
use App\Domain\Shared\CommandContext;
use RuntimeException;

/**
 * Sonde de test : note les points atteints et, si demandé, lève une erreur à l'un d'eux pour prouver que tout
 * ce qui était déjà écrit est annulé.
 */
final class RecordingProbe implements CommandBusProbe
{
    /** @var list<string> */
    public array $reached = [];

    public function __construct(private readonly ?Checkpoint $failAt = null) {}

    public function reached(Checkpoint $checkpoint, CommandContext $context): void
    {
        $this->reached[] = $checkpoint->value;

        if ($this->failAt === $checkpoint) {
            throw new RuntimeException('Erreur injectée au point '.$checkpoint->value);
        }
    }
}
