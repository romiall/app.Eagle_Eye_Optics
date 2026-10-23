<?php

namespace App\Offline;

use App\Domain\Shared\GrantSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lectures des postes enrôlés et de leurs grants (gérante, `offline.enroll`), toujours limitées au cabinet. Aucune
 * donnée personnelle ni clinique : nom du poste, statut, responsable (nom affiché), version, dates, profils préparés et
 * validité. Le jeton signé d'un grant n'est rendu qu'à la lecture d'un grant précis (`grants`), pour que le poste le
 * vérifie et le conserve.
 */
final class DeviceDirectory
{
    /**
     * Ligne d'un poste, d'un compte ou d'une ressource lue SOUS le verrou exclusif du cabinet déjà tenu par le chemin
     * central. Les commandes de préparation (enrôlement, grants, plages, révocations) prennent l'idempotence (rang 30)
     * avant d'entrer dans le gestionnaire : redemander ensuite le compte (15) ou le poste (20) violerait l'ordre du
     * contrat 23 (`LockOrderViolation`). Toutes les écritures de ces lignes passent par le chemin central et tiennent
     * le verrou du cabinet : il les sérialise déjà, une lecture simple suffit (aucun verrou hors ordre).
     */
    public static function underClinicLock(string $table, string $clinicId, string $id): ?stdClass
    {
        return DB::table($table)->where('clinic_id', $clinicId)->where('id', $id)->first();
    }

    /**
     * @return list<stdClass>
     */
    public static function devices(string $clinicId): array
    {
        return array_values(DB::table('enrolled_devices as d')
            ->leftJoin('users as r', static function ($join): void {
                $join->on('r.clinic_id', '=', 'd.clinic_id')->on('r.id', '=', 'd.responsible_user_id');
            })
            ->where('d.clinic_id', $clinicId)
            ->orderByDesc('d.enrolled_at')
            ->orderBy('d.id')
            ->get(['d.*', 'r.display_name as responsible_name'])
            ->all());
    }

    public static function device(string $clinicId, string $deviceId): ?stdClass
    {
        return DB::table('enrolled_devices as d')
            ->leftJoin('users as r', static function ($join): void {
                $join->on('r.clinic_id', '=', 'd.clinic_id')->on('r.id', '=', 'd.responsible_user_id');
            })
            ->where('d.clinic_id', $clinicId)
            ->where('d.id', $deviceId)
            ->first(['d.*', 'r.display_name as responsible_name']);
    }

    /**
     * Grants d'un poste, du plus récent au plus ancien, avec le nom affiché du profil.
     *
     * @return list<stdClass>
     */
    public static function grants(string $clinicId, string $deviceId): array
    {
        return array_values(DB::table('offline_grants as g')
            ->leftJoin('users as u', static function ($join): void {
                $join->on('u.clinic_id', '=', 'g.clinic_id')->on('u.id', '=', 'g.user_id');
            })
            ->where('g.clinic_id', $clinicId)
            ->where('g.device_id', $deviceId)
            ->orderByDesc('g.issued_at')
            ->orderByDesc('g.id')
            ->get(['g.*', 'u.display_name as user_name'])
            ->all());
    }

    public static function grant(string $clinicId, string $grantId): ?stdClass
    {
        return DB::table('offline_grants as g')
            ->leftJoin('users as u', static function ($join): void {
                $join->on('u.clinic_id', '=', 'g.clinic_id')->on('u.id', '=', 'g.user_id');
            })
            ->where('g.clinic_id', $clinicId)
            ->where('g.id', $grantId)
            ->first(['g.*', 'u.display_name as user_name']);
    }

    /**
     * Instantané typé d'une ligne `offline_grants` : le contexte d'autorisation historique d'une commande de poste
     * (permissions figées, validité, révocation éventuelle).
     */
    public static function grantSnapshot(stdClass $grant): GrantSnapshot
    {
        return GrantSnapshot::fromRow((object) [
            'id' => (string) $grant->id,
            'device_id' => (string) $grant->device_id,
            'user_id' => (string) $grant->user_id,
            'permissions_snapshot' => $grant->permissions_snapshot,
            'rules_version' => (string) $grant->rules_version,
            'catalog_version' => (string) $grant->catalog_version,
            'issued_at' => (string) $grant->issued_at,
            'valid_until' => (string) $grant->valid_until,
            'revoked_at' => $grant->revoked_at === null ? null : (string) $grant->revoked_at,
        ]);
    }

    /** État d'un grant à l'instant donné : ACTIVE, EXPIRED ou REVOKED. */
    public static function grantState(stdClass $grant, CarbonImmutable $now): string
    {
        if ($grant->revoked_at !== null) {
            return 'REVOKED';
        }

        return CarbonImmutable::parse((string) $grant->valid_until, 'UTC')->lessThanOrEqualTo($now) ? 'EXPIRED' : 'ACTIVE';
    }
}
