<?php

namespace App\Offline;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * JWS compact ES256 dont l'en-tête protégé ET la charge sont la sérialisation JCS de leur objet (fichier 23 :
 * « signature est un JWS compact ES256… le protected header impose alg=ES256, typ et kid connus ; refuser alg=none et
 * toute substitution de clé fournie dans le payload »). Signature JWA R||S de 64 octets ; les conversions DER
 * appartiennent à la bibliothèque standard `firebase/php-jwt` (jamais une conversion maison).
 *
 * Vérification stricte : trois segments base64url, en-tête exactement {alg, kid, typ} avec `alg = ES256`, `typ`
 * attendu, `kid` attendu ; aucune clé n'est jamais lue dans le jeton (pas de `jwk`, `jku`, `x5c`) ; la charge doit
 * être un objet JSON dont les octets sont EXACTEMENT sa forme JCS (une charge réécrite autrement est refusée, même si
 * sa signature est valide pour d'autres octets).
 */
final class CompactJws
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, string $privatePem, string $kid, string $type): string
    {
        $input = JWT::urlsafeB64Encode(Jcs::encode(['alg' => 'ES256', 'kid' => $kid, 'typ' => $type]))
            .'.'.JWT::urlsafeB64Encode(Jcs::encode($payload));

        return $input.'.'.JWT::urlsafeB64Encode(JWT::sign($input, $privatePem, 'ES256'));
    }

    /**
     * Vérifie le jeton avec la clé publique attendue et rend la charge.
     *
     * @param  array{kty: string, crv: string, x: string, y: string}  $publicJwk
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException jeton refusé (message sans donnée du jeton)
     */
    public static function verify(string $jws, array $publicJwk, string $expectedKid, string $expectedType): array
    {
        $segments = explode('.', $jws);
        if (count($segments) !== 3 || in_array('', $segments, true)) {
            throw new UnexpectedValueException('JWS compact attendu (trois segments).');
        }
        [$headerSegment, $payloadSegment] = $segments;

        $header = self::decodeObject($headerSegment, 'en-tête');
        if (array_keys($header) !== ['alg', 'kid', 'typ'] && ! self::sameKeys($header, ['alg', 'kid', 'typ'])) {
            throw new UnexpectedValueException('En-tête protégé inattendu : seuls alg, kid et typ sont admis.');
        }
        if (($header['alg'] ?? null) !== 'ES256') {
            throw new UnexpectedValueException('Algorithme refusé : ES256 exigé.');
        }
        if (($header['typ'] ?? null) !== $expectedType) {
            throw new UnexpectedValueException('Type de jeton inattendu.');
        }
        if (($header['kid'] ?? null) !== $expectedKid) {
            throw new UnexpectedValueException('Clé de signature inconnue pour ce jeton.');
        }
        if (JWT::urlsafeB64Decode($headerSegment) !== Jcs::encode($header)) {
            throw new UnexpectedValueException('En-tête non canonique.');
        }

        $payload = self::decodeObject($payloadSegment, 'charge');
        if (JWT::urlsafeB64Decode($payloadSegment) !== Jcs::encode($payload)) {
            throw new UnexpectedValueException('Charge non canonique : JCS exigé.');
        }

        try {
            $key = JWK::parseKey(['kty' => $publicJwk['kty'], 'crv' => $publicJwk['crv'], 'x' => $publicJwk['x'], 'y' => $publicJwk['y'], 'alg' => 'ES256']);
            if ($key === null) {
                throw new UnexpectedValueException('Clé publique inutilisable.');
            }
            JWT::decode($jws, $key);
        } catch (UnexpectedValueException $refused) {
            throw new UnexpectedValueException('Signature refusée.', 0, $refused);
        } catch (Throwable $refused) {
            throw new UnexpectedValueException('Signature refusée.', 0, $refused);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $segment, string $what): array
    {
        $raw = JWT::urlsafeB64Decode($segment);
        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new UnexpectedValueException('JSON invalide dans '.$what.'.');
        }
        if (! $decoded instanceof stdClass) {
            throw new UnexpectedValueException('Objet JSON attendu dans '.$what.'.');
        }

        /** @var array<string, mixed> $array */
        $array = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        return $array;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<string>  $keys
     */
    private static function sameKeys(array $object, array $keys): bool
    {
        $actual = array_keys($object);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
