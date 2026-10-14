<?php

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Pas de `health: '/up'` : la vue par défaut de Laravel charge un script et des fontes de CDN
        // tiers sur l'origine de l'application. La sonde maison est déclarée ci-dessous.
        apiPrefix: 'api',
        then: function (): void {
            // Sonde de santé : sans vue, sans session ni cookie (hors du groupe `web`). Déclarée avant
            // le repli SPA, qui accepterait sinon « /up » comme URL du routeur Vue.
            Route::get('/up', HealthController::class)->name('health');

            // Coquille SPA : volontairement hors du groupe `web` (ni session, ni cookie) afin que
            // la réponse soit identique pour tous et précachable par le service worker.
            // Déclarée en dernier : c'est la route de repli, jamais appliquée à /api.
            Route::group([], base_path('routes/spa.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // La sonde reste joignable en mode maintenance, comme le `health:` de Laravel qu'elle remplace.
        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        // Session SPA Sanctum (cookie du seul domaine applicatif + CSRF), sans jeton d'API personnel.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toute erreur sous /api est du JSON, quel que soit l'en-tête Accept (jamais la coquille SPA).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );
    })->create();
