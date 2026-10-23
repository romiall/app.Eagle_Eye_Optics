<?php

namespace App\Http\Resources;

use App\Offline\Compartments;
use App\Offline\DeviceDirectory;
use App\Offline\ServerSigningKey;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation des postes enrôlés et des grants hors-ligne (schemas/resources/operations.json : `EnrolledDevice`,
 * `OfflineGrant`). Aucune clé privée, aucune donnée patient : nom et code du poste, clé PUBLIQUE, responsable, état,
 * profils préparés et validité. Instants UTC à la milliseconde.
 */
final class DevicePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function device(stdClass $row, int $activeGrants): array
    {
        $jwk = json_decode((string) $row->public_key_jwk, true);
        $jwk = is_array($jwk) ? ['kty' => (string) ($jwk['kty'] ?? ''), 'crv' => (string) ($jwk['crv'] ?? ''), 'x' => (string) ($jwk['x'] ?? ''), 'y' => (string) ($jwk['y'] ?? '')] : ['kty' => '', 'crv' => '', 'x' => '', 'y' => ''];

        return [
            'device_id' => (string) $row->id,
            'device_code' => (string) $row->device_code,
            'name' => (string) $row->name,
            'kid' => ServerSigningKey::thumbprint($jwk),
            'public_key_jwk' => $jwk,
            'status' => (string) $row->status,
            'enrolled_at' => self::instant((string) $row->enrolled_at),
            'responsible_user_id' => (string) $row->responsible_user_id,
            'responsible_name' => $row->responsible_name === null ? null : (string) $row->responsible_name,
            'app_version' => (string) $row->app_version,
            'last_sync_at' => $row->last_sync_at === null ? null : self::instant((string) $row->last_sync_at),
            'active_grants' => $activeGrants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function grant(stdClass $row, CarbonImmutable $now): array
    {
        $decoded = json_decode((string) $row->permissions_snapshot, true);
        $permissions = is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];

        return [
            'grant_id' => (string) $row->id,
            'device_id' => (string) $row->device_id,
            'user_id' => (string) $row->user_id,
            'user_name' => $row->user_name === null ? null : (string) $row->user_name,
            'permissions_snapshot' => $permissions,
            'compartments' => Compartments::of($permissions),
            'rules_version' => (string) $row->rules_version,
            'catalog_version' => (string) $row->catalog_version,
            'issued_at' => self::instant((string) $row->issued_at),
            'valid_until' => self::instant((string) $row->valid_until),
            'revoked_at' => $row->revoked_at === null ? null : self::instant((string) $row->revoked_at),
            'state' => DeviceDirectory::grantState($row, $now),
            'signed_payload' => (string) $row->signed_payload,
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
