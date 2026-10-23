<?php

namespace App\Offline;

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use UnexpectedValueException;

/**
 * Preuve de possession de la clé privée d'un poste à l'enrôlement (O02 : « vérifier signatures croisées »). Le poste
 * génère sa paire ES256 dans le coffre, puis signe avec sa clé PRIVÉE un JWS compact (`typ` = `eeo-device-proof+jws`,
 * `kid` = empreinte RFC 7638 de sa clé publique) dont la charge JCS est :
 *
 *   {"clinic_id": …, "device_code": …, "issued_at": "AAAA-MM-JJTHH:MM:SS.mmmZ", "jwk_thumbprint": …,
 *    "purpose": "enroll", "v": 1}
 *
 * Le serveur la vérifie avec la clé PUBLIQUE envoyée : signature, empreinte égale à la clé envoyée, cabinet de la
 * session, code du poste du corps, fraîcheur (`eeo.offline.proof_max_age_seconds`, 10 minutes par défaut, dans les
 * deux sens). Une clé publique envoyée sans la preuve correspondante n'est jamais enrôlée.
 */
final class DeviceProof
{
    public const TYPE = 'eeo-device-proof+jws';

    /**
     * @param  array{kty: string, crv: string, x: string, y: string}  $publicJwk
     *
     * @throws UnexpectedValueException preuve refusée
     */
    public static function verify(string $proof, array $publicJwk, string $clinicId, string $deviceCode, CarbonImmutable $now): void
    {
        foreach (['x', 'y'] as $coordinate) {
            if (strlen(JWT::urlsafeB64Decode($publicJwk[$coordinate])) !== 32) {
                throw new UnexpectedValueException('Clé publique P-256 invalide.');
            }
        }
        $thumbprint = ServerSigningKey::thumbprint($publicJwk);
        $claims = CompactJws::verify($proof, $publicJwk, $thumbprint, self::TYPE);

        $expected = ['clinic_id', 'device_code', 'issued_at', 'jwk_thumbprint', 'purpose', 'v'];
        $keys = array_keys($claims);
        sort($keys);
        if ($keys !== $expected) {
            throw new UnexpectedValueException('Preuve de possession incomplète.');
        }
        if ($claims['v'] !== 1 || $claims['purpose'] !== 'enroll') {
            throw new UnexpectedValueException('Preuve de possession d\'un autre usage.');
        }
        if ($claims['jwk_thumbprint'] !== $thumbprint || $claims['clinic_id'] !== $clinicId || $claims['device_code'] !== $deviceCode) {
            throw new UnexpectedValueException('Preuve de possession d\'un autre poste ou d\'un autre cabinet.');
        }
        $issuedAt = is_string($claims['issued_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $claims['issued_at']) === 1
            ? CarbonImmutable::parse($claims['issued_at'], 'UTC')
            : null;
        $maxAge = max(60, (int) config('eeo.offline.proof_max_age_seconds', 600));
        if ($issuedAt === null || abs($now->getTimestamp() - $issuedAt->getTimestamp()) > $maxAge) {
            throw new UnexpectedValueException('Preuve de possession périmée ou datée dans le futur : horloge du poste à vérifier.');
        }
    }
}
