<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Contrats de configuration de F02 (fichier 23) : tables techniques fw_*, fuseaux et identité.
 */
final class ConfigurationContractTest extends TestCase
{
    public function test_les_instants_sont_en_utc_et_le_fuseau_metier_est_un_parametre_separe(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('Africa/Douala', config('eeo.clinic_timezone'));
        $this->assertSame('XAF', config('eeo.clinic_currency'));
    }

    public function test_les_documents_de_production_et_les_integrations_reelles_sont_desactives(): void
    {
        $this->assertFalse(config('eeo.production_documents_enabled'));
        $this->assertSame('fake', config('eeo.integrations.mode'));
    }

    public function test_les_tables_techniques_du_framework_portent_le_prefixe_fw(): void
    {
        $this->assertSame('fw_sessions', config('session.table'));
        $this->assertSame('fw_cache', config('cache.stores.database.table'));
        $this->assertSame('fw_cache_locks', config('cache.stores.database.lock_table'));
        $this->assertSame('fw_jobs', config('queue.connections.database.table'));
        $this->assertSame('fw_job_batches', config('queue.batching.table'));
        $this->assertSame('fw_failed_jobs', config('queue.failed.table'));
        $this->assertSame('fw_password_reset_tokens', config('auth.passwords.users.table'));
        $this->assertSame('migrations', config('database.migrations.table'));
    }

    public function test_la_base_cible_est_mysql_innodb_en_utf8mb4(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('InnoDB', config('database.connections.mysql.engine'));
        $this->assertSame('utf8mb4', config('database.connections.mysql.charset'));
        $this->assertSame('utf8mb4_0900_ai_ci', config('database.connections.mysql.collation'));
        $this->assertTrue(config('database.connections.mysql.strict'));
    }

    public function test_la_session_est_un_cookie_securise_et_httponly_du_seul_domaine(): void
    {
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertNull(config('session.domain'));
    }

    public function test_le_cookie_csrf_sanctum_reste_sous_api(): void
    {
        $this->assertSame('/api/sanctum/csrf-cookie', route('sanctum.csrf-cookie', absolute: false));
    }

    public function test_l_interface_est_en_francais(): void
    {
        $this->assertSame('fr', config('app.locale'));
        $this->assertSame('fr', config('app.fallback_locale'));
    }

    public function test_le_modele_utilisateur_suit_le_catalogue_et_non_les_valeurs_par_defaut_de_laravel(): void
    {
        // Écriture explicite (forceFill) comme dans les actions du domaine Accès : pas d'affectation de masse.
        $user = (new User)->forceFill(['login' => 'accueil', 'password_hash' => '$2y$04$fictifFictifFictifFictifFictifFictifFictifFictifFictifFi']);

        $this->assertSame('password_hash', $user->getAuthPasswordName());
        $this->assertStringStartsWith('$2y$', $user->getAuthPassword());
        $this->assertSame('', $user->getRememberTokenName());
        $this->assertNull($user->getRememberToken());
        $this->assertFalse($user->getIncrementing());
        $this->assertSame('string', $user->getKeyType());
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
    }

    public function test_l_affectation_de_masse_ne_peut_pas_choisir_cabinet_hash_ni_statut(): void
    {
        $user = new User([
            'display_name' => 'Nom',
            'clinic_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            'password_hash' => 'x',
            'active' => false,
            'version' => 99,
        ]);

        $this->assertSame('Nom', $user->display_name);
        $this->assertNull($user->getAttribute('clinic_id'), 'Le cabinet ne vient jamais d\'une saisie (contrat 23).');
        $this->assertNull($user->getAttribute('password_hash'));
        $this->assertNull($user->getAttribute('active'));
        $this->assertNull($user->getAttribute('version'));
    }
}
