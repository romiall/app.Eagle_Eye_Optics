<?php

namespace Tests\Support;

use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Postes préparés et grants FICTIFS pour les tests des ressources d'un poste (O03). Le poste et ses grants sont insérés
 * directement (leur enrôlement et leur signature sont couverts par O02, `DevicesTest`) : le jeton signé y est factice,
 * les permissions figées sont les permissions effectives du compte à l'instant de la fixture. S'emploie avec
 * BillingFixtures (cabinet, comptes, articles, stock, factures).
 */
trait OfflineResourceFixtures
{
    /**
     * Grant ACTIF du compte sur le poste (par défaut valable jusqu'au 25/10/2026, 72 h après 09 h 30 le 22/10).
     *
     * @param  list<string>|null  $permissions  permissions figées (par défaut : celles du compte)
     */
    protected function seedGrant(string $deviceId, User $user, string $validUntil = '2026-10-25 09:30:00.000000', ?array $permissions = null, ?string $revokedAt = null): string
    {
        $id = Ulid::generate();
        $snapshot = $permissions ?? app(EffectivePermissions::class)->for($user);
        DB::table('offline_grants')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'device_id' => $deviceId,
            'user_id' => $user->id,
            'permissions_snapshot' => json_encode(array_values($snapshot), JSON_THROW_ON_ERROR),
            'rules_version' => 'test',
            'catalog_version' => 'catalogue-test',
            'issued_at' => '2026-10-22 08:00:00.000000',
            'valid_until' => $validUntil,
            'signed_payload' => 'eyJ0ZXN0IjoiZmljdGlmIn0.e30.ZmljdGlm',
            'revoked_at' => $revokedAt,
            'created_at' => '2026-10-22 09:00:00.000000',
            'updated_at' => '2026-10-22 09:00:00.000000',
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function prepareDevice(string $deviceId, array $body, ?User $as = null, ?string $key = null, bool $reauthenticated = true): TestResponse
    {
        $actor = $as ?? $this->manager;
        $client = $reauthenticated ? $this->actingAsReauthenticated($actor) : $this->actingAsFresh($actor);

        return $client->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/devices/'.$deviceId.'/prepare', ['expected_version' => null] + $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function releaseDevice(string $deviceId, array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAsReauthenticated($as ?? $this->manager)
            ->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson('/api/v1/devices/'.$deviceId.'/release', array_merge(['expected_version' => null, 'last_device_sequence' => '0', 'last_event_hash' => null, 'reason' => 'Reprise complète vérifiée (test)'], $body));
    }

    /**
     * @return list<array{start: int, end: int}>
     */
    protected function rangesOf(string $type, int $year = 2026): array
    {
        return DB::table('document_number_allocations')
            ->where('clinic_id', $this->clinicId)
            ->where('document_type', $type)
            ->where('document_year', $year)
            ->orderBy('range_start')
            ->get(['range_start', 'range_end'])
            ->map(static fn (object $row): array => ['start' => (int) $row->range_start, 'end' => (int) $row->range_end])
            ->all();
    }
}
