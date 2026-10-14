<?php

namespace App\Providers;

use App\Support\Testing\BlockDestructiveDatabaseCommands;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Aucune commande destructive de base de données hors base de test isolée (contrats 23 et 25).
        Event::listen(CommandStarting::class, [BlockDestructiveDatabaseCommands::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->relayConsoleEvents();
        }
    }

    /**
     * Laravel ne relaie pas les événements de commande Symfony vers CommandStarting lorsque
     * APP_ENV=testing (Kernel::__construct). Sans ce relais, « APP_ENV=testing php artisan migrate:fresh »
     * contournerait l'écouteur ci-dessus alors que c'est précisément le cas d'une base mal ciblée.
     * La méthode est idempotente.
     */
    private function relayConsoleEvents(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);

        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }
    }
}
