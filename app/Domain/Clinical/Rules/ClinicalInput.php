<?php

namespace App\Domain\Clinical\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;

/**
 * Saisies élémentaires d'une rubrique clinique : texte libre borné, texte d'une ligne, oui/non/non renseigné.
 *
 * - Un texte garde son Unicode d'origine, seuls les blancs de bord sont retirés ; un texte vide vaut null
 *   (« non renseigné »), jamais une chaîne vide.
 * - 4000 caractères au plus (colonne TEXT bornée par le contrat) ; aucun caractère de contrôle, sauf les
 *   retours à la ligne et la tabulation d'un texte libre (le texte d'un « Autre » tient sur une ligne).
 * - Oui/non/non renseigné : true, false ou null ; jamais « faux » par défaut.
 */
final class ClinicalInput
{
    public const TEXT_MAX = 4000;

    /**
     * Texte libre (plaintes, commentaires, conclusion) : plusieurs lignes admises.
     */
    public static function text(mixed $value, string $path, FieldErrors $errors): ?string
    {
        return self::normalize($value, $path, $errors, true);
    }

    /**
     * Texte d'une ligne (précision d'un « Autre »).
     */
    public static function line(mixed $value, string $path, FieldErrors $errors): ?string
    {
        return self::normalize($value, $path, $errors, false);
    }

    public static function triState(mixed $value, string $path, FieldErrors $errors): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        $errors->add($path, 'Répondez oui, non, ou laissez non renseigné.');

        return null;
    }

    private static function normalize(mixed $value, string $path, FieldErrors $errors, bool $multiline): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $errors->add($path, 'Un texte est attendu.');

            return null;
        }
        try {
            $text = Text::trim($value);
        } catch (RuleViolation $violation) {
            $errors->add($path, $violation->getMessage());

            return null;
        }
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > self::TEXT_MAX) {
            $errors->add($path, 'Ce texte dépasse '.self::TEXT_MAX.' caractères.');

            return null;
        }
        if (preg_match($multiline ? '/[^\P{Cc}\n\r\t]/u' : '/\p{Cc}/u', $text) === 1) {
            $errors->add($path, 'Ce champ ne peut pas contenir de caractère de contrôle.');

            return null;
        }

        return $text;
    }
}
