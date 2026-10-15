<?php

namespace App\Domain\Shared;

use stdClass;

/**
 * JSON déterministe pour les empreintes INTERNES du serveur (audit, idempotence) : clés d'objet triées
 * récursivement par octets (ordre total : `SORT_STRING`, jamais la comparaison mixte nombre/chaîne de PHP,
 * qui n'est pas transitive), Unicode et barres obliques non échappés. Un objet `stdClass` vide reste `{}`.
 *
 * Limite assumée : un tableau PHP ne distingue pas `{}` de `[]` ni `{"0":"a","1":"b"}` de `["a","b"]`. Une
 * charge de commande est validée contre son schéma AVANT l'empreinte, et le schéma fixe le type de chaque
 * champ : deux charges valides ne peuvent pas différer seulement par cette forme.
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
            if ($value === []) {
                return new stdClass;
            }
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
