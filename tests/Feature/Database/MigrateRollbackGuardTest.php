<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\RequiresIsolatedMysql;
use Tests\TestCase;
use Throwable;

/**
 * Preuve sur MySQL réel que « migrate:rollback » n'exécute pas down() hors d'une base de test isolée.
 *
 * Défaut d'origine : la garde ne couvrait pas migrate:rollback ; avec APP_ENV=production et une base
 * quelconque, « php artisan migrate:rollback --force » supprimait les tables du dernier lot. Ici une
 * vraie migration de sonde (tests/fixtures/migrations) crée une table, puis un vrai processus artisan
 * tente l'annulation « comme en production » : la table doit survivre.
 *
 * La base jetable « eeo_rollback_probe_test » est créée puis supprimée par le test ; seule une base
 * « *_test » peut l'être avec les droits de l'utilisateur de test, ce qui exclut tout risque pour une
 * autre base. Le nom non-« _test » est couvert sans connexion par DestructiveCommandsBlockedTest.
 */
final class MigrateRollbackGuardTest extends TestCase
{
    use RequiresIsolatedMysql;

    private const PROBE_DATABASE = 'eeo_rollback_probe_test';

    private const PROBE_TABLE = 'rollback_guard_probe';

    private const FIXTURE_MIGRATIONS = 'tests/fixtures/migrations';

    private bool $probeCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireIsolatedMysql();

        try {
            DB::statement('DROP DATABASE IF EXISTS `'.self::PROBE_DATABASE.'`');
            DB::statement('CREATE DATABASE `'.self::PROBE_DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
            $this->probeCreated = true;
        } catch (Throwable $exception) {
            $message = 'Droit CREATE DATABASE sur '.self::PROBE_DATABASE.' absent (tools/dev/mysql-up.sh l\'accorde) : '.$exception->getMessage();

            if (getenv('EEO_REQUIRE_MYSQL') === '1') {
                $this->fail($message);
            }

            $this->markTestSkipped($message);
        }
    }

    protected function tearDown(): void
    {
        if ($this->probeCreated) {
            DB::statement('DROP DATABASE IF EXISTS `'.self::PROBE_DATABASE.'`');
        }

        parent::tearDown();
    }

    /**
     * Lance un vrai processus « php artisan » (sans la couche de test de Laravel) visant la base de sonde.
     */
    private function runArtisanProcess(string $environment, string ...$arguments): Process
    {
        $connection = (array) config('database.connections.mysql');

        $process = new Process([PHP_BINARY, 'artisan', ...$arguments], base_path(), [
            'APP_ENV' => $environment,
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => self::PROBE_DATABASE,
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
        ]);
        $process->run();

        return $process;
    }

    private function combinedOutput(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    private function probeTableExists(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [self::PROBE_DATABASE, self::PROBE_TABLE],
        );

        return (int) $row->total === 1;
    }

    private function migrateProbe(): void
    {
        $migrate = $this->runArtisanProcess('testing', 'migrate', '--path='.self::FIXTURE_MIGRATIONS, '--force');

        $this->assertSame(0, $migrate->getExitCode(), $this->combinedOutput($migrate));
        $this->assertTrue($this->probeTableExists(), 'La migration de sonde aurait dû créer la table.');
    }

    public function test_migrate_rollback_en_production_est_refuse_et_la_table_survit(): void
    {
        $this->migrateProbe();

        $rollback = $this->runArtisanProcess('production', 'migrate:rollback', '--path='.self::FIXTURE_MIGRATIONS, '--force');

        $this->assertNotSame(0, $rollback->getExitCode(), $this->combinedOutput($rollback));
        $this->assertStringContainsString('Opération destructive refusée', $this->combinedOutput($rollback));
        $this->assertStringContainsString('migrate:rollback', $this->combinedOutput($rollback));
        $this->assertTrue($this->probeTableExists(), 'down() a été exécuté : la table de sonde a disparu malgré APP_ENV=production.');
    }

    public function test_une_abreviation_de_migrate_rollback_est_refusee_de_la_meme_facon(): void
    {
        $this->migrateProbe();

        $rollback = $this->runArtisanProcess('production', 'migrate:rollb', '--path='.self::FIXTURE_MIGRATIONS, '--force');

        $this->assertNotSame(0, $rollback->getExitCode(), $this->combinedOutput($rollback));
        $this->assertStringContainsString('Opération destructive refusée', $this->combinedOutput($rollback));
        $this->assertTrue($this->probeTableExists());
    }

    public function test_migrate_rollback_s_execute_sur_la_base_de_test_isolee_ce_qui_donne_son_sens_au_test_de_survie(): void
    {
        $this->migrateProbe();

        $rollback = $this->runArtisanProcess('testing', 'migrate:rollback', '--path='.self::FIXTURE_MIGRATIONS, '--force');

        $this->assertSame(0, $rollback->getExitCode(), $this->combinedOutput($rollback));
        $this->assertFalse($this->probeTableExists(), 'Contrôle négatif : sur la base isolée, down() doit réellement supprimer la table.');
    }
}
