<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\Support\RequiresIsolatedMysql;
use Tests\TestCase;

/**
 * Connexion réelle à la base MySQL de test isolée : version cible, mode strict, moteur et jeu de
 * caractères du schéma validé (MySQL 8.4 / InnoDB / utf8mb4_0900_ai_ci / UTC).
 */
final class MysqlTestDatabaseConnectionTest extends TestCase
{
    use RequiresIsolatedMysql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireIsolatedMysql();
    }

    public function test_la_connexion_cible_la_base_de_test_isolee(): void
    {
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS ok')->ok);

        $database = (string) DB::selectOne('SELECT DATABASE() AS name')->name;

        $this->assertStringEndsWith('_test', $database);
        $this->assertSame(DB::connection()->getDatabaseName(), $database);
    }

    public function test_le_serveur_est_mysql_8_4(): void
    {
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;

        $this->assertMatchesRegularExpression('/^8\.4\.\d+/', $version, 'La cible validée est MySQL 8.4 (MariaDB non déclarée compatible).');
    }

    public function test_le_mode_strict_innodb_utf8mb4_et_utc_sont_actifs(): void
    {
        $row = DB::selectOne(
            'SELECT @@session.sql_mode AS sql_mode, @@default_storage_engine AS engine, '
            .'@@character_set_connection AS charset, @@collation_connection AS collation, '
            .'@@session.time_zone AS time_zone, @@transaction_isolation AS isolation'
        );

        $this->assertStringContainsString('STRICT_TRANS_TABLES', $row->sql_mode);
        $this->assertSame('InnoDB', $row->engine);
        $this->assertSame('utf8mb4', $row->charset);
        $this->assertSame('utf8mb4_0900_ai_ci', $row->collation);
        $this->assertSame('+00:00', $row->time_zone);
        $this->assertSame('REPEATABLE-READ', $row->isolation);
    }

    public function test_la_base_de_test_admet_la_creation_de_triggers_par_un_utilisateur_sans_super(): void
    {
        // Les guards d'historique (F04) sont des triggers. Avec le journal binaire actif (cas par défaut
        // de MySQL 8.4), un utilisateur sans SUPER ne peut créer un trigger que si
        // log_bin_trust_function_creators vaut 1 : prérequis aussi à vérifier chez l'hébergeur (Q01).
        $row = DB::selectOne('SELECT @@global.log_bin AS log_bin, @@global.log_bin_trust_function_creators AS trusted');

        $this->assertTrue(
            (int) $row->log_bin === 0 || (int) $row->trusted === 1,
            'Journal binaire actif et log_bin_trust_function_creators=0 : CREATE TRIGGER sera refusé (erreur 1419). '
            .'tools/dev/mysql-up.sh active ce réglage sur le conteneur de développement.',
        );
    }
}
