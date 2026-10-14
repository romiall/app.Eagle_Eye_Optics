<?php

namespace App\Providers;

use App\Support\Testing\BlockDestructiveDatabaseCommands;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel as FoundationConsoleKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
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
        $this->useRootRelativeAssetPaths();

        // Aucune commande destructive de base de données hors base de test isolée (contrats 23 et 25).
        Event::listen(CommandStarting::class, [BlockDestructiveDatabaseCommands::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->relayConsoleEvents();
        }
    }

    /**
     * Les assets de la coquille SPA (@vite) sont des chemins depuis la racine du site (« /build/… »), non
     * des URL absolues.
     *
     * Par défaut Laravel écrit des URL absolues déduites de la REQUÊTE (helper asset()) : schéma et hôte
     * reçus, ou ASSET_URL. Derrière un proxy ou un CDN qui termine TLS, la requête arrive en HTTP et la
     * page HTTPS référence http://…/build/…js : le navigateur bloque le script (contenu mixte), la page
     * reste vide et le service worker n'est jamais enregistré. APP_URL n'y change rien, et un en-tête Host
     * arbitraire ferait pointer les assets vers cet hôte.
     *
     * La SPA, ses assets, son service worker et son manifeste sont servis par une seule origine (fichier
     * 23) : un chemin depuis la racine se résout toujours contre l'origine du document, quelle que soit la
     * chaîne de proxys, sans dépendre de trustProxies ni d'un en-tête X-Forwarded-*. ASSET_URL est donc
     * volontairement sans effet sur la coquille : un CDN d'assets sortirait de l'origine unique (portée du
     * service worker, précache, politique de contenu).
     */
    private function useRootRelativeAssetPaths(): void
    {
        Vite::createAssetPathsUsing(
            static fn (string $path, ?bool $secure = null): string => '/'.ltrim($path, '/'),
        );
    }

    /**
     * Laravel ne relaie pas les événements de commande Symfony vers CommandStarting lorsque
     * APP_ENV=testing (Kernel::__construct). Sans ce relais, « APP_ENV=testing php artisan migrate:fresh »
     * contournerait l'écouteur ci-dessus alors que c'est précisément le cas d'une base mal ciblée.
     * La méthode est idempotente.
     *
     * Appel direct, sans test d'existence : si une mise à jour de Laravel retirait cette méthode, la
     * console doit échouer bruyamment plutôt que de laisser la garde silencieusement inactive.
     */
    private function relayConsoleEvents(): void
    {
        /** @var FoundationConsoleKernel $kernel Le noyau lié au contrat : la méthode de relais n'existe pas sur l'interface. */
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->rerouteSymfonyCommandEvents();
    }
}
