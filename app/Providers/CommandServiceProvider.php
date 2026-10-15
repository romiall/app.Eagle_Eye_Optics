<?php

namespace App\Providers;

use App\Application\Authorization\CommandAuthorizer;
use App\Application\CommandBus;
use App\Application\CommandBusProbe;
use App\Application\CommandRegistry;
use App\Application\Contracts\ContractValidator;
use App\Application\NullCommandBusProbe;
use App\Application\TransactionRunner;
use App\Domain\Access\AccessCommands;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Chemin central des commandes (F07, contrat 23) : registre des commandes, exécuteur de transactions, validateur
 * de contrats, autorisation et bus. Le registre est UNIQUE par application : toute commande y est déclarée une
 * fois, avec son action, ses sources admises et son schéma de contrat.
 */
final class CommandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommandRegistry::class);
        $this->app->singleton(ContractValidator::class);

        $this->app->singleton(TransactionRunner::class, static fn (Application $app): TransactionRunner => new TransactionRunner(
            $app->make('db'),
            $app,
            (int) config('eeo.transactions.max_attempts', 3),
            (int) config('eeo.transactions.lock_wait_timeout_seconds', 10),
        ));

        // Droits effectifs relus à chaque requête (EffectivePermissions est lui aussi « scoped »).
        $this->app->scoped(CommandAuthorizer::class);

        // Sonde d'observation des points du chemin : sans effet en production, remplacée par les tests.
        $this->app->bind(CommandBusProbe::class, NullCommandBusProbe::class);

        // Pas de singleton : le bus est reconstruit avec la sonde liée à cet instant.
        $this->app->bind(CommandBus::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(CommandRegistry::class);

        // Chaque module déclare ici ses commandes ; une commande non enregistrée n'existe pas.
        foreach (AccessCommands::definitions() as $definition) {
            $registry->register($definition);
        }
    }
}
