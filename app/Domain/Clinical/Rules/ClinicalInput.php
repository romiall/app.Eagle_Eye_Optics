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
 *
 * Écart connu (documenté dans docs/implementation/clinical.md, non corrigé ici) : le middleware Laravel
 * `TrimStrings` / `ConvertEmptyStringsToNull` rogne et vide les chaînes AVANT cette validation, avec un jeu de
 * blancs plus large que la liste fermée de `Text::trim`. Un futur client hors-ligne qui applique la liste fermée
 * pourrait donc conserver un caractère que le serveur retire. À trancher avant la synchronisation clinique (O05).
 */
final class ClinicalInput
{
    public const TEXT_MAX = 4000;

    /**
     * Texte libre (plaintes, commentaires, conclusion) : plusieurs lignes admises.
     */
    public static function text(mixed $value, string $path, FieldErrors $errors, int $max = self::TEXT_MAX): ?string
    {
        return self::normalize($value, $path, $errors, true, $max);
    }

    /**
     * Texte d'une ligne (précision d'un « Autre »).
     */
    public static function line(mixed $value, string $path, FieldErrors $errors, int $max = self::TEXT_MAX): ?string
    {
        return self::normalize($value, $path, $errors, false, $max);
    }

    /**
     * Vrai si la valeur brute est absente ou ne contient que des blancs (liste fermée de `Text`) : un champ « non
     * renseigné ». Une valeur non textuelle ou un texte invalide n'est pas « vide » : son erreur est celle de
     * `text()` / `line()`, jamais celle d'un champ manquant.
     */
    public static function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_string($value)) {
            return false;
        }
        try {
            return Text::trim($value) === '';
        } catch (RuleViolation) {
            return false;
        }
    }

    public static function triState(mixed $value, string $path, FieldErrors $errors): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        $errors->add($path, 'Répondez oui, non, ou laissez non renseigné.');

        return null;
    }

    private static function normalize(mixed $value, string $path, FieldErrors $errors, bool $multiline, int $max): ?string
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
        if (mb_strlen($text, 'UTF-8') > $max) {
            $errors->add($path, 'Ce texte dépasse '.$max.' caractères.');

            return null;
        }
        if (preg_match($multiline ? '/[^\P{Cc}\n\r\t]/u' : '/\p{Cc}/u', $text) === 1) {
            $errors->add($path, 'Ce champ ne peut pas contenir de caractère de contrôle.');

            return null;
        }

        return $text;
    }
}
