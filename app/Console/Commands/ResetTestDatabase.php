<?php

namespace App\Console\Commands;

use App\Support\Testing\IsolatedDatabaseGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Procédure dédiée de remise à zéro des bases de test (contrats 23 : « Réinitialiser uniquement les bases
 * isolées de tests par une procédure dédiée qui vérifie environnement et nom de base »).
 *
 * Elle n'agit que si IsolatedDatabaseGuard l'autorise : APP_ENV=testing, base « *_test », hôte local.
 */
#[Signature('eeo:test-db:reset
    {--database= : Nom de la connexion (par défaut : connexion par défaut)}
    {--migrate : Rejoue les migrations après la remise à zéro}')]
#[Description('Vide une base MySQL de test isolée (refusé partout ailleurs)')]
final class ResetTestDatabase extends Command
{
    public function handle(): int
    {
        $option = $this->option('database');
        $connection = is_string($option) && $option !== '' ? $option : (string) config('database.default');

        $guard = IsolatedDatabaseGuard::forConnection((string) app()->environment(), $connection);

        if (! $guard->allows()) {
            $this->components->error('Remise à zéro refusée : la connexion « '.$connection.' » n\'est pas une base de test isolée.');
            $this->components->bulletList($guard->violations());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Remise à zéro de la base de test isolée « %s ».', $guard->database));

        // La garde ci-dessus est le verrou de cette procédure ; db:wipe, appelé en sous-commande, ne
        // repasse pas par l'écouteur CommandStarting (qui protège les invocations directes en CLI).
        $this->call('db:wipe', ['--database' => $connection, '--drop-views' => true, '--force' => true]);

        if ($this->option('migrate')) {
            $this->call('migrate', ['--database' => $connection, '--force' => true]);
        }

        return self::SUCCESS;
    }
}
