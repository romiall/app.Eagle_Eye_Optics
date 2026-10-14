<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Actions\AuthenticateStaff;
use App\Domain\Access\Actions\ChangeOwnPassword;
use App\Domain\Access\Actions\CreateStaffMember;
use App\Domain\Access\Actions\ResetStaffPassword;
use App\Domain\Access\Actions\SetStaffPermissionOverride;
use App\Domain\Access\Actions\SetupClinic;
use App\Domain\Access\Actions\UpdateStaffMember;
use App\Domain\Access\StaffRoles;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AccessFixtures;
use Tests\Support\CountingHasher;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Non-régression des constats des revues F05 (sécurité et conformité), sur MySQL réel. Chaque test
 * échoue sur le comportement constaté avant correction.
 */
final class SecurityHardeningTest extends TestCase
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

    // --- B1 : aucun secret dans les journaux ---------------------------------------------------------

    public function test_un_refus_metier_n_est_pas_journalise(): void
    {
        Exceptions::fake();
        $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');

        $this->login('accueil.test', 'MauvaisMotDePasse99')->assertStatus(422);

        Exceptions::assertNotReported(BusinessError::class);
    }

    public function test_le_mot_de_passe_n_apparait_pas_dans_une_pile_d_appels_meme_avec_les_arguments_actives(): void
    {
        $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        $maxLength = ini_get('zend.exception_string_param_max_len');
        // Valeurs par défaut de PHP quand php.ini ne les fixe pas (hébergement simple).
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '100');

        try {
            app(AuthenticateStaff::class)('accueil.test', 'SecretTresVisible99', '127.0.0.1');
            $this->fail('La connexion devait être refusée.');
        } catch (BusinessError $error) {
            $this->assertStringContainsString('accueil.test', $error->getTraceAsString(), 'Les arguments sont bien exposés dans ce test.');
            $this->assertStringNotContainsString('SecretTresVisible99', $error->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
            ini_set('zend.exception_string_param_max_len', (string) $maxLength);
        }
    }

    public function test_tout_parametre_secret_de_l_application_est_marque_sensible(): void
    {
        $checked = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path()) + 1));
            if (! class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if (preg_match('/password|secret|passphrase/i', $parameter->getName()) === 1) {
                        $checked++;
                        $this->assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class), $class.'::'.$method->getName().'($'.$parameter->getName().') doit porter #[\SensitiveParameter].');
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $checked);

        // Charges de formulaire qui transportent un mot de passe.
        foreach ([
            [CreateStaffMember::class, '__invoke', 'input'],
            [ResetStaffPassword::class, '__invoke', 'input'],
            [ChangeOwnPassword::class, '__invoke', 'input'],
            [SetupClinic::class, '__invoke', 'input'],
        ] as [$class, $method, $name]) {
            $parameter = collect((new \ReflectionMethod($class, $method))->getParameters())->firstWhere('name', $name);
            $this->assertNotNull($parameter);
            $this->assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class), $class.'::'.$method.'($'.$name.')');
        }
    }

    public function test_les_valeurs_liees_n_apparaissent_pas_dans_le_message_d_une_erreur_sql(): void
    {
        try {
            DB::table('users')->insert([
                'id' => $this->manager->id, 'clinic_id' => $this->clinicId, 'display_name' => 'Doublon',
                'login' => 'doublon.test', 'password_hash' => 'VALEUR-LIEE-SECRETE', 'active' => true,
            ]);
            $this->fail('Clé primaire dupliquée attendue.');
        } catch (QueryException $exception) {
            $this->assertStringNotContainsString('VALEUR-LIEE-SECRETE', $exception->getMessage());
        }
    }

    // --- M1 / M2 : pas d'escalade par dérogation, gérante protégée ------------------------------------

    public function test_users_manage_ne_s_attribue_ni_ne_se_retire_par_derogation(): void
    {
        $cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $second = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante2.test');
        $request = $this->actingAsReauthenticated($this->manager);

        $request->postJson('/api/v1/staff/'.$cashier->id.'/permissions', ['permission_code' => 'users.manage', 'effect' => 'ALLOW', 'reason' => 'Remplacement', 'expected_version' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['permission_code']]]);
        $request->postJson('/api/v1/staff/'.$second->id.'/permissions', ['permission_code' => 'users.manage', 'effect' => 'DENY', 'reason' => 'Restriction', 'expected_version' => 1])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('user_permissions')->where('permission_code', 'users.manage')->count());

        // CLEAR reste possible (retour aux droits du profil) : nettoyage d'une ligne ancienne.
        $this->insertOverride($second, 'users.manage', false);
        $request->postJson('/api/v1/staff/'.$second->id.'/permissions', ['permission_code' => 'users.manage', 'effect' => 'CLEAR', 'reason' => 'Nettoyage', 'expected_version' => 1])
            ->assertOk();
        $this->assertSame(0, DB::table('user_permissions')->where('permission_code', 'users.manage')->count());
    }

    public function test_personne_ne_modifie_ses_propres_profils(): void
    {
        $this->makeStaff($this->clinicId, 'MANAGER', 'gerante2.test');

        $this->actingAsReauthenticated($this->manager)
            ->patchJson('/api/v1/staff/'.$this->manager->id, ['expected_version' => 1, 'roles' => ['MANAGER', 'CLINICIAN'], 'reason' => 'Ajout'])
            ->assertStatus(409)->assertJsonPath('error.code', 'SELF_ROLE_CHANGE_FORBIDDEN');

        $this->assertSame(['MANAGER'], StaffRoles::activeRoles($this->clinicId, $this->manager->id));
    }

    public function test_le_mot_de_passe_d_une_gerante_ne_se_reinitialise_pas_par_l_api(): void
    {
        $second = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante2.test');
        $hash = DB::table('users')->where('id', $second->id)->value('password_hash');

        $this->actingAsReauthenticated($this->manager)
            ->postJson('/api/v1/staff/'.$second->id.'/password', ['password' => 'Remplace2026xx', 'password_confirmation' => 'Remplace2026xx', 'reason' => 'Oubli', 'expected_version' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'MANAGER_RESET_REQUIRES_CONSOLE');

        $this->assertSame($hash, DB::table('users')->where('id', $second->id)->value('password_hash'));
    }

    public function test_reinitialisation_refusee_sans_motif_ou_sur_son_propre_compte(): void
    {
        $user = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        $request = $this->actingAsReauthenticated($this->manager);

        $request->postJson('/api/v1/staff/'.$user->id.'/password', ['password' => 'Remplace2026xx', 'password_confirmation' => 'Remplace2026xx', 'expected_version' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $request->postJson('/api/v1/staff/'.$this->manager->id.'/password', ['password' => 'Remplace2026xx', 'password_confirmation' => 'Remplace2026xx', 'reason' => 'x', 'expected_version' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'SELF_RESET_FORBIDDEN');
        $request->postJson('/api/v1/staff/'.$user->id.'/password', ['password' => 'Remplace2026xx', 'password_confirmation' => 'Remplace2026xx', 'reason' => 'Oubli', 'expected_version' => 7])
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $request->postJson('/api/v1/staff/'.$user->id.'/permissions', ['permission_code' => 'exam_fee.collect', 'effect' => 'ALLOW', 'expected_version' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);

        $this->assertSame(0, $this->auditCount('staff.password_reset'));
    }

    public function test_l_invariant_compte_une_gerante_qui_a_effectivement_users_manage(): void
    {
        $second = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante2.test');
        $this->insertOverride($second, 'users.manage', false);

        try {
            StaffRoles::assertAnotherActiveManager($this->clinicId, $this->manager->id);
            $this->fail('Une gérante privée de users.manage ne compte pas.');
        } catch (BusinessError $error) {
            $this->assertSame('LAST_MANAGER_REQUIRED', $error->errorCode);
        }

        DB::table('user_permissions')->where('user_id', $second->id)->delete();
        StaffRoles::assertAnotherActiveManager($this->clinicId, $this->manager->id);
        $this->addToAssertionCount(1);
    }

    // --- m6 : droits de l'auteur relus sous le verrou du cabinet --------------------------------------

    public function test_une_gerante_desactivee_ou_retrogradee_depuis_sa_requete_ne_peut_plus_agir(): void
    {
        $this->makeStaff($this->clinicId, 'MANAGER', 'gerante2.test');
        $target = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $stale = User::query()->findOrFail($this->manager->id);

        DB::table('users')->where('id', $this->manager->id)->update(['active' => false]);
        $this->assertForbidden(fn () => app(UpdateStaffMember::class)($stale, $target->id, ['expected_version' => 1, 'display_name' => 'Renommé']));

        DB::table('users')->where('id', $this->manager->id)->update(['active' => true]);
        DB::table('user_roles')->where('user_id', $this->manager->id)->update(['revoked_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
        $this->assertForbidden(fn () => app(SetStaffPermissionOverride::class)($stale, $target->id, ['permission_code' => 'exam_fee.collect', 'effect' => 'ALLOW', 'reason' => 'Test', 'expected_version' => 1]));

        $this->assertSame('TEST RECEPTION', DB::table('users')->where('id', $target->id)->value('display_name'));
        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    public function test_l_action_de_derogation_filtre_elle_meme_le_cabinet(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $other = $this->makeStaff($otherClinic, 'RECEPTION', 'accueil.b');

        try {
            app(SetStaffPermissionOverride::class)($this->manager, $other->id, ['permission_code' => 'exam_fee.collect', 'effect' => 'ALLOW', 'reason' => 'Test', 'expected_version' => 1]);
            $this->fail('Compte d\'un autre cabinet : introuvable.');
        } catch (BusinessError $error) {
            $this->assertSame('RESOURCE_NOT_FOUND', $error->errorCode);
        }
        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    // --- M3 : changement de mot de passe limité et journalisé ------------------------------------------

    public function test_le_changement_de_mot_de_passe_est_limite_et_ses_echecs_journalises(): void
    {
        $user = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $this->login('caisse.test')->assertOk();
        $wrong = ['current_password' => 'Faux1234567890', 'password' => 'NouveauSecret2027', 'password_confirmation' => 'NouveauSecret2027'];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->nextRequest()->postJson('/api/v1/auth/password', $wrong)->assertStatus(422);
        }
        // Compteur partagé avec la réauthentification (même compte).
        $this->nextRequest()->postJson('/api/v1/auth/reauthenticate', ['password' => 'Faux1234567890'])->assertStatus(422);
        $this->nextRequest()->postJson('/api/v1/auth/reauthenticate', ['password' => 'Faux1234567890'])->assertStatus(422);
        $this->nextRequest()->postJson('/api/v1/auth/password', [...$wrong, 'current_password' => self::TEST_PASSWORD])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

        $this->assertSame(3, $this->auditCount('auth.password_change_failed', $user->id));
        $this->assertTrue(Hash::check(self::TEST_PASSWORD, (string) DB::table('users')->where('id', $user->id)->value('password_hash')));
    }

    // --- m3 : la session courante reste ouverte, les autres sont fermées --------------------------------

    public function test_changer_son_mot_de_passe_garde_la_session_courante(): void
    {
        $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        $this->login('pro.test')->assertOk();

        $this->nextRequest()->postJson('/api/v1/auth/password', [
            'current_password' => self::TEST_PASSWORD, 'password' => 'NouveauSecret2027', 'password_confirmation' => 'NouveauSecret2027',
        ])->assertNoContent();

        $this->nextRequest()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.login', 'pro.test');
    }

    public function test_changer_son_mot_de_passe_ferme_les_autres_sessions_du_compte(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        $other = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        foreach ([['courante', $user->id], ['autre-poste', $user->id], ['autre-compte', $other->id]] as [$id, $owner]) {
            DB::table('fw_sessions')->insert(['id' => $id, 'user_id' => $owner, 'payload' => 'x', 'last_activity' => time()]);
        }

        app(ChangeOwnPassword::class)($user, ['current_password' => self::TEST_PASSWORD, 'password' => 'NouveauSecret2027', 'password_confirmation' => 'NouveauSecret2027'], 'courante');

        $this->assertSame(['autre-compte', 'courante'], DB::table('fw_sessions')->orderBy('id')->pluck('id')->all());
    }

    // --- M4 : un seul calcul de hash, que le compte existe ou non ---------------------------------------

    public function test_un_identifiant_inconnu_coute_une_seule_verification_de_hash(): void
    {
        $this->login('inconnu.test')->assertStatus(422);
        $this->assertNotEmpty(Cache::get('eeo:auth:dummy-hash:'.hash('sha256', json_encode(config('hashing'), JSON_THROW_ON_ERROR))));

        $counting = new CountingHasher($this->app->make('hash'));
        $this->app->instance('hash', $counting);
        Hash::clearResolvedInstance('hash');

        $this->login('autre.inconnu')->assertStatus(422);

        $this->assertSame(0, $counting->makes, 'Aucun hash calculé pour un identifiant inconnu.');
        $this->assertSame(1, $counting->checks, 'Une seule vérification, comme pour un compte existant.');
    }

    // --- M5 : course sur la création d'un compte -------------------------------------------------------

    public function test_une_creation_concurrente_du_meme_identifiant_repond_422_sans_erreur_journalisee(): void
    {
        Exceptions::fake();
        $request = $this->actingAsReauthenticated($this->manager);
        $injected = false;
        Event::listen(TransactionBeginning::class, function () use (&$injected): void {
            if ($injected) {
                return;
            }
            $injected = true;
            // L'autre requête insère le même identifiant entre la validation et l'insertion.
            DB::table('users')->insert([
                'id' => Ulid::generate(), 'clinic_id' => $this->clinicId, 'display_name' => 'Course',
                'login' => 'course.test', 'password_hash' => Hash::make('Autre2026Secret'), 'active' => true,
            ]);
        });

        $request->postJson('/api/v1/staff', [
            'login' => 'course.test', 'display_name' => 'Course', 'password' => 'InitialFictif2026', 'roles' => ['RECEPTION'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertTrue($injected);
        Exceptions::assertNothingReported();
    }

    // --- M6 : jeton porteur ignoré ---------------------------------------------------------------------

    public function test_un_en_tete_bearer_donne_401_et_non_500(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer 1|abcdef'])->getJson('/api/v1/auth/me')
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->fromSpa()->withHeaders(['Authorization' => 'Bearer 1|abcdef'])->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    // --- m1 : variantes d'identifiant et limite par compte ---------------------------------------------

    public function test_une_variante_d_identifiant_hors_format_ne_trouve_pas_le_compte(): void
    {
        $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');

        $this->login('àccueil.test')->assertStatus(422);
        $this->login("accueil\u{00AD}.test")->assertStatus(422);
        $this->login("accueil.\u{200B}test")->assertStatus(422);
        $this->login(' ACCUEIL.TEST ')->assertOk();
    }

    public function test_dix_echecs_depuis_des_adresses_differentes_bloquent_l_identifiant(): void
    {
        $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');

        for ($address = 1; $address <= 10; $address++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$address])->login('accueil.test', 'MauvaisMotDePasse99')->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->login('accueil.test')
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // --- m2 : audit d'un échec sans auteur ni empreinte attaquable ------------------------------------

    public function test_un_echec_de_connexion_n_a_pas_d_auteur_et_ne_garde_qu_un_hmac(): void
    {
        $victim = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');

        $this->login('accueil.test', 'MauvaisMotDePasse99')->assertStatus(422);

        $event = DB::table('audit_events')->where('action', 'auth.login_failed')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->actor_id);
        $this->assertSame('Tentative de connexion non authentifiée', $event->actor_name_snapshot);
        $this->assertSame($victim->id, $event->target_id);
        $this->assertStringNotContainsString(hash('sha256', 'accueil.test'), (string) $event->sanitized_diff);
    }

    public function test_la_connexion_et_son_audit_sont_dans_la_meme_transaction(): void
    {
        $user = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        DB::beforeExecuting(static function (string $query): void {
            if (str_starts_with($query, 'insert into `audit_events`')) {
                throw new \RuntimeException('Journal indisponible (test)');
            }
        });

        $this->login('accueil.test')->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('last_login_at'), 'Sans audit, la connexion n\'est pas enregistrée.');
    }

    // --- m4 / mineur 3 / mineur 4 : saisies refusées proprement -----------------------------------------

    public function test_parametres_des_documents_objet_obligatoire(): void
    {
        $request = $this->actingAsReauthenticated($this->manager);

        $request->patchJson('/api/v1/clinic/settings', ['document_settings' => ['a', 'b']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['document_settings']]]);
        $request->patchJson('/api/v1/clinic/settings', ['document_settings' => new \stdClass])->assertOk();

        $this->assertSame('{}', DB::table('clinics')->where('id', $this->clinicId)->value('document_settings'));
    }

    public function test_expiration_de_derogation_instant_utc_et_message_francais(): void
    {
        $user = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $request = $this->actingAsReauthenticated($this->manager);
        $override = ['permission_code' => 'exam_fee.collect', 'effect' => 'ALLOW', 'reason' => 'Test', 'expected_version' => 1];

        $request->postJson('/api/v1/staff/'.$user->id.'/permissions', [...$override, 'expires_at' => '2026-10-16'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['expires_at']]]);
        $past = $request->postJson('/api/v1/staff/'.$user->id.'/permissions', [...$override, 'expires_at' => '2020-01-01T00:00:00.000Z'])
            ->assertStatus(422);
        $this->assertStringNotContainsString('validation.', (string) json_encode($past->json('error.fields.expires_at')), 'Message français, jamais une clé brute.');

        $instant = CarbonImmutable::now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z');
        $request->postJson('/api/v1/staff/'.$user->id.'/permissions', [...$override, 'expires_at' => $instant])->assertOk();
        $this->assertSame(
            CarbonImmutable::parse($instant)->format('Y-m-d H:i:s'),
            substr((string) DB::table('user_permissions')->where('user_id', $user->id)->value('expires_at'), 0, 19),
        );
    }

    private function insertOverride(User $user, string $permission, bool $allowed): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('user_permissions')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $user->clinic_id, 'user_id' => $user->id,
            'permission_code' => $permission, 'allowed' => $allowed, 'assigned_by' => $this->manager->id,
            'reason' => 'Ligne ancienne (test)', 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function assertForbidden(\Closure $action): void
    {
        try {
            $action();
            $this->fail('Action refusée attendue.');
        } catch (BusinessError $error) {
            $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
        }
    }
}
