<?php

namespace Tests\Unit\Offline;

use App\Domain\Shared\Ulid;
use App\Offline\CompactJws;
use App\Offline\Jcs;
use App\Offline\ServerSigningKey;
use App\Offline\SignedCommands;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyncFixtures;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Vérification de l'origine d'une commande de poste (O05 ; contrat 23 « Signature et chiffrement ») sans base : cœur
 * reconstitué et haché en JCS, JWS ES256 de la clé enrôlée (kid = empreinte RFC 7638), charge signée exacte, cabinet,
 * poste et commande du journal. Toute altération est refusée. Données fictives.
 */
final class SignedCommandsTest extends TestCase
{
    use SyncFixtures;

    protected string $clinicId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinicId = Ulid::generate();
        $this->deviceKey = self::newDeviceKey();
        $this->syncDevice = Ulid::generate();
    }

    /**
     * @return array<string, mixed>
     */
    private function core(array $overrides = []): array
    {
        return array_merge([
            'command_id' => Ulid::generate(),
            'command_name' => 'CreatePatient',
            'device_id' => $this->syncDevice,
            'actor_id' => Ulid::generate(),
            'grant_id' => Ulid::generate(),
            'clinic_id' => $this->clinicId,
            'device_sequence' => '1',
            'previous_event_hash' => null,
            'schema_version' => '1',
            'rules_version' => '2026-10-14.1',
            'occurred_at_local' => '2026-10-22T09:20:00.000Z',
            'last_server_time' => '2026-10-22T09:00:00.000Z',
            'entity_type' => 'patient_records',
            'entity_id' => Ulid::generate(),
            'expected_version' => null,
            'number_allocation_id' => Ulid::generate(),
            'stock_allocation_ids' => [],
            'payload' => ['last_name' => 'PATIENT FICTIF', 'guardian_contact' => new \stdClass],
        ], $overrides);
    }

    private static function asReceived(array $signed): mixed
    {
        // Comme le serveur la lit : objets JSON conservés (un objet vide reste « {} »).
        return json_decode((string) json_encode($signed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
    }

    public function test_une_commande_signee_par_la_cle_enrolee_est_verifiee_a_l_identique(): void
    {
        $signed = $this->sign($this->core());

        $verified = SignedCommands::verify(self::asReceived($signed), $this->clinicId, $this->syncDevice, $this->deviceKey['jwk']);

        $this->assertSame($signed['event_hash'], $verified->eventHash);
        $this->assertSame(Jcs::sha256(self::asReceived($signed)->core), $verified->eventHash, 'empreinte du cœur reçu, objet vide compris');
        $this->assertSame(1, $verified->deviceSequence);
        $this->assertNull($verified->previousEventHash);
        $this->assertSame('2026-10-22T09:20:00.000Z', SignedCommands::format($verified->occurredAtLocal));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function alterations(): array
    {
        return [
            'charge modifiée après signature' => ['payload'],
            'empreinte annoncée fausse' => ['hash'],
            'clé d\'un autre poste' => ['key'],
            'kid ULID (ancien contrat)' => ['kid'],
            'autre cabinet' => ['clinic'],
            'autre poste que le lot' => ['device'],
            'commande hors du journal' => ['command'],
            'signature tronquée' => ['truncated'],
        ];
    }

    #[DataProvider('alterations')]
    public function test_toute_alteration_est_refusee(string $alteration): void
    {
        $core = $this->core($alteration === 'command' ? ['command_name' => 'EnrollDevice'] : []);
        $signed = match ($alteration) {
            'key' => $this->sign($core, self::newDeviceKey()),
            'kid' => [
                'core' => $core,
                'event_hash' => Jcs::sha256($core),
                'signature' => CompactJws::sign(['command_id' => $core['command_id'], 'device_id' => $core['device_id'], 'event_hash' => Jcs::sha256($core)], $this->deviceKey['pem'], Ulid::generate(), SignedCommands::JWS_TYPE),
            ],
            default => $this->sign($core),
        };
        $received = self::asReceived($signed);
        $clinic = $this->clinicId;
        $device = $this->syncDevice;
        match ($alteration) {
            'payload' => $received->core->payload->last_name = 'AUTRE NOM',
            'hash' => $received->event_hash = str_repeat('0', 64),
            'clinic' => $clinic = Ulid::generate(),
            'device' => $device = Ulid::generate(),
            'truncated' => $received->signature = substr((string) $received->signature, 0, -4),
            default => null,
        };

        $this->expectException(UnexpectedValueException::class);
        SignedCommands::verify($received, $clinic, $device, $this->deviceKey['jwk']);
    }

    public function test_le_kid_attendu_est_l_empreinte_rfc_7638_de_la_cle_du_poste(): void
    {
        $signed = $this->sign($this->core());
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $signed['signature'])[0]), true);

        $this->assertSame(['alg' => 'ES256', 'kid' => ServerSigningKey::thumbprint($this->deviceKey['jwk']), 'typ' => 'eeo-command+jws'], $header);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $header['kid']);
    }
}
