<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Migration de sonde pour MigrateRollbackGuardTest : table fictive créée par up(), supprimée par down().
 * Elle n'est lue que par --path=tests/fixtures/migrations : « php artisan migrate » ne la voit jamais.
 * Jamais exécutée ailleurs que sur la base jetable « eeo_rollback_probe_test ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollback_guard_probe', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollback_guard_probe');
    }
};
