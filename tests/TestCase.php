<?php

namespace Tests;

use App\Support\Testing\IsolatedDatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Filet de sécurité fermé par défaut : aucun test d'application ne démarre si l'environnement
     * n'est pas « testing » ou si la connexion par défaut ne désigne pas une base de test isolée
     * (nom « *_test », hôte local). Laravel n'installe pas l'écouteur CommandStarting pendant les
     * tests ; cette vérification protège donc aussi RefreshDatabase et migrate:fresh lancés par un test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'testing',
            $this->app->environment(),
            'Les tests doivent s\'exécuter avec APP_ENV=testing.',
        );

        $guard = IsolatedDatabaseGuard::forConnection($this->app->environment(), (string) config('database.default'));
        $this->assertSame(
            [],
            $guard->violations(),
            'La connexion de test par défaut n\'est pas une base de test isolée (voir EEO_TEST_DB_DATABASE) : exécution refusée.',
        );
    }
}
