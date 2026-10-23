<?php

namespace App\Offline;

use App\Domain\Shared\BusinessError;
use Firebase\JWT\JWT;
use LogicException;
use Throwable;

/**
 * Curseur OPAQUE et SIGNÉ du pull (fichier 19 ; contrat 23 : « une page a une borne supérieure et un curseur
 * signés/opaques »). Il lie la borne supérieure balayée au cabinet, au poste, au compte ET aux compartiments lus : un
 * curseur d'un autre poste ou d'un autre profil est refusé ; un compartiment ouvert depuis (droit clinique accordé)
 * relance la lecture depuis le début, pour qu'aucun changement d'abord masqué ne soit sauté.
 *
 *   base64url(JCS {after, clinic_id, compartments, device_id, user_id, v: 1}) "." base64url(HMAC-SHA256)
 *
 * La clé HMAC est dérivée de la clé d'application (`APP_KEY`), jamais transmise ni stockée en base.
 */
final class PullCursor
{
    /**
     * @param  list<string>  $compartments
     */
    public static function sign(string $clinicId, string $deviceId, string $userId, array $compartments, int $after): string
    {
        $body = JWT::urlsafeB64Encode(Jcs::encode([
            'after' => (string) max(0, $after),
            'clinic_id' => $clinicId,
            'compartments' => $compartments,
            'device_id' => $deviceId,
            'user_id' => $userId,
            'v' => 1,
        ]));

        return $body.'.'.JWT::urlsafeB64Encode(hash_hmac('sha256', $body, self::key(), true));
    }

    /**
     * Borne du curseur reçu ; 0 si les compartiments du profil ont changé depuis.
     *
     * @param  list<string>  $compartments
     */
    public static function after(string $cursor, string $clinicId, string $deviceId, string $userId, array $compartments): int
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw self::invalid();
        }
        try {
            $mac = JWT::urlsafeB64Decode($parts[1]);
            $claims = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw self::invalid();
        }
        if (! hash_equals(hash_hmac('sha256', $parts[0], self::key(), true), $mac) || ! is_array($claims)) {
            throw self::invalid();
        }
        if (($claims['v'] ?? null) !== 1 || ($claims['clinic_id'] ?? null) !== $clinicId || ($claims['device_id'] ?? null) !== $deviceId || ($claims['user_id'] ?? null) !== $userId) {
            throw self::invalid();
        }
        $after = $claims['after'] ?? null;
        if (! is_string($after) || preg_match('/^(0|[1-9][0-9]{0,17})\z/', $after) !== 1) {
            throw self::invalid();
        }

        return ($claims['compartments'] ?? null) === $compartments ? (int) $after : 0;
    }

    private static function key(): string
    {
        $key = config('app.key');
        $key = is_string($key) ? $key : '';
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = is_string($decoded) ? $decoded : '';
        }
        if ($key === '') {
            throw new LogicException('APP_KEY absente : le curseur de synchronisation ne peut pas être signé.');
        }

        return hash_hmac('sha256', 'eeo-pull-cursor-v1', $key, true);
    }

    private static function invalid(): BusinessError
    {
        return BusinessError::validation(['cursor' => ['Curseur de synchronisation invalide, altéré ou d\'un autre poste : reprenez la lecture sans curseur.']]);
    }
}
