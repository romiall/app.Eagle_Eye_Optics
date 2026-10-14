<?php

namespace Tests\Support;

use App\Support\Testing\IsolatedDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prérequis des tests qui ont besoin du vrai MySQL isolé (InnoDB, verrous, guards).
 *
 * - Si la connexion ne désigne pas une base de test isolée : ÉCHEC immédiat (jamais d'exécution
 *   contre une autre base).
 * - Si MySQL est injoignable : test IGNORÉ avec un message explicite, ou ÉCHEC lorsque
 *   EEO_REQUIRE_MYSQL=1 (CI et tools/dev/check-all.sh --strict).
 */
trait RequiresIsolatedMysql
{
    protected function requireIsolatedMysql(?string $connection = null): void
    {
        $connection ??= (string) config('database.default');

        $guard = IsolatedDatabaseGuard::forConnection($this->app->environment(), $connection);
        $this->assertSame(
            [],
            $guard->violations(),
            'La connexion de test « '.$connection.' » n\'est pas une base de test isolée : exécution refusée.',
        );

        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable $exception) {
            $message = sprintf(
                'MySQL de test injoignable (%s, base %s) : lancer tools/dev/mysql-up.sh. Cause : %s',
                implode(',', $guard->hosts),
                $guard->database,
                $exception->getMessage(),
            );

            if (getenv('EEO_REQUIRE_MYSQL') === '1') {
                $this->fail($message);
            }

            $this->markTestSkipped($message);
        }
    }
}
