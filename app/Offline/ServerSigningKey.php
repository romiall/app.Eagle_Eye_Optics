<?php

namespace App\Offline;

use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Clé ES256 (ECDSA P-256, SHA-256) avec laquelle le serveur SIGNE les grants hors-ligne (O02 ; fichier 19,
 * « Préparation du poste » ; fichier 23, « Signature et chiffrement »). La clé privée vit dans un fichier PEM privé
 * du serveur (`eeo.offline.signing_key_path`, hors de la racine web, jamais dans MySQL ni dans le dépôt) ; elle est
 * créée par `php artisan eeo:offline:signing-key` et jamais générée en silence pendant une requête.
 *
 * Identifiant `kid` : empreinte RFC 7638 de la clé publique (base64url du SHA-256 de son JWK canonique
 * `{"crv","kty","x","y"}`). Une rotation garde les clés publiques retirées (`retired/<kid>.json`) : un grant déjà émis
 * reste vérifiable par le poste qui les a épinglées ; un nouveau grant est toujours signé par la clé courante.
 */
final class ServerSigningKey
{
    public function path(): string
    {
        $configured = config('eeo.offline.signing_key_path');

        return is_string($configured) && $configured !== '' ? $configured : storage_path('app/private/offline/grant-signing.pem');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** PEM de la clé privée (signature) ; refus explicite si la clé n'a pas été créée. */
    public function privatePem(): string
    {
        if (! $this->exists()) {
            throw new RuntimeException('Clé de signature des grants absente : lancer « php artisan eeo:offline:signing-key ».');
        }
        $pem = file_get_contents($this->path());
        if (! is_string($pem) || $pem === '') {
            throw new RuntimeException('Clé de signature des grants illisible.');
        }

        return $pem;
    }

    /**
     * Clé publique courante au format JWK (jamais de composante « d »).
     *
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    public function publicJwk(): array
    {
        $key = openssl_pkey_get_private($this->privatePem());
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Clé de signature des grants invalide.');
        }

        return self::jwkOf($key);
    }

    public function kid(): string
    {
        return self::thumbprint($this->publicJwk());
    }

    /**
     * Clés publiques à épingler par un poste : la courante puis les retirées, sans aucune donnée privée.
     *
     * @return list<array{kid: string, status: string, jwk: array{kty: string, crv: string, x: string, y: string}}>
     */
    public function keySet(): array
    {
        $current = $this->publicJwk();
        $keys = [['kid' => self::thumbprint($current), 'status' => 'CURRENT', 'jwk' => $current]];
        foreach (glob($this->retiredDirectory().DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            $jwk = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($jwk) && is_string($jwk['x'] ?? null) && is_string($jwk['y'] ?? null)) {
                $public = ['kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y']];
                $keys[] = ['kid' => self::thumbprint($public), 'status' => 'RETIRED', 'jwk' => $public];
            }
        }

        return $keys;
    }

    /**
     * Crée la clé (première installation) ou la remplace (rotation : l'ancienne clé publique est conservée pour la
     * vérification des grants déjà émis). Refuse d'écraser sans rotation explicite.
     *
     * @return array{kid: string, rotated_from: ?string}
     */
    public function generate(bool $rotate = false): array
    {
        $previous = null;
        if ($this->exists()) {
            if (! $rotate) {
                throw new RuntimeException('Une clé de signature existe déjà : utiliser --rotate pour la remplacer.');
            }
            $old = $this->publicJwk();
            $previous = self::thumbprint($old);
            $this->ensureDirectory($this->retiredDirectory());
            file_put_contents($this->retiredDirectory().DIRECTORY_SEPARATOR.$previous.'.json', json_encode($old, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (! $key instanceof OpenSSLAsymmetricKey || ! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            throw new RuntimeException('OpenSSL n\'a pas pu créer la clé P-256.');
        }
        $this->ensureDirectory(dirname($this->path()));
        $temporary = $this->path().'.tmp';
        file_put_contents($temporary, $pem);
        @chmod($temporary, 0600);
        rename($temporary, $this->path());

        return ['kid' => self::thumbprint(self::jwkOf($key)), 'rotated_from' => $previous];
    }

    /**
     * Empreinte RFC 7638 d'une clé publique EC (membres obligatoires dans l'ordre lexicographique, sans espace).
     *
     * @param  array{kty: string, crv: string, x: string, y: string}  $jwk
     */
    public static function thumbprint(array $jwk): string
    {
        $canonical = Jcs::encode(['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']]);

        return JWT::urlsafeB64Encode(hash('sha256', $canonical, true));
    }

    /**
     * Clé publique EC P-256 enrôlée d'un poste (`enrolled_devices.public_key_jwk`, forme JCS) ; null si elle est illisible.
     *
     * @return array{kty: string, crv: string, x: string, y: string}|null
     */
    public static function enrolledJwk(string $stored): ?array
    {
        $decoded = json_decode($stored, true);
        if (! is_array($decoded)) {
            return null;
        }
        $kty = $decoded['kty'] ?? null;
        $crv = $decoded['crv'] ?? null;
        $x = $decoded['x'] ?? null;
        $y = $decoded['y'] ?? null;
        if (! is_string($kty) || ! is_string($crv) || ! is_string($x) || ! is_string($y) || $x === '' || $y === '') {
            return null;
        }

        return ['kty' => $kty, 'crv' => $crv, 'x' => $x, 'y' => $y];
    }

    /**
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    private static function jwkOf(OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        $ec = is_array($details) ? ($details['ec'] ?? null) : null;
        if (! is_array($ec) || ($ec['curve_name'] ?? null) !== 'prime256v1' || ! is_string($ec['x'] ?? null) || ! is_string($ec['y'] ?? null)) {
            throw new RuntimeException('La clé de signature doit être une clé EC P-256.');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => JWT::urlsafeB64Encode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => JWT::urlsafeB64Encode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    private function retiredDirectory(): string
    {
        return dirname($this->path()).DIRECTORY_SEPARATOR.'retired';
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Dossier privé de la clé de signature impossible à créer.');
        }
    }
}
