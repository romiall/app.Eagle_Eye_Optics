<?php

namespace App\Domain\Access;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attribution et révocation des profils (table user_roles : une ligne par compte et profil, révocation
 * datée conservée ; la ré-attribution réactive la ligne et l'historique reste dans l'audit).
 */
final class StaffRoles
{
    public const MANAGER = 'MANAGER';

    /**
     * @return list<string>
     */
    public static function known(): array
    {
        return array_values(DB::table('roles')->orderBy('code')->pluck('code')->map(static fn (mixed $c): string => (string) $c)->all());
    }

    /**
     * @return list<string>
     */
    public static function activeRoles(string $clinicId, string $userId): array
    {
        return array_values(DB::table('user_roles')
            ->where('clinic_id', $clinicId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->orderBy('role_code')
            ->pluck('role_code')
            ->map(static fn (mixed $c): string => (string) $c)
            ->all());
    }

    /**
     * Applique l'ensemble de profils voulu. Renvoie [ajoutés, révoqués].
     *
     * @param  list<string>  $wanted
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function sync(string $clinicId, string $userId, array $wanted, string $assignedBy): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $current = self::activeRoles($clinicId, $userId);
        $added = array_values(array_diff($wanted, $current));
        $revoked = array_values(array_diff($current, $wanted));

        foreach ($revoked as $role) {
            DB::table('user_roles')
                ->where('clinic_id', $clinicId)->where('user_id', $userId)->where('role_code', $role)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
        }

        foreach ($added as $role) {
            $reactivated = DB::table('user_roles')
                ->where('clinic_id', $clinicId)->where('user_id', $userId)->where('role_code', $role)
                ->update(['revoked_at' => null, 'assigned_at' => $now, 'assigned_by' => $assignedBy, 'updated_at' => $now]);

            if ($reactivated === 0) {
                DB::table('user_roles')->insert([
                    'id' => Ulid::generate(),
                    'clinic_id' => $clinicId,
                    'user_id' => $userId,
                    'role_code' => $role,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        sort($added);
        sort($revoked);

        return [$added, $revoked];
    }

    /**
     * Refuse une opération qui laisserait le cabinet sans aucune gérante active capable de gérer les
     * comptes : profil Gérante actif, compte actif et aucune dérogation DENY en vigueur sur users.manage
     * (une telle dérogation ne peut plus être posée, mais une ligne ancienne ne doit pas fausser
     * l'invariant). Verrou du cabinet tenu par l'appelant : deux retraits simultanés ne peuvent pas passer
     * chacun ce contrôle.
     */
    public static function assertAnotherActiveManager(string $clinicId, string $exceptUserId): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $others = DB::table('user_roles')
            ->join('users', static function ($join): void {
                $join->on('users.clinic_id', '=', 'user_roles.clinic_id')->on('users.id', '=', 'user_roles.user_id');
            })
            ->where('user_roles.clinic_id', $clinicId)
            ->where('user_roles.role_code', self::MANAGER)
            ->whereNull('user_roles.revoked_at')
            ->where('users.active', true)
            ->where('users.id', '<>', $exceptUserId)
            ->whereNotExists(static function ($query) use ($now): void {
                $query->selectRaw('1')
                    ->from('user_permissions')
                    ->whereColumn('user_permissions.clinic_id', 'users.clinic_id')
                    ->whereColumn('user_permissions.user_id', 'users.id')
                    ->where('user_permissions.permission_code', StaffAdministration::MANAGE_PERMISSION)
                    ->where('user_permissions.allowed', false)
                    ->where(static function ($expiry) use ($now): void {
                        $expiry->whereNull('user_permissions.expires_at')->orWhere('user_permissions.expires_at', '>', $now);
                    });
            })
            ->count();

        if ($others === 0) {
            throw new BusinessError('LAST_MANAGER_REQUIRED', 'Le cabinet doit conserver au moins une gérante active.', 409);
        }
    }

    /**
     * Verrou de sérialisation de l'administration des comptes : la ligne du cabinet, premier verrou de
     * l'ordre défini au contrat 23.
     */
    public static function lockClinic(string $clinicId): void
    {
        DB::table('clinics')->where('id', $clinicId)->lockForUpdate()->first();
    }
}
