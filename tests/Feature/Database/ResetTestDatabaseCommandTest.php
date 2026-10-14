<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\RequiresIsolatedMysql;
use Tests\TestCase;
use Throwable;

/**
 * Preuve sur MySQL réel que eeo:test-db:reset vide complètement une base isolée (tables, clés
 * étrangères, vues et triggers) sans toucher à la base de test principale : le test crée sa propre
 * base jetable « eeo_reset_probe_test ».
 */
final class ResetTestDatabaseCommandTest extends TestCase
{
    use RequiresIsolatedMysql;

    private const PROBE_DATABASE = 'eeo_reset_probe_test';

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

        config(['database.connections.mysql_probe' => [
            ...(array) config('database.connections.mysql'),
            'database' => self::PROBE_DATABASE,
        ]]);
        DB::purge('mysql_probe');
    }

    protected function tearDown(): void
    {
        if ($this->probeCreated) {
            DB::purge('mysql_probe');
            DB::statement('DROP DATABASE IF EXISTS `'.self::PROBE_DATABASE.'`');
        }

        parent::tearDown();
    }

    /**
     * @return array{tables: int, views: int, triggers: int}
     */
    private function inventory(): array
    {
        $count = static fn (string $sql): int => (int) DB::selectOne($sql, [self::PROBE_DATABASE])->total;

        return [
            'tables' => $count('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\''),
            'views' => $count('SELECT COUNT(*) AS total FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?'),
            'triggers' => $count('SELECT COUNT(*) AS total FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?'),
        ];
    }

    private function populateProbe(): void
    {
        $probe = DB::connection('mysql_probe');

        $probe->unprepared('CREATE TABLE parents (id INT PRIMARY KEY) ENGINE=InnoDB');
        $probe->unprepared('CREATE TABLE children (id INT PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT fk_children_parent FOREIGN KEY (parent_id) REFERENCES parents (id)) ENGINE=InnoDB');
        $probe->unprepared('CREATE VIEW parent_ids AS SELECT id FROM parents');
        $probe->unprepared('CREATE TRIGGER children_guard BEFORE UPDATE ON children FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'append-only\'');
        $probe->insert('INSERT INTO parents (id) VALUES (1)');
        $probe->insert('INSERT INTO children (id, parent_id) VALUES (1, 1)');
    }

    public function test_la_commande_vide_tables_cles_etrangeres_vues_et_triggers(): void
    {
        $this->populateProbe();
        $this->assertSame(['tables' => 2, 'views' => 1, 'triggers' => 1], $this->inventory());

        $status = Artisan::call('eeo:test-db:reset', ['--database' => 'mysql_probe']);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertSame(['tables' => 0, 'views' => 0, 'triggers' => 0], $this->inventory());
    }

    public function test_l_option_migrate_recree_le_depot_de_migrations_apres_la_remise_a_zero(): void
    {
        $this->populateProbe();

        $status = Artisan::call('eeo:test-db:reset', ['--database' => 'mysql_probe', '--migrate' => true]);

        $this->assertSame(0, $status, Artisan::output());

        $tables = DB::connection('mysql_probe')->select('SHOW TABLES');
        $names = array_map(static fn (object $row): string => (string) array_values((array) $row)[0], $tables);

        $this->assertContains('migrations', $names);
        $this->assertNotContains('parents', $names);
    }

    public function test_la_commande_refuse_une_connexion_qui_n_est_pas_isolee(): void
    {
        config(['database.connections.mysql_probe.database' => 'eagle_eye_optics']);
        DB::purge('mysql_probe');

        $status = Artisan::call('eeo:test-db:reset', ['--database' => 'mysql_probe']);
        $output = Artisan::output();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Remise à zéro refusée', $output);
    }
}
