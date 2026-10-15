<?php

namespace Tests\Support;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBus;
use App\Application\CommandBusProbe;
use App\Application\CommandDefinition;
use App\Application\CommandRegistry;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\GrantSnapshot;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commands\MisbehavingHandler;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\Commands\TestCommands;

/**
 * Fixtures FICTIVES du chemin central : cabinet, comptes, compteur à consommer, poste et grant, commandes de
 * test, bus avec sonde. Aucune donnée réelle.
 */
trait CommandFixtures
{
    use AccessFixtures;

    public const MISBEHAVING = 'TestMisbehaving';

    public const CONTRACT_CHECKED = 'TestRegisterPaymentContract';

    protected function registerTestCommands(): void
    {
        $registry = $this->app->make(CommandRegistry::class);
        TestCommands::register($registry);
        $registry->register(new CommandDefinition(self::MISBEHAVING, MisbehavingHandler::class, [CommandSource::Direct]));
        $registry->register(new CommandDefinition(
            self::CONTRACT_CHECKED,
            Commands\ConsumeCounterHandler::class,
            [CommandSource::Direct],
            'commands/RegisterPayment.v1.json',
            true,
        ));
    }

    /**
     * Compteur consommable (haut-niveau PATIENT 2026) : `last_value` joue le rôle d'un solde.
     */
    protected function makeCounter(string $clinicId, int $balance = 50000): string
    {
        $id = Ulid::generate();
        DB::table('document_sequences')->insert([
            'id' => $id,
            'clinic_id' => $clinicId,
            'document_type' => Commands\ConsumeCounterHandler::COUNTER_TYPE,
            'document_year' => Commands\ConsumeCounterHandler::COUNTER_YEAR,
            'last_value' => $balance,
        ]);

        return $id;
    }

    protected function consume(string $counterId, int $amount, ?int $expectedVersion = null, string $name = TestCommands::CONSUME): Command
    {
        return new Command($name, ['amount' => $amount], $counterId, $expectedVersion);
    }

    protected function directContext(User $user, string $correlationId = 'corr-test-0001'): CommandContext
    {
        return CommandContext::direct($user, $correlationId);
    }

    protected function bus(?Checkpoint $failAt = null, ?RecordingProbe &$probe = null): CommandBus
    {
        $probe = new RecordingProbe($failAt);
        $this->app->instance(CommandBusProbe::class, $probe);

        return $this->app->make(CommandBus::class);
    }

    protected function counterBalance(string $counterId): int
    {
        return (int) DB::table('document_sequences')->where('id', $counterId)->value('last_value');
    }

    protected function counterVersion(string $counterId): int
    {
        return (int) DB::table('document_sequences')->where('id', $counterId)->value('version');
    }

    /**
     * Effets durables écrits pour un cabinet : audit de test, outbox, changements à diffuser, clés d'idempotence.
     *
     * @return array{audit: int, outbox: int, changes: int, idempotency: int}
     */
    protected function effectCounts(string $clinicId): array
    {
        return [
            'audit' => DB::table('audit_events')->where('clinic_id', $clinicId)->where('action', 'like', 'test.%')->count(),
            'outbox' => DB::table('outbox_events')->where('clinic_id', $clinicId)->count(),
            'changes' => DB::table('sync_change_log')->where('clinic_id', $clinicId)->count(),
            'idempotency' => DB::table('idempotency_requests')->where('clinic_id', $clinicId)->count(),
        ];
    }

    protected function makeDevice(string $clinicId, string $enrolledBy): string
    {
        $id = Ulid::generate();
        DB::table('enrolled_devices')->insert([
            'id' => $id,
            'clinic_id' => $clinicId,
            'device_code' => 'POSTE-'.substr($id, -8),
            'name' => 'Poste de test',
            'public_key_jwk' => '{"kty":"EC","crv":"P-256","x":"TEST","y":"TEST"}',
            'key_algorithm' => 'ES256',
            'status' => 'ACTIVE',
            'enrolled_by' => $enrolledBy,
            'responsible_user_id' => $enrolledBy,
            'enrolled_at' => '2026-10-14 08:00:00.000000',
            'app_version' => '0.0.0-test',
        ]);

        return $id;
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function makeGrant(string $clinicId, string $deviceId, string $userId, array $permissions, string $issuedAt, string $validUntil, ?string $revokedAt = null): GrantSnapshot
    {
        $id = Ulid::generate();
        DB::table('offline_grants')->insert([
            'id' => $id,
            'clinic_id' => $clinicId,
            'device_id' => $deviceId,
            'user_id' => $userId,
            'permissions_snapshot' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'rules_version' => 'rules-test-1',
            'catalog_version' => 'catalog-test-1',
            'issued_at' => $issuedAt,
            'valid_until' => $validUntil,
            'signed_payload' => 'entete.charge.signature',
            'revoked_at' => $revokedAt,
        ]);

        return new GrantSnapshot(
            $id, $deviceId, $userId, $permissions, 'rules-test-1', 'catalog-test-1',
            CarbonImmutable::parse($issuedAt, 'UTC'), CarbonImmutable::parse($validUntil, 'UTC'),
            $revokedAt === null ? null : CarbonImmutable::parse($revokedAt, 'UTC'),
        );
    }

    protected function syncContext(string $clinicId, string $deviceId, User $actor, GrantSnapshot $grant, string $occurredAtLocal, string $lastServerTime, string $now): CommandContext
    {
        return CommandContext::sync(
            $clinicId, $deviceId, $actor->id, $actor->display_name, $grant, 'corr-sync-0001',
            CarbonImmutable::parse($occurredAtLocal, 'UTC'), CarbonImmutable::parse($lastServerTime, 'UTC'), CarbonImmutable::parse($now, 'UTC'),
        );
    }
}
