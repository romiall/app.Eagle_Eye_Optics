<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bases MySQL jetables « eeo_*_test » créées par un test pour des opérations de schéma (migrations,
 * adoption) sans toucher à la base de test principale. Supprimées en fin de test.
 */
trait ScratchMysqlDatabases
{
    use RequiresIsolatedMysql;

    /** @var list<string> */
    private array $scratchDatabases = [];

    protected function createScratchDatabase(string $connection, string $database): void
    {
        $this->assertMatchesRegularExpression('/^eeo_[a-z0-9_]+_test$/', $database, 'Seules les bases jetables eeo_*_test sont admises.');
        $this->requireIsolatedMysql();

        try {
            DB::statement('DROP DATABASE IF EXISTS `'.$database.'`');
            DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        } catch (Throwable $exception) {
            $message = 'Droit CREATE DATABASE sur '.$database.' absent (tools/dev/mysql-up.sh l\'accorde) : '.$exception->getMessage();

            if (getenv('EEO_REQUIRE_MYSQL') === '1') {
                $this->fail($message);
            }

            $this->markTestSkipped($message);
        }

        $this->scratchDatabases[] = $database;
        config(["database.connections.{$connection}" => [
            ...(array) config('database.connections.mysql'),
            'database' => $database,
        ]]);
        DB::purge($connection);
    }

    protected function dropScratchDatabases(): void
    {
        foreach ($this->scratchDatabases as $database) {
            DB::statement('DROP DATABASE IF EXISTS `'.$database.'`');
        }
        $this->scratchDatabases = [];
    }
}
