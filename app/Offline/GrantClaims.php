<?php

namespace App\Offline;

use Carbon\CarbonImmutable;

/**
 * Charge signée d'un grant hors-ligne (O02 ; fichier 19 : « autorisations serveur signées par profil : cabinet,
 * utilisateur, rôle, appareil, version de règles, validité et opérations admises » ; fichier 23 : « les grants signés
 * lient device_id, user_id et permissions »). Sérialisée en JCS, signée en JWS compact ES256 (`typ`
 * `eeo-offline-grant+jws`, `kid` = empreinte de la clé du serveur). Le poste vérifie la MÊME forme avant d'ouvrir un
 * compartiment : un grant modifié, d'un autre poste, d'un autre compte, d'un autre cabinet, expiré ou signé par une
 * clé non épinglée est refusé.
 *
 *   {"catalog_sha256", "catalog_version", "clinic_id", "compartments": [...], "device_id", "grant_id",
 *    "issued_at", "permissions": [...], "rules_version", "user_id", "v": 1, "valid_until"}
 *
 * Instants UTC à la milliseconde (`…sss Z`) ; permissions triées et sans doublon ; compartiments dans l'ordre du
 * contrat. Immuable : une révocation s'ajoute en base (`revoked_at`), elle ne réécrit jamais le jeton.
 */
final class GrantClaims
{
    public const TYPE = 'eeo-offline-grant+jws';

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    public static function build(
        string $grantId,
        string $clinicId,
        string $deviceId,
        string $userId,
        array $permissions,
        string $rulesVersion,
        string $catalogVersion,
        string $catalogSha256,
        CarbonImmutable $issuedAt,
        CarbonImmutable $validUntil,
    ): array {
        $sorted = array_values(array_unique($permissions));
        sort($sorted, SORT_STRING);

        return [
            'catalog_sha256' => $catalogSha256,
            'catalog_version' => $catalogVersion,
            'clinic_id' => $clinicId,
            'compartments' => Compartments::of($sorted),
            'device_id' => $deviceId,
            'grant_id' => $grantId,
            'issued_at' => self::instant($issuedAt),
            'permissions' => $sorted,
            'rules_version' => $rulesVersion,
            'user_id' => $userId,
            'v' => 1,
            'valid_until' => self::instant($validUntil),
        ];
    }

    public static function instant(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
