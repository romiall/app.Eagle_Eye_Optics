<?php

namespace Tests\Feature\Database;

use App\Support\Testing\IsolatedDatabaseGuard;
use Tests\TestCase;

/**
 * Lecture de la configuration réelle de Laravel par la garde (sans connexion à MySQL).
 */
final class IsolatedDatabaseGuardConfigTest extends TestCase
{
    public function test_la_connexion_de_test_par_defaut_est_une_base_isolee(): void
    {
        $guard = IsolatedDatabaseGuard::forConnection($this->app->environment(), (string) config('database.default'));

        $this->assertSame([], $guard->violations());
        $this->assertStringEndsWith('_test', $guard->database);
    }

    public function test_une_connexion_inconnue_est_refusee(): void
    {
        $guard = IsolatedDatabaseGuard::forConnection('testing', 'inexistante');

        $this->assertFalse($guard->allows());
    }

    public function test_un_hote_de_lecture_distant_est_refuse_meme_si_l_hote_principal_est_local(): void
    {
        config([
            'database.connections.split' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'read' => ['host' => ['replica.example.com']],
                'write' => ['host' => '127.0.0.1'],
                'database' => 'eeo_test',
            ],
        ]);

        $guard = IsolatedDatabaseGuard::forConnection('testing', 'split');

        $this->assertFalse($guard->allows());
        $this->assertStringContainsString('replica.example.com', implode(' | ', $guard->violations()));
    }

    public function test_une_connexion_par_socket_unix_est_locale(): void
    {
        config([
            'database.connections.socket' => [
                'driver' => 'mysql',
                'host' => '',
                'unix_socket' => '/var/run/mysqld/mysqld.sock',
                'database' => 'eeo_test',
            ],
        ]);

        $this->assertTrue(IsolatedDatabaseGuard::forConnection('testing', 'socket')->allows());
    }

    public function test_une_connexion_sans_hote_ni_socket_est_refusee(): void
    {
        config([
            'database.connections.nohost' => [
                'driver' => 'mysql',
                'host' => '',
                'unix_socket' => '',
                'database' => 'eeo_test',
            ],
        ]);

        $this->assertFalse(IsolatedDatabaseGuard::forConnection('testing', 'nohost')->allows());
    }
}
