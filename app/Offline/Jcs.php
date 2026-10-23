<?php

namespace App\Offline;

use InvalidArgumentException;
use stdClass;

/**
 * Sérialisation JSON canonique JCS (RFC 8785) des contenus SIGNÉS du hors-ligne (grants du serveur, preuve de
 * possession d'un poste, puis commandes du journal en O04/O05). Le navigateur applique la même règle
 * (`resources/frontend/offline/signing/jcs.ts`) ; les vecteurs `contracts/vectors/signatures/jcs.json` sont lus par
 * les deux côtés.
 *
 * Domaine de valeurs du contrat (fichier 23, « Valeurs et sérialisation ») : null, booléens, ENTIERS sûrs
 * (|n| ≤ 2^53 − 1, forme décimale sans zéro initial), chaînes UTF-8 valides, listes et objets. Un flottant est
 * refusé : une quantité, un taux ou une mesure voyage en chaîne décimale exacte, et JCS n'a alors aucune
 * sérialisation ECMAScript de nombre à reproduire. Ce sous-ensemble est exactement conforme à RFC 8785 :
 *  - clés d'objet triées par unités de code UTF-16 (comparaison des encodages UTF-16BE) ;
 *  - chaînes : échappement minimal de JSON.stringify (`"` `\` `\b` `\f` `\n` `\r` `\t`, autres contrôles
 *    `\u00xx` en minuscules), aucun autre échappement (Unicode et « / » tels quels) ;
 *  - aucun espace.
 *
 * Un tableau PHP séquentiel (0..n−1) est une liste ; tout autre tableau, ou un `stdClass`, est un objet. Un objet
 * vide s'écrit `{}` seulement s'il est un `stdClass` ; `[]` est une liste vide.
 */
final class Jcs
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
                throw new InvalidArgumentException('Entier hors de la plage sûre de JCS : le transporter en chaîne de chiffres.');
            }

            return (string) $value;
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('Un nombre à virgule n\'a pas de forme admise dans un contenu signé : chaîne décimale exigée.');
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof stdClass) {
            return self::object(get_object_vars($value));
        }
        if (is_array($value)) {
            return array_is_list($value) ? self::list($value) : self::object($value);
        }

        throw new InvalidArgumentException('Type de valeur sans forme JCS : '.get_debug_type($value).'.');
    }

    /** SHA-256 hexadécimal minuscule des octets UTF-8 de JCS(valeur). */
    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function list(array $items): string
    {
        return '['.implode(',', array_map(self::encode(...), $items)).']';
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private static function object(array $members): string
    {
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($members));
        foreach ($keys as $key) {
            self::assertUtf8($key);
        }
        usort($keys, static fn (string $left, string $right): int => strcmp(self::utf16($left), self::utf16($right)));

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::string($key).':'.self::encode($members[$key]);
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function string(string $value): string
    {
        self::assertUtf8($value);
        $out = '"';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $byte = $value[$index];
            $code = ord($byte);
            $out .= match (true) {
                $byte === '"' => '\\"',
                $byte === '\\' => '\\\\',
                $code === 0x08 => '\\b',
                $code === 0x0C => '\\f',
                $code === 0x0A => '\\n',
                $code === 0x0D => '\\r',
                $code === 0x09 => '\\t',
                $code < 0x20 => sprintf('\\u%04x', $code),
                default => $byte,
            };
        }

        return $out.'"';
    }

    private static function utf16(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    private static function assertUtf8(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Texte UTF-8 invalide : aucune forme canonique.');
        }
    }
}
