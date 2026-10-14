<?php

namespace Tests\Feature\Access;

use App\Http\Middleware\RequireRecentReauthentication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Session SPA du personnel (F05, AT01) sur MySQL réel : connexion dans le cabinet configuré, réponse
 * identique pour tout échec, limitation des tentatives, déconnexion, réauthentification, changement de
 * mot de passe, enveloppe d'erreur du fichier 08.
 */
final class AuthenticationTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    private string $clinicId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinicId = $this->makeClinic();
    }

    public function test_connexion_reussie_renvoie_le_compte_ses_profils_et_ses_permissions(): void
    {
        $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');

        $response = $this->login('Caisse.Test ');

        $response->assertOk()
            ->assertJsonPath('data.id', $cashier->id)
            ->assertJsonPath('data.login', 'caisse.test')
            ->assertJsonPath('data.roles', ['CASHIER'])
            ->assertJsonPath('data.clinic.id', $this->clinicId)
            ->assertJsonPath('data.clinic.currency', 'XAF')
            ->assertJsonMissingPath('data.password_hash');
        $this->assertContains('payment.collect', $response->json('data.permissions'));
        $this->assertNotContains('clinical.read', $response->json('data.permissions'));
        $this->assertSame(1, $this->auditCount('auth.login', $cashier->id));
        $this->assertNotNull(DB::table('users')->where('id', $cashier->id)->value('last_login_at'));
    }

    public function test_tout_echec_de_connexion_donne_la_meme_reponse_sans_divulguer_le_compte(): void
    {
        $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->makeStaff($this->clinicId, 'CASHIER', 'inactif.test', active: false);

        $wrongPassword = $this->login('accueil.test', 'MauvaisMotDePasse99');
        $unknown = $this->login('inconnu.test');
        $inactive = $this->login('inactif.test');

        foreach ([$wrongPassword, $unknown, $inactive] as $response) {
            $response->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonPath('error.message', 'Identifiant ou mot de passe incorrect.')
                ->assertJsonPath('error.fields.login.0', 'Identifiant ou mot de passe incorrect.')
                ->assertJsonStructure(['correlation_id']);
        }

        $this->assertSame(2, $this->auditCount('auth.login_failed'));
        $this->assertSame(1, $this->auditCount('auth.login_refused_inactive'));
        $diffs = DB::table('audit_events')->where('action', 'like', 'auth.login_%')->pluck('sanitized_diff')->implode(' ');
        $this->assertStringNotContainsString('inconnu.test', $diffs, 'L\'identifiant tenté n\'est jamais journalisé en clair.');
        $this->assertStringNotContainsString('MauvaisMotDePasse99', $diffs);
        $this->assertStringContainsString('login_sha256', $diffs);
    }

    public function test_cinq_echecs_bloquent_l_identifiant_avec_une_reponse_429(): void
    {
        $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login('accueil.test', 'MauvaisMotDePasse99')->assertStatus(422);
        }

        $blocked = $this->login('accueil.test');

        $blocked->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($blocked->headers->get('Retry-After'));
    }

    public function test_un_compte_d_un_autre_cabinet_ne_peut_pas_se_connecter_ici(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $this->makeStaff($otherClinic, 'MANAGER', 'gerante.b');
        config(['eeo.clinic_id' => $this->clinicId]);

        $this->login('gerante.b')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_sans_cabinet_configure_parmi_plusieurs_la_connexion_est_refusee_explicitement(): void
    {
        $this->makeClinic('TEST CABINET B');
        $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');

        $this->login('gerante.test')->assertStatus(503)->assertJsonPath('error.code', 'CLINIC_NOT_CONFIGURED');
    }

    public function test_sans_session_l_api_repond_401_dans_l_enveloppe_du_fichier_08(): void
    {
        $response = $this->fromSpa()->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED')
            ->assertJsonStructure(['error' => ['code', 'message'], 'correlation_id']);
        $this->assertSame($response->headers->get('X-Correlation-Id'), $response->json('correlation_id'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_la_deconnexion_ferme_la_session_serveur(): void
    {
        $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->login('accueil.test')->assertOk();
        $this->fromSpa()->getJson('/api/v1/auth/me')->assertOk();

        $this->nextRequest()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->nextRequest()->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertSame(1, $this->auditCount('auth.logout'));
    }

    public function test_la_reauthentification_exige_le_bon_mot_de_passe_et_expire(): void
    {
        $manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->login('gerante.test')->assertOk();

        $this->fromSpa()->postJson('/api/v1/staff', [])->assertStatus(423)->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->fromSpa()->postJson('/api/v1/auth/reauthenticate', ['password' => 'Faux1234567890'])
            ->assertStatus(422)->assertJsonPath('error.fields.password.0', 'Mot de passe incorrect.');

        $this->fromSpa()->postJson('/api/v1/auth/reauthenticate', ['password' => self::TEST_PASSWORD])
            ->assertOk()->assertJsonStructure(['data' => ['reauthenticated_until']]);
        $this->fromSpa()->postJson('/api/v1/staff', [])->assertStatus(422);

        $this->travel(16)->minutes();
        $this->fromSpa()->postJson('/api/v1/staff', [])->assertStatus(423);

        $this->assertSame(1, $this->auditCount('auth.reauthenticated', $manager->id));
        $this->assertSame(1, $this->auditCount('auth.reauthentication_failed', $manager->id));
    }

    public function test_une_nouvelle_connexion_annule_une_reauthentification_precedente(): void
    {
        $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->fromSpa()->withSession([RequireRecentReauthentication::SESSION_KEY => CarbonImmutable::now()->getTimestamp()]);

        $this->login('gerante.test')->assertOk();

        $this->fromSpa()->postJson('/api/v1/staff', [])->assertStatus(423);
    }

    public function test_changer_son_mot_de_passe(): void
    {
        $user = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        $this->login('pro.test')->assertOk();

        $this->fromSpa()->postJson('/api/v1/auth/password', [
            'current_password' => 'Faux1234567890', 'password' => 'NouveauSecret2027', 'password_confirmation' => 'NouveauSecret2027',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->fromSpa()->postJson('/api/v1/auth/password', [
            'current_password' => self::TEST_PASSWORD, 'password' => 'court1', 'password_confirmation' => 'court1',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);

        $this->fromSpa()->postJson('/api/v1/auth/password', [
            'current_password' => self::TEST_PASSWORD, 'password' => 'pro.test', 'password_confirmation' => 'pro.test',
        ])->assertStatus(422);

        $this->fromSpa()->postJson('/api/v1/auth/password', [
            'current_password' => self::TEST_PASSWORD, 'password' => 'NouveauSecret2027', 'password_confirmation' => 'NouveauSecret2027',
        ])->assertNoContent();

        $row = DB::table('users')->where('id', $user->id)->first(['password_hash', 'version']);
        $this->assertNotNull($row);
        $this->assertTrue(Hash::check('NouveauSecret2027', (string) $row->password_hash));
        $this->assertSame(2, (int) $row->version);
        $this->assertSame(1, $this->auditCount('auth.password_changed', $user->id));
        $passwordDiffs = DB::table('audit_events')->pluck('sanitized_diff')->implode(' ');
        $this->assertStringNotContainsString('NouveauSecret2027', $passwordDiffs);
    }

    public function test_une_connexion_sans_session_spa_est_refusee_explicitement(): void
    {
        $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');

        // Ni Referer ni Origin de la SPA : Sanctum n'ouvre pas de session, la connexion ne doit pas « réussir ».
        $response = $this->postJson('/api/v1/auth/login', ['login' => 'gerante.test', 'password' => self::TEST_PASSWORD]);

        $response->assertStatus(400)->assertJsonPath('error.code', 'SESSION_REQUIRED');
        $this->assertSame(0, $this->auditCount('auth.login'));
    }

    public function test_aucun_mot_de_passe_universel_n_ouvre_un_compte(): void
    {
        $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');

        foreach (['password', 'admin', 'admin123', '123456', 'gerante.test', ''] as $guess) {
            $this->assertNotSame(200, $this->login('gerante.test', $guess)->status(), 'Mot de passe deviné accepté : '.$guess);
        }
    }
}
