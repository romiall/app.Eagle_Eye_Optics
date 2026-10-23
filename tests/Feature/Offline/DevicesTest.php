<?php

namespace Tests\Feature\Offline;

use App\Domain\Shared\Ulid;
use App\Models\User;
use App\Offline\CompactJws;
use App\Offline\DeviceProof;
use App\Offline\GrantClaims;
use App\Offline\Jcs;
use App\Offline\ServerSigningKey;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Préparation des postes (O02, AT36, AT43 ; EE016, EE020, EE042) sur la vraie base : enrôlement d'un poste par la
 * gérante réauthentifiée avec preuve de possession de sa clé, grants SIGNÉS par la clé du serveur (charge JCS
 * vérifiable, permissions effectives et compartiments figés, catalogue figé), rotation au réémission, révocations,
 * droits, module fermé, isolation des cabinets. Tout est fictif ; la clé du serveur vit dans un fichier temporaire.
 */
final class DevicesTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    private string $clinicId;

    private User $manager;

    private User $reception;

    private User $cashier;

    private User $clinician;

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée AVANT la création des comptes (sinon leurs profils seraient « futurs »).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:30:00', 'UTC'));
        $this->keyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eeo-grant-key-'.Ulid::generate().DIRECTORY_SEPARATOR.'grant-signing.pem';
        config(['eeo.enabled_modules' => ['offline', 'settings'], 'eeo.offline.signing_key_path' => $this->keyPath, 'eeo.offline.grant_hours' => 72]);

        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $this->clinician = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        app(ServerSigningKey::class)->generate();
    }

    protected function tearDown(): void
    {
        foreach ([$this->keyPath, ...(glob(dirname($this->keyPath).'/retired/*.json') ?: [])] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Paire ES256 de poste fictive : clé privée PEM et clé publique JWK.
     *
     * @return array{pem: string, jwk: array{kty: string, crv: string, x: string, y: string}}
     */
    private function deviceKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        openssl_pkey_export($key, $pem);
        $ec = openssl_pkey_get_details($key)['ec'] ?? [];

        return ['pem' => (string) $pem, 'jwk' => [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => JWT::urlsafeB64Encode(str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => JWT::urlsafeB64Encode(str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT)),
        ]];
    }

    /**
     * @param  array{pem: string, jwk: array{kty: string, crv: string, x: string, y: string}}  $key
     * @param  array<string, mixed>  $overrides
     */
    private function proof(array $key, string $deviceCode, array $overrides = []): string
    {
        $thumbprint = ServerSigningKey::thumbprint($key['jwk']);

        return CompactJws::sign(array_merge([
            'clinic_id' => $this->clinicId,
            'device_code' => $deviceCode,
            'issued_at' => GrantClaims::instant(CarbonImmutable::now('UTC')),
            'jwk_thumbprint' => $thumbprint,
            'purpose' => 'enroll',
            'v' => 1,
        ], $overrides), $key['pem'], $thumbprint, DeviceProof::TYPE);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function enrollBody(array $key, string $deviceCode = 'CAISSE-1', array $overrides = []): array
    {
        return array_merge([
            'expected_version' => null,
            'device_code' => $deviceCode,
            'name' => 'Caisse principale (fictive)',
            'public_key_jwk' => $key['jwk'],
            'responsible_user_id' => $this->manager->id,
            'app_version' => '0.1.0-test',
            'proof' => $this->proof($key, $deviceCode),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postAs(string $uri, array $body, ?User $as = null, bool $reauthenticated = true): TestResponse
    {
        $actor = $as ?? $this->manager;
        $client = $reauthenticated ? $this->actingAsReauthenticated($actor) : $this->actingAsFresh($actor);

        return $client->withHeaders(['Idempotency-Key' => Ulid::generate()])->postJson($uri, $body);
    }

    private function enroll(?string $code = null): string
    {
        $response = $this->postAs('/api/v1/devices/enroll', $this->enrollBody($this->deviceKey(), $code ?? 'CAISSE-1'))->assertCreated();

        return (string) $response->json('data.device_id');
    }

    public function test_la_gerante_enrole_un_poste_avec_la_preuve_de_sa_cle_et_seule_la_cle_publique_est_conservee(): void
    {
        $key = $this->deviceKey();

        $response = $this->postAs('/api/v1/devices/enroll', $this->enrollBody($key));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.device_code', 'CAISSE-1')
            ->assertJsonPath('data.public_key_jwk', $key['jwk'])
            ->assertJsonPath('data.kid', ServerSigningKey::thumbprint($key['jwk']))
            ->assertJsonPath('data.active_grants', 0);
        $row = DB::table('enrolled_devices')->where('id', $response->json('data.device_id'))->first();
        $this->assertNotNull($row);
        $this->assertSame(Jcs::encode($key['jwk']), $row->public_key_jwk);
        $this->assertStringNotContainsString('"d"', (string) $row->public_key_jwk);
        $this->assertSame(1, $this->auditCount('device.enrolled'));
    }

    public function test_une_preuve_absente_alteree_d_un_autre_cabinet_ou_perimee_est_refusee(): void
    {
        $key = $this->deviceKey();
        $other = $this->deviceKey();
        $cases = [
            'autre clé' => $this->proof($other, 'CAISSE-1'),
            'autre cabinet' => $this->proof($key, 'CAISSE-1', ['clinic_id' => Ulid::generate()]),
            'autre code' => $this->proof($key, 'CAISSE-2'),
            'périmée' => $this->proof($key, 'CAISSE-1', ['issued_at' => '2026-10-22T09:00:00.000Z']),
            'future' => $this->proof($key, 'CAISSE-1', ['issued_at' => '2026-10-22T10:00:00.000Z']),
            'autre usage' => $this->proof($key, 'CAISSE-1', ['purpose' => 'login']),
        ];
        foreach ($cases as $label => $proof) {
            $this->postAs('/api/v1/devices/enroll', $this->enrollBody($key, 'CAISSE-1', ['proof' => $proof]))
                ->assertStatus(422)
                ->assertJsonStructure(['error' => ['fields' => ['proof']]]);
            $this->assertSame(0, DB::table('enrolled_devices')->count(), $label);
        }
    }

    public function test_code_ou_cle_deja_enroles_et_responsable_inactif_sont_refuses_sans_erreur_sql(): void
    {
        $key = $this->deviceKey();
        $this->postAs('/api/v1/devices/enroll', $this->enrollBody($key))->assertCreated();

        $this->postAs('/api/v1/devices/enroll', $this->enrollBody($this->deviceKey()))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['device_code']]]);
        $this->postAs('/api/v1/devices/enroll', $this->enrollBody($key, 'CAISSE-2'))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['public_key_jwk']]]);
        DB::table('users')->where('id', $this->cashier->id)->update(['active' => 0]);
        $this->postAs('/api/v1/devices/enroll', $this->enrollBody($this->deviceKey(), 'CAISSE-3', ['responsible_user_id' => $this->cashier->id]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['responsible_user_id']]]);
        $this->assertSame(1, DB::table('enrolled_devices')->count());
    }

    public function test_reauthentification_droit_et_module_sont_exiges(): void
    {
        $body = $this->enrollBody($this->deviceKey());

        $this->postAs('/api/v1/devices/enroll', $body, null, false)->assertStatus(423)->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        foreach ([$this->reception, $this->cashier, $this->clinician] as $user) {
            $this->postAs('/api/v1/devices/enroll', $body, $user)->assertForbidden();
            $this->actingAsFresh($user)->getJson('/api/v1/devices')->assertForbidden();
        }
        config(['eeo.enabled_modules' => ['settings']]);
        $this->postAs('/api/v1/devices/enroll', $body)->assertNotFound();
        $this->actingAsFresh($this->manager)->getJson('/api/v1/offline/signing-keys')->assertNotFound();
        $this->assertSame(0, DB::table('enrolled_devices')->count());
    }

    public function test_le_grant_est_signe_par_la_cle_du_serveur_et_fige_droits_compartiments_et_catalogue(): void
    {
        $deviceId = $this->enroll();

        $response = $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id]);

        $response->assertCreated()
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.user_id', $this->cashier->id)
            ->assertJsonPath('data.compartments', ['ADMINISTRATIVE', 'FINANCIAL'])
            ->assertJsonPath('data.valid_until', '2026-10-25T09:30:00.000Z');
        $keys = $this->actingAsFresh($this->manager)->getJson('/api/v1/offline/signing-keys')->assertOk();
        $current = $keys->json('data.keys.0');
        $this->assertSame('CURRENT', $current['status']);
        $this->assertArrayNotHasKey('d', $current['jwk']);

        $claims = CompactJws::verify((string) $response->json('data.signed_payload'), $current['jwk'], (string) $current['kid'], GrantClaims::TYPE);
        $this->assertSame($deviceId, $claims['device_id']);
        $this->assertSame($this->cashier->id, $claims['user_id']);
        $this->assertSame($this->clinicId, $claims['clinic_id']);
        $this->assertSame(['ADMINISTRATIVE', 'FINANCIAL'], $claims['compartments']);
        $this->assertNotContains('clinical.read', $claims['permissions']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $claims['catalog_sha256']);
        $this->assertSame($response->json('data.permissions_snapshot'), $claims['permissions']);
        $this->assertSame(1, $this->auditCount('offline_grant.issued'));
    }

    public function test_un_nouveau_grant_du_meme_profil_revoque_le_precedent_et_le_jeton_reste_immuable(): void
    {
        $deviceId = $this->enroll();
        $first = $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->clinician->id])->assertCreated();
        $token = (string) $first->json('data.signed_payload');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 10:30:00', 'UTC'));
        $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->clinician->id, 'valid_hours' => 8])
            ->assertCreated()
            ->assertJsonPath('data.valid_until', '2026-10-22T18:30:00.000Z');

        $previous = DB::table('offline_grants')->where('id', $first->json('data.grant_id'))->first();
        $this->assertNotNull($previous->revoked_at);
        $this->assertSame($token, $previous->signed_payload);
        $list = $this->actingAsFresh($this->manager)->getJson('/api/v1/devices/'.$deviceId.'/grants')->assertOk();
        $this->assertSame(['ACTIVE', 'REVOKED'], array_column($list->json('data'), 'state'));
    }

    public function test_duree_hors_bornes_compte_inactif_poste_inconnu_et_cle_absente_sont_refuses(): void
    {
        $deviceId = $this->enroll();

        $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id, 'valid_hours' => 500])->assertStatus(422);
        DB::table('users')->where('id', $this->reception->id)->update(['active' => 0]);
        $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->reception->id])->assertStatus(422);
        $this->postAs('/api/v1/devices/'.Ulid::generate().'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id])->assertNotFound();

        unlink($this->keyPath);
        $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->assertSame(0, DB::table('offline_grants')->count());
    }

    public function test_la_revocation_d_un_poste_revoque_ses_grants_et_bloque_toute_nouvelle_emission(): void
    {
        $deviceId = $this->enroll();
        $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id])->assertCreated();

        $this->postAs('/api/v1/devices/'.$deviceId.'/revoke', ['expected_version' => null, 'status' => 'LOST', 'reason' => 'x'])->assertStatus(422);
        $this->postAs('/api/v1/devices/'.$deviceId.'/revoke', ['expected_version' => null, 'status' => 'LOST', 'reason' => 'Poste égaré (fictif)'])
            ->assertOk()
            ->assertJsonPath('data.status', 'LOST')
            ->assertJsonPath('data.active_grants', 0);

        $this->assertSame(0, DB::table('offline_grants')->where('device_id', $deviceId)->whereNull('revoked_at')->count());
        $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id])->assertStatus(409);
        $this->postAs('/api/v1/devices/'.$deviceId.'/revoke', ['expected_version' => null, 'status' => 'REVOKED', 'reason' => 'Deuxième fois'])->assertStatus(409);
    }

    public function test_un_grant_se_revoque_une_seule_fois_avec_motif(): void
    {
        $deviceId = $this->enroll();
        $grantId = (string) $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id])->json('data.grant_id');

        $this->postAs('/api/v1/offline-grants/'.$grantId.'/revoke', ['expected_version' => null, 'reason' => 'Profil changé de poste'])
            ->assertOk()->assertJsonPath('data.state', 'REVOKED');
        $this->postAs('/api/v1/offline-grants/'.$grantId.'/revoke', ['expected_version' => null, 'reason' => 'Encore'])
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->assertSame(1, $this->auditCount('offline_grant.revoked'));
    }

    public function test_un_autre_cabinet_ne_voit_ni_ne_touche_les_postes(): void
    {
        $deviceId = $this->enroll();
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $otherManager = $this->makeStaff($otherClinic, 'MANAGER', 'gerante.b');

        $this->actingAsFresh($otherManager)->getJson('/api/v1/devices/'.$deviceId)->assertNotFound();
        $this->actingAsFresh($otherManager)->getJson('/api/v1/devices')->assertOk()->assertJsonPath('data', []);
        $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $otherManager->id], $otherManager)->assertNotFound();
        $this->postAs('/api/v1/devices/'.$deviceId.'/revoke', ['expected_version' => null, 'status' => 'REVOKED', 'reason' => 'Intrusion'], $otherManager)->assertNotFound();
    }

    public function test_la_rotation_garde_la_cle_retiree_pour_verifier_les_grants_deja_emis(): void
    {
        $deviceId = $this->enroll();
        $grant = $this->postAs('/api/v1/devices/'.$deviceId.'/grants', ['expected_version' => null, 'user_id' => $this->cashier->id])->assertCreated();
        $before = app(ServerSigningKey::class)->kid();

        $this->artisan('eeo:offline:signing-key', ['--rotate' => true])->assertSuccessful();

        $keys = $this->actingAsFresh($this->manager)->getJson('/api/v1/offline/signing-keys')->assertOk()->json('data.keys');
        $this->assertSame(['CURRENT', 'RETIRED'], array_column($keys, 'status'));
        $this->assertNotSame($before, $keys[0]['kid']);
        $this->assertSame($before, $keys[1]['kid']);
        $claims = CompactJws::verify((string) $grant->json('data.signed_payload'), $keys[1]['jwk'], $before, GrantClaims::TYPE);
        $this->assertSame($deviceId, $claims['device_id']);
    }

    public function test_la_commande_ne_remplace_jamais_une_cle_sans_rotation_explicite(): void
    {
        $kid = app(ServerSigningKey::class)->kid();

        $this->artisan('eeo:offline:signing-key')->assertSuccessful();
        $this->artisan('eeo:offline:signing-key', ['--show' => true])->expectsOutputToContain($kid)->assertSuccessful();

        $this->assertSame($kid, app(ServerSigningKey::class)->kid());
    }
}
