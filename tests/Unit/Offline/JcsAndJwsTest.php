<?php

namespace Tests\Unit\Offline;

use App\Offline\CompactJws;
use App\Offline\Jcs;
use App\Offline\ServerSigningKey;
use Firebase\JWT\JWT;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * JCS (RFC 8785) et JWS compact ES256 des contenus signés du hors-ligne (O02), contre les vecteurs COMMUNS
 * `contracts/vectors/signatures/` que le navigateur rejoue aussi : même forme canonique, même empreinte, et un jeton
 * produit par Web Crypto vérifié par PHP. Refus : alg=none, autre algorithme, clé substituée, en-tête étendu, charge
 * altérée ou non canonique, signature tronquée.
 */
final class JcsAndJwsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function vectors(string $file): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3).'/contracts/vectors/signatures/'.$file);
        $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function jcsCases(): iterable
    {
        foreach (self::vectors('jcs.json')['cases'] as $case) {
            yield (string) $case['name'] => [(string) $case['input'], (string) $case['canonical'], (string) $case['sha256']];
        }
    }

    #[DataProvider('jcsCases')]
    public function test_la_forme_canonique_est_celle_des_vecteurs_communs(string $input, string $canonical, string $sha256): void
    {
        // stdClass : un objet vide reste « {} » et ne devient pas une liste.
        $value = json_decode($input, false, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($canonical, Jcs::encode($value));
        $this->assertSame($sha256, Jcs::sha256($value));
    }

    public function test_un_nombre_sans_forme_admise_est_refuse(): void
    {
        foreach (self::vectors('jcs.json')['refused'] as $case) {
            // 1.5 est lu en flottant, 9007199254740992 en entier PHP hors de la plage sûre : les deux sont refusés.
            $value = json_decode((string) $case['input'], false, 512, JSON_THROW_ON_ERROR);
            $refused = false;
            try {
                Jcs::encode($value);
            } catch (InvalidArgumentException) {
                $refused = true;
            }
            $this->assertTrue($refused, (string) $case['name']);
        }
    }

    public function test_un_texte_utf8_invalide_n_a_pas_de_forme_canonique(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Jcs::encode(['x' => "\xC3\x28"]);
    }

    public function test_un_jeton_produit_par_web_crypto_est_verifie_par_php(): void
    {
        $vector = self::vectors('grant-es256.json');
        $jwk = $vector['public_jwk'];

        $claims = CompactJws::verify((string) $vector['jws'], $jwk, (string) $vector['kid'], 'eeo-offline-grant+jws');

        $this->assertSame($vector['claims'], $claims);
        $this->assertSame((string) $vector['kid'], ServerSigningKey::thumbprint($jwk));
    }

    public function test_php_signe_un_jeton_que_sa_propre_verification_accepte_et_qui_a_la_forme_du_contrat(): void
    {
        $vector = self::vectors('grant-es256.json');
        $pem = (string) $vector['private_pkcs8_pem_test_only'];

        $jws = CompactJws::sign($vector['claims'], $pem, (string) $vector['kid'], 'eeo-offline-grant+jws');
        [$header, $payload, $signature] = explode('.', $jws);

        $this->assertSame('{"alg":"ES256","kid":"'.$vector['kid'].'","typ":"eeo-offline-grant+jws"}', JWT::urlsafeB64Decode($header));
        $this->assertSame(Jcs::encode($vector['claims']), JWT::urlsafeB64Decode($payload));
        $this->assertSame(64, strlen(JWT::urlsafeB64Decode($signature)), 'Signature JWA R||S de 64 octets');
        $this->assertSame($vector['claims'], CompactJws::verify($jws, $vector['public_jwk'], (string) $vector['kid'], 'eeo-offline-grant+jws'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tamperings(): iterable
    {
        yield 'alg=none' => ['none'];
        yield 'autre algorithme' => ['hs256'];
        yield 'en-tête avec une clé embarquée' => ['jwk'];
        yield 'charge altérée' => ['payload'];
        yield 'charge non canonique' => ['noncanonical'];
        yield 'signature tronquée' => ['signature'];
        yield 'type inattendu' => ['typ'];
        yield 'kid inconnu' => ['kid'];
        yield 'clé substituée' => ['key'];
        yield 'segments manquants' => ['segments'];
    }

    #[DataProvider('tamperings')]
    public function test_un_jeton_altere_est_refuse(string $case): void
    {
        $vector = self::vectors('grant-es256.json');
        $jwk = $vector['public_jwk'];
        $kid = (string) $vector['kid'];
        [$header, $payload, $signature] = explode('.', (string) $vector['jws']);
        $type = 'eeo-offline-grant+jws';

        $jws = match ($case) {
            'none' => JWT::urlsafeB64Encode('{"alg":"none","kid":"'.$kid.'","typ":"'.$type.'"}').'.'.$payload.'.',
            'hs256' => JWT::urlsafeB64Encode('{"alg":"HS256","kid":"'.$kid.'","typ":"'.$type.'"}').'.'.$payload.'.'.$signature,
            'jwk' => JWT::urlsafeB64Encode(Jcs::encode(['alg' => 'ES256', 'jwk' => $jwk, 'kid' => $kid, 'typ' => $type])).'.'.$payload.'.'.$signature,
            'payload' => $header.'.'.JWT::urlsafeB64Encode(Jcs::encode([...$vector['claims'], 'valid_until' => '2099-01-01T00:00:00.000Z'])).'.'.$signature,
            'noncanonical' => $header.'.'.JWT::urlsafeB64Encode((string) json_encode($vector['claims'], JSON_PRETTY_PRINT)).'.'.$signature,
            'signature' => $header.'.'.$payload.'.'.substr($signature, 0, 40),
            'typ', 'kid', 'key' => (string) $vector['jws'],
            'segments' => $header.'.'.$payload,
        };
        $expectedType = $case === 'typ' ? 'eeo-device-proof+jws' : $type;
        $expectedKid = $case === 'kid' ? str_repeat('A', 43) : $kid;
        $key = $jwk;
        if ($case === 'key') {
            $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $other);
            $ec = openssl_pkey_get_details($other)['ec'] ?? [];
            $key = ['kty' => 'EC', 'crv' => 'P-256', 'x' => JWT::urlsafeB64Encode(str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT)), 'y' => JWT::urlsafeB64Encode(str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT))];
        }

        $this->expectException(UnexpectedValueException::class);
        CompactJws::verify($jws, $key, $expectedKid, $expectedType);
    }
}
