<?php

namespace Tests\Unit\Support\Testing;

use App\Support\Testing\DestructiveDatabaseOperationRefused;
use App\Support\Testing\IsolatedDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règle pure de la garde : aucune base, aucun framework. Chaque refus correspond à un risque
 * concret (effacer une base de production ou de développement).
 */
final class IsolatedDatabaseGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function isolatedCases(): array
    {
        return [
            'base eeo_test locale' => ['testing', 'eeo_test', ['127.0.0.1']],
            'localhost' => ['testing', 'eeo_test', ['localhost']],
            'localhost en majuscules' => ['testing', 'eeo_test', ['LOCALHOST']],
            'IPv6 locale' => ['testing', 'eeo_test', ['::1']],
            'service mysql de CI' => ['testing', 'eeo_test', ['mysql']],
            'autre base jetable' => ['testing', 'eeo_reset_probe_test', ['127.0.0.1']],
            'lecture et écriture locales' => ['testing', 'eeo_test', ['127.0.0.1', 'localhost']],
        ];
    }

    /**
     * @param  list<string>  $hosts
     */
    #[DataProvider('isolatedCases')]
    public function test_autorise_une_base_de_test_locale_en_environnement_testing(string $environment, string $database, array $hosts): void
    {
        $guard = new IsolatedDatabaseGuard($environment, $database, $hosts);

        $this->assertSame([], $guard->violations());
        $this->assertTrue($guard->allows());
        $guard->assertAllows('migrate:fresh');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    public static function refusedCases(): array
    {
        return [
            'production, même sur eeo_test' => ['production', 'eeo_test', ['127.0.0.1'], 'l\'environnement « production »'],
            'local, même sur eeo_test' => ['local', 'eeo_test', ['127.0.0.1'], 'l\'environnement « local »'],
            'staging' => ['staging', 'eeo_test', ['127.0.0.1'], 'l\'environnement « staging »'],
            'environnement vide' => ['', 'eeo_test', ['127.0.0.1'], 'n\'est pas « testing »'],
            'Testing avec majuscule' => ['Testing', 'eeo_test', ['127.0.0.1'], 'l\'environnement « Testing »'],
            'base de production' => ['testing', 'eagle_eye_optics', ['127.0.0.1'], 'ne se termine pas par « _test »'],
            'suffixe _test non final' => ['testing', 'eeo_test_backup', ['127.0.0.1'], 'ne se termine pas par « _test »'],
            'nom réduit au suffixe' => ['testing', '_test', ['127.0.0.1'], 'ne se termine pas par « _test »'],
            'sans préfixe' => ['testing', 'test', ['127.0.0.1'], 'ne se termine pas par « _test »'],
            'majuscules' => ['testing', 'EEO_TEST', ['127.0.0.1'], 'ne se termine pas par « _test »'],
            'caractère non admis' => ['testing', 'eeo`; DROP DATABASE x; --_test', ['127.0.0.1'], 'caractères non admis'],
            'base vide' => ['testing', '', ['127.0.0.1'], 'ne se termine pas par « _test »'],
            'hôte distant' => ['testing', 'eeo_test', ['db.example.com'], 'l\'hôte « db.example.com » n\'est pas local'],
            'adresse privée distante' => ['testing', 'eeo_test', ['10.0.0.5'], 'n\'est pas local'],
            'adresse publique' => ['testing', 'eeo_test', ['203.0.113.10'], 'n\'est pas local'],
            'un hôte local et un distant' => ['testing', 'eeo_test', ['127.0.0.1', 'replica.example.com'], 'l\'hôte « replica.example.com » n\'est pas local'],
            'préfixe d\'hôte trompeur' => ['testing', 'eeo_test', ['localhost.example.com'], 'n\'est pas local'],
            'aucun hôte' => ['testing', 'eeo_test', [], 'aucun hôte'],
        ];
    }

    /**
     * @param  list<string>  $hosts
     */
    #[DataProvider('refusedCases')]
    public function test_refuse_tout_ce_qui_n_est_pas_une_base_de_test_isolee(string $environment, string $database, array $hosts, string $expectedViolation): void
    {
        $guard = new IsolatedDatabaseGuard($environment, $database, $hosts);

        $this->assertFalse($guard->allows());
        $this->assertStringContainsString($expectedViolation, implode(' | ', $guard->violations()));
    }

    public function test_refuse_un_pilote_autre_que_mysql_meme_en_memoire(): void
    {
        $guard = new IsolatedDatabaseGuard('testing', 'eeo_test', ['127.0.0.1'], 'sqlite');

        $this->assertFalse($guard->allows());
        $this->assertStringContainsString('n\'est pas MySQL', implode(' | ', $guard->violations()));
    }

    public function test_cumule_tous_les_motifs_de_refus(): void
    {
        $guard = new IsolatedDatabaseGuard('production', 'eagle_eye_optics', ['db.example.com']);

        $this->assertCount(3, $guard->violations());
    }

    public function test_l_exception_nomme_l_operation_et_indique_la_procedure_dediee(): void
    {
        $guard = new IsolatedDatabaseGuard('production', 'eagle_eye_optics', ['127.0.0.1']);

        try {
            $guard->assertAllows('migrate:fresh');
            $this->fail('La garde aurait dû refuser migrate:fresh en production.');
        } catch (DestructiveDatabaseOperationRefused $exception) {
            $this->assertStringContainsString('migrate:fresh', $exception->getMessage());
            $this->assertStringContainsString('production', $exception->getMessage());
            $this->assertStringContainsString('eeo:test-db:reset', $exception->getMessage());
        }
    }
}
