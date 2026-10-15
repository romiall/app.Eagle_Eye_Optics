<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\RuleViolation;
use Normalizer;

/**
 * Règles de texte communes PHP/TypeScript (F08, vecteurs `contracts/vectors/text.json`).
 *
 * - Le blanc est une liste FERMÉE de caractères (espace, tabulation, retours, espace insécable U+00A0 et espace
 *   fine insécable U+202F), identique dans les deux moteurs : `\s` n'a pas le même sens en PCRE et en JavaScript.
 * - Une valeur saisie est conservée telle quelle (Unicode d'origine), seuls les blancs de bord sont retirés ;
 *   un champ vide vaut null, jamais une chaîne vide ni « 0 ».
 * - La clé de recherche est une valeur SÉPARÉE (`patient_records.name_search`) : minuscules, sans accents ni
 *   ponctuation, blancs réduits. Elle ne remplace jamais le nom saisi.
 */
final class Text
{
    /** Classe de blancs admis, en syntaxe PCRE (le TypeScript déclare la même liste). */
    public const BLANK_CLASS = '[\x{0020}\x{0009}\x{000A}\x{000D}\x{00A0}\x{202F}]';

    public static function trim(string $value): string
    {
        $trimmed = preg_replace('/^'.self::BLANK_CLASS.'+|'.self::BLANK_CLASS.'+$/u', '', $value);
        if ($trimmed === null) {
            throw RuleViolation::invalid('text', 'text.encoding', 'Le texte n\'est pas un UTF-8 valide.');
        }

        return $trimmed;
    }

    /**
     * Champ texte facultatif : blancs de bord retirés, vide → null, longueur en caractères bornée par la colonne.
     */
    public static function optional(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = self::trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed, 'UTF-8') > $maxLength) {
            throw RuleViolation::invalid($field, 'text.too_long', "Ce texte dépasse {$maxLength} caractères.", ['max_length' => $maxLength]);
        }

        return $trimmed;
    }

    /**
     * Clé de recherche insensible à la casse, aux accents et à la ponctuation : « N'Guessan-Kouamé » et
     * « n guessan KOUAME » ont la même clé « n guessan kouame ». Ordre fixé : minuscules, décomposition
     * canonique (NFD), retrait des marques combinantes, puis tout séparateur, ponctuation, symbole ou caractère
     * de contrôle devient une espace unique.
     */
    public static function searchKey(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw RuleViolation::invalid('text', 'text.encoding', 'Le texte n\'est pas un UTF-8 valide.');
        }
        $lower = mb_strtolower($value, 'UTF-8');
        $decomposed = Normalizer::normalize($lower, Normalizer::FORM_D);
        if ($decomposed === false) {
            throw RuleViolation::invalid('text', 'text.encoding', 'Le texte n\'est pas un UTF-8 valide.');
        }
        // Marques combinantes (accents) et caractères de format invisibles (trait d'union conditionnel U+00AD,
        // espace sans chasse U+200B, U+FEFF…) disparaissent : un prénom écrit avec un trait d'union conditionnel garde la clé « marie ».
        $withoutMarks = (string) preg_replace('/[\p{Mn}\p{Cf}]+/u', '', $decomposed);
        $spaced = (string) preg_replace('/[\p{Z}\p{P}\p{S}\p{Cc}]+/u', ' ', $withoutMarks);

        return trim($spaced, ' ');
    }

    /**
     * Téléphone normalisé pour la recherche (`phone_*_normalized`) : chiffres seulement, précédés de « + » si la
     * saisie commence par « + » ou « 00 ». Aucun indicatif n'est déduit : la recherche compare les chiffres, un
     * numéro national et le même numéro avec indicatif restent tous deux retrouvables. Vide → null.
     */
    public static function phone(?string $value, string $field): ?string
    {
        $trimmed = $value === null ? '' : self::trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^\+?[0-9 .\-\/()\x{00A0}\x{202F}]+\z/u', $trimmed) !== 1) {
            throw RuleViolation::invalid($field, 'phone.format', 'Numéro de téléphone invalide : chiffres, espaces, points, tirets ou parenthèses seulement.');
        }
        $international = str_starts_with($trimmed, '+');
        $digits = (string) preg_replace('/[^0-9]/', '', $trimmed);
        if (! $international && str_starts_with($digits, '00')) {
            $international = true;
            $digits = substr($digits, 2);
        }
        if (strlen($digits) < 3 || strlen($digits) > 31) {
            throw RuleViolation::invalid($field, 'phone.length', 'Un numéro de téléphone compte de 3 à 31 chiffres.');
        }

        return ($international ? '+' : '').$digits;
    }
}
