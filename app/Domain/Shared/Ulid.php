<?php

namespace App\Domain\Shared;

use Illuminate\Support\Str;

/**
 * Identifiants ULID ASCII (26 caractères Crockford, majuscules) : connus avant tout commit, identiques
 * hors-ligne et sur le serveur (contrat 23, « Valeurs et sérialisation »).
 */
final class Ulid
{
    private const PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}\z/';

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    public static function isValid(string $value): bool
    {
        // La baseline refuse l'identifiant nul (CHECK id <> '000…').
        return preg_match(self::PATTERN, $value) === 1 && $value !== str_repeat('0', 26);
    }
}
