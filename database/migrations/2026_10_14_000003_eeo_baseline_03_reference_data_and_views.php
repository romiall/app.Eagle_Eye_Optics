<?php

use App\Support\Schema\BaselineInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Baseline 3/4 : référentiels des sources (4 rôles, 49 permissions, 76 termes cliniques…) et 10 vues.
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
        $installer->installReferenceDataAndViews();
    }

    public function down(): void
    {
        BaselineInstaller::refuseRollback('03_reference_data_and_views');
    }
};
