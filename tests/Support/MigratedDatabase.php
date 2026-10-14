<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base de test isolée migrée avec la baseline complète (une fois par processus, vues comprises), puis
 * chaque test dans une transaction annulée à la fin : les fixtures fictives ne laissent aucune trace,
 * y compris dans les tables append-only (un ROLLBACK n'est pas un DELETE).
 *
 * La garde de base isolée est vérifiée AVANT tout migrate:fresh.
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
        $this->refreshLaravelDatabase();
    }

    protected function shouldDropViews(): bool
    {
        return true;
    }
}
