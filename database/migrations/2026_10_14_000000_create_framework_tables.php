<?php

use App\Support\Testing\IsolatedDatabaseGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables techniques du framework, séparées des 117 tables métier (contrat 23) : sessions (user_id ULID),
 * cache, verrous de cache et files de jobs. Elles ne portent aucun fait métier ; outbox_events reste
 * l'état métier des effets externes et n'est pas une file Laravel. fw_password_reset_tokens n'est pas
 * créée : aucune réinitialisation par e-mail n'est utilisée à ce stade (pas d'e-mail fictif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fw_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            // users.id est un ULID ASCII de 26 caractères, comme toutes les clés de la baseline.
            $table->char('user_id', 26)->charset('ascii')->collation('ascii_bin')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('fw_cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('fw_cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('fw_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('fw_job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('fw_failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        // Un retour arrière de ces tables détruirait sessions et jobs en attente : seulement en base de test.
        $connection = $this->getConnection() ?? (string) config('database.default');
        IsolatedDatabaseGuard::forConnection(app()->environment(), $connection)
            ->assertAllows('retour arrière des tables techniques');

        foreach (['fw_failed_jobs', 'fw_job_batches', 'fw_jobs', 'fw_cache_locks', 'fw_cache', 'fw_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
