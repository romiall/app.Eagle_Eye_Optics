<?php

namespace Tests\Support;

use App\Domain\Shared\Ulid;
use App\Models\User;
use App\Offline\CompactJws;
use App\Offline\Jcs;
use App\Offline\ServerSigningKey;
use App\Offline\SignedCommands;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;

/**
 * Poste préparé FICTIF et journal signé pour les tests de synchronisation (O05) : vraie paire ES256 du poste (clé
 * publique enrôlée, clé privée gardée par le test), plages de numéros insérées directement (leur préparation est
 * couverte par O03), cœurs du contrat 23 hachés en JCS et signés comme le fait le navigateur. Le journal simulé
 * enchaîne rangs et empreintes ; `forkJournal()` permet de rejouer ou d'altérer une commande. Données fictives.
 *
 * S'emploie avec BillingFixtures et OfflineResourceFixtures (cabinet, comptes, articles, grants).
 */
trait SyncFixtures
{
    /** @var array{pem: string, jwk: array{kty: string, crv: string, x: string, y: string}} */
    protected array $deviceKey;

    protected string $syncDevice;

    protected int $journalSequence = 0;

    protected ?string $journalHead = null;

    /**
     * @return array{pem: string, jwk: array{kty: string, crv: string, x: string, y: string}}
     */
    protected static function newDeviceKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Clé ES256 de test impossible à créer.');
        }
        openssl_pkey_export($key, $pem);
        $ec = openssl_pkey_get_details($key)['ec'] ?? [];

        return ['pem' => (string) $pem, 'jwk' => [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => JWT::urlsafeB64Encode(str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => JWT::urlsafeB64Encode(str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT)),
        ]];
    }

    /** Poste enrôlé avec une VRAIE clé publique ES256 ; devient le poste courant du journal simulé. */
    protected function signingDevice(string $name = 'Caisse hors-ligne (fictive)', string $status = 'ACTIVE'): string
    {
        $this->deviceKey = self::newDeviceKey();
        $device = Ulid::generate();
        DB::table('enrolled_devices')->insert([
            'id' => $device, 'clinic_id' => $this->clinicId, 'device_code' => 'POSTE-'.substr($device, -8), 'name' => $name,
            'public_key_jwk' => json_encode($this->deviceKey['jwk'], JSON_THROW_ON_ERROR), 'key_algorithm' => 'ES256', 'status' => $status,
            'enrolled_by' => $this->manager->id, 'responsible_user_id' => $this->manager->id, 'enrolled_at' => '2026-10-22 08:00:00.000000',
            'app_version' => '0.0.0-test',
        ]);
        $this->syncDevice = $device;
        $this->journalSequence = 0;
        $this->journalHead = null;

        return $device;
    }

    /** Plage de numéros ACTIVE du poste (insérée directement ; fixer des bornes hors des numéros déjà pris). */
    protected function seedRange(string $grantId, string $type, int $start, int $end, int $year = 2026, ?string $deviceId = null): string
    {
        $id = Ulid::generate();
        DB::table('document_number_allocations')->insert([
            'id' => $id, 'clinic_id' => $this->clinicId, 'device_id' => $deviceId ?? $this->syncDevice, 'grant_id' => $grantId,
            'document_type' => $type, 'document_year' => $year, 'range_start' => $start, 'range_end' => $end,
            'last_consumed_value' => null, 'status' => 'ACTIVE', 'allocated_at' => '2026-10-22 08:30:00.000000', 'closed_at' => null, 'version' => 1,
        ]);

        return $id;
    }

    /**
     * Commande signée du journal simulé (rang suivant, chaînée sur la précédente).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $quotas
     * @param  array<string, mixed>  $coreOverrides
     * @return array{core: array<string, mixed>, event_hash: string, signature: string}
     */
    protected function signed(
        string $name,
        array $payload,
        string $entityType,
        string $entityId,
        User $actor,
        string $grantId,
        ?int $expectedVersion = null,
        ?string $allocationId = null,
        array $quotas = [],
        string $occurredAt = '2026-10-22T09:20:00.000Z',
        array $coreOverrides = [],
    ): array {
        $this->journalSequence++;
        $core = array_merge([
            'command_id' => Ulid::generate(),
            'command_name' => $name,
            'device_id' => $this->syncDevice,
            'actor_id' => $actor->id,
            'grant_id' => $grantId,
            'clinic_id' => $this->clinicId,
            'device_sequence' => (string) $this->journalSequence,
            'previous_event_hash' => $this->journalHead,
            'schema_version' => '1',
            'rules_version' => '2026-10-14.1',
            'occurred_at_local' => $occurredAt,
            'last_server_time' => '2026-10-22T09:00:00.000Z',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'expected_version' => $expectedVersion,
            'number_allocation_id' => $allocationId,
            'stock_allocation_ids' => $quotas,
            'payload' => $payload,
        ], $coreOverrides);

        $signed = $this->sign($core);
        $this->journalHead = $signed['event_hash'];

        return $signed;
    }

    /**
     * Empreinte et signature d'un cœur donné (clé du poste courant, sauf clé fournie).
     *
     * @param  array<string, mixed>  $core
     * @param  array{pem: string, jwk: array{kty: string, crv: string, x: string, y: string}}|null  $key
     * @return array{core: array<string, mixed>, event_hash: string, signature: string}
     */
    protected function sign(array $core, ?array $key = null): array
    {
        $key ??= $this->deviceKey;
        $hash = Jcs::sha256($core);

        return [
            'core' => $core,
            'event_hash' => $hash,
            'signature' => CompactJws::sign(
                ['command_id' => $core['command_id'], 'device_id' => $core['device_id'], 'event_hash' => $hash],
                $key['pem'],
                ServerSigningKey::thumbprint($key['jwk']),
                SignedCommands::JWS_TYPE,
            ),
        ];
    }

    /** Ramène la tête du journal simulé au rang donné (rejeu, ou journal divergent). */
    protected function forkJournal(int $sequence, ?string $head): void
    {
        $this->journalSequence = $sequence;
        $this->journalHead = $head;
    }

    /**
     * @param  list<array{core: array<string, mixed>, event_hash: string, signature: string}>  $commands
     */
    protected function push(array $commands, ?User $as = null, ?string $deviceId = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->cashier)->postJson('/api/v1/sync/push', [
            'device_id' => $deviceId ?? $this->syncDevice,
            'commands' => $commands,
        ]);
    }

    /**
     * Charge CreatePatient d'un poste : numéro pris dans la plage du poste.
     *
     * @return array<string, mixed>
     */
    protected function patientPayload(string $recordNumber, array $overrides = []): array
    {
        return array_merge([
            'patient_id' => Ulid::generate(),
            'record_number_usage_id' => Ulid::generate(),
            'record_number' => $recordNumber,
            'last_name' => 'PATIENT HORS LIGNE',
            'first_names' => 'Fictive',
            'sex' => 'F',
            'birth_date' => '1985-03-04',
            'birth_date_missing_reason' => null,
            'phone_primary' => '600000002',
            'phone_secondary' => null,
            'phone_missing_reason' => null,
            'address' => null,
            'profession' => null,
            'guardian_contact' => null,
            'legacy_record' => false,
            'first_known_visit_on' => null,
            'first_visit_certified' => false,
            'legacy_record_number' => null,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    protected function syncDraftPayload(string $invoiceId, string $patientId, array $lines): array
    {
        return ['invoice_id' => $invoiceId, 'patient_id' => $patientId, 'visit_id' => null, 'due_date' => null, 'lines' => $lines];
    }

    /**
     * @param  list<array<string, mixed>>  $reservations
     * @return array<string, mixed>
     */
    protected function syncIssuePayload(string $invoiceId, string $number, int $total, array $reservations = [], string $issuedAt = '2026-10-22T09:21:00.000Z'): array
    {
        return [
            'invoice_id' => $invoiceId,
            'number_usage_id' => Ulid::generate(),
            'number' => $number,
            'issued_at' => $issuedAt,
            'total_gross' => $total,
            'write_owner_id' => Ulid::generate(),
            'catalog_snapshot_sha256' => str_repeat('b', 64),
            'reservations' => $reservations,
            'document_id' => Ulid::generate(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncPaymentPayload(string $invoiceId, string $receiptNumber, int $amount, string $effectiveAt = '2026-10-22T09:22:00.000Z', array $overrides = []): array
    {
        return array_merge([
            'invoice_id' => $invoiceId,
            'payment_id' => Ulid::generate(),
            'receipt_id' => Ulid::generate(),
            'receipt_number_usage_id' => Ulid::generate(),
            'receipt_number' => $receiptNumber,
            'amount' => $amount,
            'currency' => 'XAF',
            'method' => 'MTN_MOMO',
            'reference' => 'MOMO-FICTIF-'.substr(Ulid::generate(), -6),
            'effective_at' => $effectiveAt,
            'cash_session_id' => null,
            'retroactive_reason' => null,
            'receipt_document_id' => Ulid::generate(),
        ], $overrides);
    }
}
