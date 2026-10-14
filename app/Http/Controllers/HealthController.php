<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Sonde de santé GET /up : hébergeur, supervision, démarrage des tests e2e (build.md).
 *
 * Route maison à la place de `health:` de Laravel. Celle-ci rend une vue Blade qui charge un script
 * Tailwind depuis cdn.jsdelivr.net et des fontes depuis fonts.bunny.net, sur la même origine que la
 * SPA, son service worker et le coffre local (IndexedDB, Cache Storage) : un script tiers compromis y
 * aurait accès aux données du cabinet. La réponse est donc un JSON minimal, sans vue, sans session ni
 * cookie, jamais mis en cache, et ne divulgue jamais le message d'une exception.
 *
 * Les contrôles de disponibilité (base de données, stockage, file) s'ajoutent comme écouteurs de
 * DiagnosingHealth ; une exception levée par l'un d'eux est journalisée et donne un 500.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            Event::dispatch(new DiagnosingHealth);
        } catch (Throwable $exception) {
            report($exception);

            return $this->respond('error', 500);
        }

        return $this->respond('ok', 200);
    }

    private function respond(string $status, int $httpStatus): JsonResponse
    {
        return response()
            ->json(['status' => $status], $httpStatus)
            ->header('Cache-Control', 'no-store');
    }
}
