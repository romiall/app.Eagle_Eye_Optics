<?php

namespace Tests\Support\Commands;

use App\Application\CommandDefinition;
use App\Application\CommandRegistry;
use App\Domain\Shared\CommandSource;

/**
 * Enregistre les commandes de TEST auprès du chemin central. Elles ne font pas partie du catalogue F06 : elles
 * servent à prouver le mécanisme (idempotence, verrous, retour arrière, publication) avant que les commandes
 * métier n'existent.
 */
final class TestCommands
{
    /** Clé d'idempotence facultative. */
    public const CONSUME = 'TestConsumeCounter';

    /** Clé d'idempotence obligatoire. */
    public const CONSUME_STRICT = 'TestConsumeCounterStrict';

    /** Refuse la synchronisation et les tâches : route directe seulement. */
    public const CONSUME_DIRECT_ONLY = 'TestConsumeCounterDirectOnly';

    /** Admet les tâches planifiées. */
    public const CONSUME_JOB = 'TestConsumeCounterJob';

    public static function register(CommandRegistry $registry): void
    {
        ConsumeCounterHandler::$handled = 0;

        $registry->register(new CommandDefinition(self::CONSUME, ConsumeCounterHandler::class, [CommandSource::Direct, CommandSource::Sync]));
        $registry->register(new CommandDefinition(self::CONSUME_STRICT, ConsumeCounterHandler::class, [CommandSource::Direct, CommandSource::Sync], null, true));
        $registry->register(new CommandDefinition(self::CONSUME_DIRECT_ONLY, ConsumeCounterHandler::class, [CommandSource::Direct]));
        $registry->register(new CommandDefinition(self::CONSUME_JOB, ConsumeCounterHandler::class, [CommandSource::Direct, CommandSource::Job]));
    }
}
