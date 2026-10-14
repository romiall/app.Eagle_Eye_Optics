<?php

namespace App\Domain\Access;

use DateTimeInterface;

/**
 * Règle pure des permissions effectives (fichiers 09 et 23) :
 *  - compte désactivé : aucune permission ;
 *  - rôles attribués et non révoqués à l'instant donné ;
 *  - dérogations nominatives non expirées : un refus l'emporte sur le rôle, une autorisation s'ajoute ;
 *  - seuls les codes du référentiel `permissions` comptent (les 49 codes seedés font autorité).
 */
final class PermissionResolver
{
    /**
     * @param  list<array{role: string, assigned_at: DateTimeInterface, revoked_at: ?DateTimeInterface}>  $roleAssignments
     * @param  array<string, list<string>>  $rolePermissions
     * @param  list<array{permission: string, allowed: bool, expires_at: ?DateTimeInterface}>  $overrides
     * @param  list<string>  $knownPermissions
     * @return list<string> codes triés, sans doublon
     */
    public static function resolve(
        bool $userActive,
        array $roleAssignments,
        array $rolePermissions,
        array $overrides,
        array $knownPermissions,
        DateTimeInterface $now,
    ): array {
        if (! $userActive) {
            return [];
        }

        $granted = [];
        foreach ($roleAssignments as $assignment) {
            $started = $assignment['assigned_at'] <= $now;
            $notRevoked = $assignment['revoked_at'] === null || $assignment['revoked_at'] > $now;
            if ($started && $notRevoked) {
                foreach ($rolePermissions[$assignment['role']] ?? [] as $permission) {
                    $granted[$permission] = true;
                }
            }
        }

        foreach ($overrides as $override) {
            if ($override['expires_at'] !== null && $override['expires_at'] <= $now) {
                continue;
            }
            if ($override['allowed']) {
                $granted[$override['permission']] = true;
            } else {
                unset($granted[$override['permission']]);
            }
        }

        $codes = array_values(array_intersect(array_keys($granted), $knownPermissions));
        sort($codes);

        return $codes;
    }
}
