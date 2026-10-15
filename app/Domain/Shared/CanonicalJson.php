<?php

namespace App\Domain\Shared;

use stdClass;

/**
 * JSON déterministe pour les empreintes INTERNES du serveur (audit, idempotence) : clés d'objet triées
 * récursivement, Unicode et barres obliques non échappés.
 *
 * Ce n'est pas la canonicalisation JCS (RFC 8785) des commandes signées du poste (tâche O02, avec ses
 * vecteurs croisés PHP/navigateur) : elle ne sert qu'à reconnaître le même contenu côté serveur, jamais à
 * vérifier une signature.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** SHA-256 hexadécimal minuscule (64 caractères) du JSON canonique. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
