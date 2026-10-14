<?php

namespace Tests\Feature\Access;

use App\Console\Commands\CreateDemoAccounts;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Amorçage contrôlé (contrat 23) : cabinet puis première gérante, une seule fois, sans mot de passe par
 * défaut ; comptes de démonstration fictifs des quatre profils, seulement dans le cabinet de démonstration
 * et hors production ; procédure de secours d'une gérante en console, journalisée.
 */
final class SetupAndDemoCommandsTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    public function test_mise_en_service_cree_cabinet_puis_gerante_attribuee_par_elle_meme(): void
    {
        $this->artisan('eeo:setup-clinic', ['--clinic-name' => 'CABINET FICTIF', '--manager-name' => 'Gérante fictive', '--login' => 'Gerante.Fictive'])
            ->expectsQuestion('Mot de passe de la gérante (12 caractères minimum, lettres et chiffres)', 'SecretInstall2026')
            ->expectsQuestion('Confirmer le mot de passe', 'SecretInstall2026')
            ->assertSuccessful();

        $clinic = DB::table('clinics')->first();
        $this->assertNotNull($clinic);
        $this->assertSame('CABINET FICTIF', $clinic->name);
        $user = DB::table('users')->where('clinic_id', $clinic->id)->first();
        $this->assertNotNull($user);
        $this->assertSame('gerante.fictive', $user->login);
        $role = DB::table('user_roles')->where('user_id', $user->id)->first();
        $this->assertNotNull($role);
        $this->assertSame('MANAGER', $role->role_code);
        $this->assertSame($user->id, $role->assigned_by, 'assigned_by = la première gérante désormais existante');
        $this->assertSame(1, $this->auditCount('clinic.setup', (string) $clinic->id));

        $this->login('gerante.fictive', 'SecretInstall2026')->assertOk()->assertJsonPath('data.roles', ['MANAGER']);
    }

    public function test_mise_en_service_refusee_une_seconde_fois(): void
    {
        $this->makeClinic();

        $this->artisan('eeo:setup-clinic', ['--clinic-name' => 'AUTRE', '--manager-name' => 'Autre gérante', '--login' => 'autre.gerante'])
            ->expectsQuestion('Mot de passe de la gérante (12 caractères minimum, lettres et chiffres)', 'SecretInstall2026')
            ->expectsQuestion('Confirmer le mot de passe', 'SecretInstall2026')
            ->expectsOutputToContain('déjà installé')
            ->assertFailed();

        $this->assertSame(1, DB::table('clinics')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_mise_en_service_refuse_un_mot_de_passe_faible_ou_mal_confirme(): void
    {
        $this->artisan('eeo:setup-clinic', ['--clinic-name' => 'CABINET', '--manager-name' => 'Gérante', '--login' => 'gerante'])
            ->expectsQuestion('Mot de passe de la gérante (12 caractères minimum, lettres et chiffres)', 'faible')
            ->expectsQuestion('Confirmer le mot de passe', 'faible')
            ->assertFailed();

        $this->artisan('eeo:setup-clinic', ['--clinic-name' => 'CABINET', '--manager-name' => 'Gérante', '--login' => 'gerante'])
            ->expectsQuestion('Mot de passe de la gérante (12 caractères minimum, lettres et chiffres)', 'SecretInstall2026')
            ->expectsQuestion('Confirmer le mot de passe', 'SecretInstall2027')
            ->assertFailed();

        $this->assertSame(0, DB::table('clinics')->count());
    }

    public function test_comptes_de_demonstration_des_quatre_profils_avec_mots_de_passe_aleatoires(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eeo-demo-');
        $this->assertIsString($file);

        try {
            $this->artisan('eeo:demo:accounts', ['--output' => $file])->assertSuccessful();
            $first = json_decode((string) file_get_contents($file), true);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));

            $this->assertIsArray($first);
            $this->assertSame(['MANAGER', 'RECEPTION', 'CLINICIAN', 'CASHIER'], array_column($first, 'role'));
            foreach ($first as $account) {
                $this->assertIsString($account['password']);
                $this->assertGreaterThanOrEqual(20, strlen($account['password']));
                $this->login($account['login'], $account['password'])->assertOk()->assertJsonPath('data.roles', [$account['role']]);
                $this->app['auth']->forgetGuards();
            }
            $this->assertStringContainsString('FICTIVES', (string) DB::table('clinics')->value('name'));

            $this->artisan('eeo:demo:accounts', ['--output' => $file, '--reset-passwords' => true])->assertSuccessful();
            $second = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($second);
            $this->assertNotSame(array_column($first, 'password'), array_column($second, 'password'), 'Aucun mot de passe fixe ni réutilisé.');
            $this->assertSame(4, DB::table('users')->count());
        } finally {
            @unlink($file);
        }
    }

    public function test_comptes_de_demonstration_refuses_en_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('eeo:demo:accounts')->expectsOutputToContain('Jamais en production')->assertFailed();

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_comptes_de_demonstration_refuses_si_un_cabinet_reel_existe(): void
    {
        $this->makeClinic('TEST CABINET REEL');

        $this->artisan('eeo:demo:accounts')->expectsOutputToContain('Un cabinet réel existe')->assertFailed();

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_comptes_de_demonstration_refuses_hors_des_environnements_admis(): void
    {
        $this->app['env'] = 'staging';

        $this->artisan('eeo:demo:accounts')->assertFailed();

        $this->assertSame(0, DB::table('clinics')->count());
    }

    public function test_comptes_de_demonstration_refuses_si_un_compte_non_fictif_existe(): void
    {
        $clinicId = $this->makeClinic(CreateDemoAccounts::DEMO_CLINIC_NAME);
        $this->makeStaff($clinicId, 'MANAGER', 'gerante.reelle');

        $this->artisan('eeo:demo:accounts')->expectsOutputToContain('comptes non fictifs')->assertFailed();

        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_la_regeneration_ne_reactive_jamais_un_compte_de_demonstration(): void
    {
        $this->artisan('eeo:demo:accounts', ['--output' => $file = $this->temporaryFile()])->assertSuccessful();
        DB::table('users')->where('login', 'demo.caisse')->update(['active' => false]);
        $hash = DB::table('users')->where('login', 'demo.caisse')->value('password_hash');

        try {
            $this->artisan('eeo:demo:accounts', ['--output' => $file, '--reset-passwords' => true])
                ->expectsOutputToContain('demo.caisse est désactivé')->assertSuccessful();
        } finally {
            @unlink($file);
        }

        $this->assertFalse((bool) DB::table('users')->where('login', 'demo.caisse')->value('active'));
        $this->assertSame($hash, DB::table('users')->where('login', 'demo.caisse')->value('password_hash'));
    }

    public function test_le_fichier_de_sortie_ne_suit_pas_un_lien_symbolique(): void
    {
        $target = $this->temporaryFile();
        file_put_contents($target, 'contenu protégé');
        $link = sys_get_temp_dir().'/eeo-demo-lien-'.bin2hex(random_bytes(4));
        symlink($target, $link);

        try {
            $this->artisan('eeo:demo:accounts', ['--output' => $link])->assertFailed();
            $this->assertSame('contenu protégé', file_get_contents($target));
            $this->assertSame(0, DB::table('users')->count());
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }

    public function test_procedure_de_secours_retablit_une_gerante_verrouillee(): void
    {
        $clinicId = $this->makeClinic();
        $manager = $this->makeStaff($clinicId, 'MANAGER', 'gerante.a');
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        // Verrouillage : compte désactivé, profil révoqué, ancienne dérogation DENY users.manage.
        DB::table('users')->where('id', $manager->id)->update(['active' => false]);
        DB::table('user_roles')->where('user_id', $manager->id)->update(['revoked_at' => $now]);
        DB::table('user_permissions')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $clinicId, 'user_id' => $manager->id, 'permission_code' => 'users.manage',
            'allowed' => false, 'assigned_by' => $manager->id, 'reason' => 'Ligne ancienne (test)', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->artisan('eeo:staff:recover-manager', ['login' => 'gerante.a', '--reason' => 'Gérante verrouillée (test fictif)'])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum, lettres et chiffres)', 'SecoursFictif2026')
            ->expectsQuestion('Confirmer le mot de passe', 'SecoursFictif2026')
            ->expectsConfirmation('Rétablir l\'accès de gerante.a : nouveau mot de passe, compte actif, profil Gérante, sessions fermées ?', 'yes')
            ->assertSuccessful();

        $this->assertTrue((bool) DB::table('users')->where('id', $manager->id)->value('active'));
        $this->assertTrue(Hash::check('SecoursFictif2026', (string) DB::table('users')->where('id', $manager->id)->value('password_hash')));
        $this->assertSame(0, DB::table('user_permissions')->where('user_id', $manager->id)->count());
        $event = DB::table('audit_events')->where('action', 'staff.recovered_by_console')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->actor_id);
        $this->assertSame('Gérante verrouillée (test fictif)', $event->reason);

        $this->login('gerante.a', 'SecoursFictif2026')->assertOk()->assertJsonPath('data.roles', ['MANAGER']);
        $this->assertContains('users.manage', $this->fromSpa()->getJson('/api/v1/auth/me')->json('data.permissions'));
    }

    public function test_procedure_de_secours_refusee_pour_un_compte_qui_n_a_jamais_ete_gerante(): void
    {
        $clinicId = $this->makeClinic();
        $this->makeStaff($clinicId, 'MANAGER', 'gerante.a');
        $cashier = $this->makeStaff($clinicId, 'CASHIER', 'caisse.a');

        $this->artisan('eeo:staff:recover-manager', ['login' => 'caisse.a', '--reason' => 'Tentative de promotion'])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum, lettres et chiffres)', 'SecoursFictif2026')
            ->expectsQuestion('Confirmer le mot de passe', 'SecoursFictif2026')
            ->expectsConfirmation('Rétablir l\'accès de caisse.a : nouveau mot de passe, compte actif, profil Gérante, sessions fermées ?', 'yes')
            ->expectsOutputToContain('jamais eu le profil Gérante')
            ->assertFailed();

        $this->assertSame(['CASHIER'], DB::table('user_roles')->where('user_id', $cashier->id)->whereNull('revoked_at')->pluck('role_code')->all());
        $this->assertSame(0, $this->auditCount('staff.recovered_by_console'));
    }

    public function test_procedure_de_secours_exige_un_motif_et_une_confirmation(): void
    {
        $clinicId = $this->makeClinic();
        $manager = $this->makeStaff($clinicId, 'MANAGER', 'gerante.a');
        $hash = DB::table('users')->where('id', $manager->id)->value('password_hash');

        $this->artisan('eeo:staff:recover-manager', ['login' => 'gerante.a', '--reason' => 'Oubli (test)'])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum, lettres et chiffres)', 'SecoursFictif2026')
            ->expectsQuestion('Confirmer le mot de passe', 'SecoursFictif2026')
            ->expectsConfirmation('Rétablir l\'accès de gerante.a : nouveau mot de passe, compte actif, profil Gérante, sessions fermées ?', 'no')
            ->assertFailed();
        $this->artisan('eeo:staff:recover-manager', ['login' => 'gerante.a', '--password-stdin' => true])->assertFailed();

        $this->assertSame($hash, DB::table('users')->where('id', $manager->id)->value('password_hash'));
    }

    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'eeo-demo-');
        $this->assertIsString($file);

        return $file;
    }
}
