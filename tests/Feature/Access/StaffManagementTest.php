<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Comptes du personnel (F05, AT01, AT02, AT43) : création par la gérante seulement, version attendue,
 * désactivation qui coupe la session serveur, profils et dérogations appliqués à la requête suivante,
 * aucune donnée d'un autre cabinet, cabinet jamais privé de gérante.
 */
final class StaffManagementTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    private string $clinicId;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonManagerRoles(): array
    {
        return ['accueil' => ['RECEPTION'], 'professionnel' => ['CLINICIAN'], 'caisse' => ['CASHIER']];
    }

    #[DataProvider('nonManagerRoles')]
    public function test_seule_la_gerante_gere_les_comptes(string $role): void
    {
        $user = $this->makeStaff($this->clinicId, $role);
        $request = $this->actingAsReauthenticated($user);

        $request->getJson('/api/v1/staff')->assertStatus(403)->assertJsonPath('error.code', 'ACTION_FORBIDDEN');
        $request->postJson('/api/v1/staff', ['login' => 'pirate.test'])->assertStatus(403);
        $request->patchJson('/api/v1/staff/'.$this->manager->id, ['expected_version' => 1, 'active' => false, 'reason' => 'x'])->assertStatus(403);
        $request->patchJson('/api/v1/clinic/settings', ['name' => 'Piraté'])->assertStatus(403);
        $this->assertTrue((bool) DB::table('users')->where('id', $this->manager->id)->value('active'));
    }

    public function test_les_quatre_profils_ont_les_permissions_du_referentiel(): void
    {
        $expectations = [
            'MANAGER' => ['users.manage' => true, 'clinical.read' => true, 'payment.collect' => true, 'finance.global.read' => true],
            'RECEPTION' => ['patient.create' => true, 'agenda.write' => true, 'clinical.read' => false, 'payment.collect' => false, 'exam_fee.collect' => false],
            'CLINICIAN' => ['clinical.write' => true, 'clinical.complete' => true, 'payment.collect' => false, 'catalog.purchase_cost.read' => false],
            'CASHIER' => ['payment.collect' => true, 'invoice.issue' => true, 'clinical.read' => false, 'refund.execute' => false, 'users.manage' => false],
        ];

        foreach ($expectations as $role => $abilities) {
            $user = $role === 'MANAGER' ? $this->manager : $this->makeStaff($this->clinicId, $role);
            foreach ($abilities as $ability => $expected) {
                $this->assertSame($expected, Gate::forUser($user)->allows($ability), $role.' / '.$ability);
            }
        }

        $managerPermissions = $this->actingAsFresh($this->manager)->getJson('/api/v1/auth/me')->json('data.permissions');
        $this->assertCount(49, $managerPermissions, 'La gérante a les 49 permissions seedées.');
    }

    public function test_la_gerante_cree_un_compte_nominatif_journalise_sans_mot_de_passe(): void
    {
        $response = $this->actingAsReauthenticated($this->manager)->postJson('/api/v1/staff', [
            'login' => ' Marie.Caisse ',
            'display_name' => 'Marie (fictive)',
            'email' => 'marie@example.invalid',
            'password' => 'InitialFictif2026',
            'roles' => ['CASHIER'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.login', 'marie.caisse')
            ->assertJsonPath('data.roles', ['CASHIER'])
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.version', 1);
        $id = (string) $response->json('data.id');
        $this->assertTrue(Hash::check('InitialFictif2026', (string) DB::table('users')->where('id', $id)->value('password_hash')));
        $this->assertSame($this->manager->id, DB::table('user_roles')->where('user_id', $id)->value('assigned_by'));
        $this->assertSame(1, $this->auditCount('staff.created', $id));
        $this->assertStringNotContainsString('InitialFictif2026', (string) DB::table('audit_events')->where('target_id', $id)->value('sanitized_diff'));

        $this->login('marie.caisse', 'InitialFictif2026')->assertOk();
    }

    public function test_creation_refusee_pour_identifiant_deja_pris_profil_inconnu_ou_mot_de_passe_faible(): void
    {
        $request = $this->actingAsReauthenticated($this->manager);

        $request->postJson('/api/v1/staff', ['login' => 'GERANTE.test', 'display_name' => 'Doublon', 'password' => 'InitialFictif2026', 'roles' => ['CASHIER']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['login']]]);
        $request->postJson('/api/v1/staff', ['login' => 'nouveau.test', 'display_name' => 'X Y', 'password' => 'InitialFictif2026', 'roles' => ['SUPERADMIN']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['roles.0']]]);
        $request->postJson('/api/v1/staff', ['login' => 'nouveau.test', 'display_name' => 'X Y', 'password' => 'faible', 'roles' => ['CASHIER']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);
        $request->postJson('/api/v1/staff', ['login' => 'nouveau.test', 'display_name' => 'X Y', 'password' => 'InitialFictif2026', 'roles' => []])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['roles']]]);

        $this->assertSame(1, DB::table('users')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_version_attendue_obsolete_refusee_sans_modification(): void
    {
        $user = $this->makeStaff($this->clinicId, 'RECEPTION');

        $this->actingAsReauthenticated($this->manager)
            ->patchJson('/api/v1/staff/'.$user->id, ['expected_version' => 7, 'display_name' => 'Autre nom'])
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertSame('TEST RECEPTION', DB::table('users')->where('id', $user->id)->value('display_name'));
    }

    public function test_desactivation_motivee_qui_coupe_toutes_les_sessions_serveur(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->makeStaff($this->clinicId, 'CASHIER');
        DB::table('fw_sessions')->insert([
            ['id' => 'session-test-1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'session-test-2', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()],
        ]);

        $this->actingAsReauthenticated($this->manager)
            ->patchJson('/api/v1/staff/'.$user->id, ['expected_version' => 1, 'active' => false])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);

        $this->actingAsReauthenticated($this->manager)
            ->patchJson('/api/v1/staff/'.$user->id, ['expected_version' => 1, 'active' => false, 'reason' => 'Départ (fictif)'])
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.version', 2);

        $this->assertSame(0, DB::table('fw_sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, $this->auditCount('staff.deactivated', $user->id));
        $this->assertSame([], $user->refresh()->permissionCodes());

        // Un cookie encore présent ne suffit plus : le compte est relu à chaque requête.
        $this->actingAsFresh($user)->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->login($user->login)->assertStatus(422);
    }

    public function test_le_cabinet_garde_toujours_une_gerante_active(): void
    {
        $request = $this->actingAsReauthenticated($this->manager);

        $request->patchJson('/api/v1/staff/'.$this->manager->id, ['expected_version' => 1, 'active' => false, 'reason' => 'Test'])
            ->assertStatus(409)->assertJsonPath('error.code', 'SELF_DEACTIVATION_FORBIDDEN');
        $request->patchJson('/api/v1/staff/'.$this->manager->id, ['expected_version' => 1, 'roles' => ['CASHIER'], 'reason' => 'Test'])
            ->assertStatus(409)->assertJsonPath('error.code', 'LAST_MANAGER_REQUIRED');

        $second = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante2.test');
        $request->patchJson('/api/v1/staff/'.$second->id, ['expected_version' => 1, 'active' => false, 'reason' => 'Départ (fictif)'])
            ->assertOk();
        $request->patchJson('/api/v1/staff/'.$this->manager->id, ['expected_version' => 1, 'roles' => ['CASHIER'], 'reason' => 'Test'])
            ->assertStatus(409)->assertJsonPath('error.code', 'LAST_MANAGER_REQUIRED');
    }

    public function test_profils_et_derogations_s_appliquent_des_la_requete_suivante(): void
    {
        $user = $this->makeStaff($this->clinicId, 'RECEPTION');
        $request = $this->actingAsReauthenticated($this->manager);

        $request->patchJson('/api/v1/staff/'.$user->id, ['expected_version' => 1, 'roles' => ['RECEPTION', 'CASHIER']])->assertOk();
        $this->assertTrue($user->refresh()->hasPermission('payment.collect'));

        $request->patchJson('/api/v1/staff/'.$user->id, ['expected_version' => 2, 'roles' => ['RECEPTION']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $request->patchJson('/api/v1/staff/'.$user->id, ['expected_version' => 2, 'roles' => ['RECEPTION'], 'reason' => 'Fin du remplacement'])->assertOk();
        $this->app->forgetScopedInstances();
        $this->assertFalse($user->refresh()->hasPermission('payment.collect'));
        $this->assertNotNull(DB::table('user_roles')->where('user_id', $user->id)->where('role_code', 'CASHIER')->value('revoked_at'));

        $request->postJson('/api/v1/staff/'.$user->id.'/permissions', ['permission_code' => 'exam_fee.collect', 'effect' => 'ALLOW', 'reason' => 'Encaissement des frais d\'examen à l\'accueil'])
            ->assertOk();
        $request->postJson('/api/v1/staff/'.$user->id.'/permissions', ['permission_code' => 'patient.update', 'effect' => 'DENY', 'reason' => 'Lecture seule temporaire', 'expires_at' => now()->addDay()->toIso8601String()])
            ->assertOk();
        $this->app->forgetScopedInstances();
        $user->refresh();
        $this->assertTrue($user->hasPermission('exam_fee.collect'));
        $this->assertFalse($user->hasPermission('patient.update'));

        $this->travel(2)->days();
        $this->app->forgetScopedInstances();
        $this->assertTrue($user->refresh()->hasPermission('patient.update'), 'Une dérogation expirée ne compte plus.');

        // Deux jours plus tard, la réauthentification de 15 minutes a expiré : nouvelle confirmation.
        $request = $this->actingAsReauthenticated($this->manager);
        $request->postJson('/api/v1/staff/'.$user->id.'/permissions', ['permission_code' => 'exam_fee.collect', 'effect' => 'CLEAR', 'reason' => 'Fin'])->assertOk();
        $this->app->forgetScopedInstances();
        $this->assertFalse($user->refresh()->hasPermission('exam_fee.collect'));
        $this->assertSame(3, $this->auditCount('staff.permission_override', $user->id));

        $request->postJson('/api/v1/staff/'.$this->manager->id.'/permissions', ['permission_code' => 'users.manage', 'effect' => 'DENY', 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'SELF_OVERRIDE_FORBIDDEN');
        $request->postJson('/api/v1/staff/'.$user->id.'/permissions', ['permission_code' => 'inexistant.code', 'effect' => 'ALLOW', 'reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_reinitialisation_par_la_gerante_ferme_les_sessions_du_compte(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        DB::table('fw_sessions')->insert(['id' => 'session-pro', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAsReauthenticated($this->manager)
            ->postJson('/api/v1/staff/'.$user->id.'/password', ['password' => 'Remplace2026xx', 'password_confirmation' => 'Remplace2026xx', 'reason' => 'Oubli (fictif)'])
            ->assertNoContent();

        $this->assertSame(0, DB::table('fw_sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, $this->auditCount('staff.password_reset', $user->id));
        $this->login('pro.test')->assertStatus(422);
        $this->login('pro.test', 'Remplace2026xx')->assertOk();
    }

    public function test_aucune_donnee_d_un_autre_cabinet_n_est_accessible(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $otherManager = $this->makeStaff($otherClinic, 'MANAGER', 'gerante.b');
        $otherUser = $this->makeStaff($otherClinic, 'CASHIER', 'caisse.b');
        $request = $this->actingAsReauthenticated($this->manager);

        $request->getJson('/api/v1/staff/'.$otherUser->id)->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $request->patchJson('/api/v1/staff/'.$otherUser->id, ['expected_version' => 1, 'active' => false, 'reason' => 'x'])->assertStatus(404);
        $request->postJson('/api/v1/staff/'.$otherUser->id.'/password', ['password' => 'Remplace2026xx', 'password_confirmation' => 'Remplace2026xx', 'reason' => 'x'])->assertStatus(404);
        $request->postJson('/api/v1/staff/'.$otherUser->id.'/permissions', ['permission_code' => 'users.manage', 'effect' => 'ALLOW', 'reason' => 'x'])->assertStatus(404);

        $listed = array_column($request->getJson('/api/v1/staff')->assertOk()->json('data'), 'id');
        $this->assertNotContains($otherUser->id, $listed);
        $this->assertNotContains($otherManager->id, $listed);
        $this->assertTrue((bool) DB::table('users')->where('id', $otherUser->id)->value('active'));
    }

    public function test_parametres_du_cabinet(): void
    {
        $reception = $this->makeStaff($this->clinicId, 'RECEPTION');

        $this->actingAsFresh($reception)->getJson('/api/v1/clinic/settings')
            ->assertOk()->assertJsonPath('data.currency', 'XAF')->assertJsonPath('data.production_documents_enabled', false);

        $this->actingAsFresh($this->manager)->patchJson('/api/v1/clinic/settings', ['name' => 'Nouveau nom'])
            ->assertStatus(423);

        $request = $this->actingAsReauthenticated($this->manager);
        $request->patchJson('/api/v1/clinic/settings', ['production_documents_enabled' => true])->assertStatus(422);
        $request->patchJson('/api/v1/clinic/settings', ['currency' => 'EUR'])->assertStatus(422);
        $request->patchJson('/api/v1/clinic/settings', ['name' => 'TEST CABINET RENOMMÉ', 'phone' => '+237 600 00 00 00'])
            ->assertOk()->assertJsonPath('data.name', 'TEST CABINET RENOMMÉ');

        $this->assertFalse((bool) DB::table('clinics')->where('id', $this->clinicId)->value('production_documents_enabled'));
        $this->assertSame(1, $this->auditCount('clinic.settings_updated', $this->clinicId));
    }
}
