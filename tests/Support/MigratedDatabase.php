<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Base de test isolée migrée avec la baseline complète (une fois par processus, vues comprises), puis
 * chaque test dans une transaction annulée à la fin : les fixtures fictives ne laissent aucune trace,
 * y compris dans les tables append-only (un ROLLBACK n'est pas un DELETE).
 *
 * La garde de base isolée est vérifiée AVANT tout migrate:fresh.
 *
 * Boucle de développement locale (opt-in, jamais en CI) : avec EEO_TEST_REUSE_SCHEMA=1, un schéma DÉJÀ migré
 * dans la base isolée est réutilisé au lieu d'être recréé. La baseline (117 tables, 464 clés étrangères,
 * 88 triggers) coûte de quelques secondes à plusieurs minutes selon le disque ; les tests qui ne touchent pas
 * au schéma n'ont pas besoin de la refaire à chaque processus. Le schéma n'est réutilisé que si les migrations
 * enregistrées sont EXACTEMENT celles du dépôt ; sinon la base est recréée. Une migration existante modifiée
 * sans changer de nom n'est pas détectée : relancer alors sans la variable.
 */
trait MigratedDatabase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshLaravelDatabase;
    }
    use RequiresIsolatedMysql;

    public function refreshDatabase(): void
    {
        $this->requireIsolatedMysql();

        if (getenv('EEO_TEST_REUSE_SCHEMA') === '1' && $this->schemaMatchesRepositoryMigrations()) {
            RefreshDatabaseState::$migrated = true;
        }

        $this->refreshLaravelDatabase();
    }

    protected function shouldDropViews(): bool
    {
        return true;
    }

    private function schemaMatchesRepositoryMigrations(): bool
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }

            $recorded = DB::table('migrations')->pluck('migration')->map(static fn (mixed $name): string => (string) $name)->all();
            $files = array_map(
                static fn (string $path): string => basename($path, '.php'),
                glob(database_path('migrations/*.php')) ?: [],
            );
            sort($recorded);
            sort($files);

            return $recorded !== [] && $recorded === $files;
        } catch (Throwable) {
            return false;
        }
    }
}
