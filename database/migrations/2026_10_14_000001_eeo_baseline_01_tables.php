<?php

use App\Support\Schema\BaselineInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Baseline 1/4 : les 117 tables métier avec index, clés uniques, CHECK et colonnes générées (01_schema.sql).
 *
 * Instructions générées dans database/baseline/statements.json par database/tools/build_migrations.py,
 * identiques aux scripts SQL validés. Contrôle préalable : refus explicite si l'état de la base ne permet
 * pas cette étape (base déjà initialisée ou installation partielle), sans IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        $installer = BaselineInstaller::for(DB::connection($this->getConnection()));
        $installer->assertSupportedServer();
        $installer->installTables();
    }

    public function down(): void
    {
        BaselineInstaller::refuseRollback('01_tables');
    }
};
