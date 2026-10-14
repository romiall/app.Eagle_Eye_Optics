<?php

namespace App\Support\Testing;

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Écouteur de CommandStarting : refuse toute commande artisan qui détruit des données de base de
 * données (BLOCKED_COMMANDS : migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback et
 * db:wipe) sauf si la connexion visée est une base de test isolée (voir IsolatedDatabaseGuard).
 *
 * migrate:rollback exécute les méthodes down() des migrations du dernier lot : sans cette garde, une
 * erreur d'environnement suffirait à supprimer des tables en production. Le nom testé est celui de la
 * commande réellement résolue par Symfony : une abréviation (« migrate:rollb ») ou un alias n'y échappe pas.
 *
 * L'exception est levée avant l'exécution de la commande, y compris pour Artisan::call().
 */
final class BlockDestructiveDatabaseCommands
{
    public const BLOCKED_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
    ];

    /**
     * @throws DestructiveDatabaseOperationRefused
     */
    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::BLOCKED_COMMANDS, true)) {
            return;
        }

        IsolatedDatabaseGuard::forConnection(
            (string) app()->environment(),
            $this->connectionName($event->input),
        )->assertAllows($event->command);
    }

    private function connectionName(InputInterface $input): string
    {
        $requested = $input->getParameterOption('--database', null, true);

        return is_string($requested) && $requested !== ''
            ? $requested
            : (string) config('database.default');
    }
}
