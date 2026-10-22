<?php

namespace App\Domain\Clinical;

use InvalidArgumentException;
use stdClass;

/**
 * Empreinte d'une révision clinique : SHA-256 hexadécimal minuscule de la sérialisation JSON canonique (JCS,
 * RFC 8785) du snapshot. Hypothèse retenue pour la décision d'empreinte des révisions (« G8 » dans les notes de
 * pilotage ; non inscrite au registre 15) : le contrat `snapshots/clinical-revision.json` dit déjà
 * « sha256 = SHA-256 de JCS(snapshot) ».
 *
 * Sous-ensemble de JCS suffisant pour un snapshot clinique, et VÉRIFIÉ à l'identique côté navigateur par les
 * vecteurs communs `contracts/vectors/clinical/snapshot-hash.json` :
 *  - objets : clés triées par unités de code UTF-16 (ici des clés ASCII, donc l'ordre des octets) ;
 *  - tableaux : ordre conservé ; chaînes : échappement minimal de JSON.stringify (guillemet, barre oblique inverse,
 *    \b \f \n \r \t, autres contrôles en \u00xx minuscule), Unicode et « / » non échappés ;
 *  - nombres : ENTIERS seulement (une mesure est une chaîne décimale exacte, jamais un flottant) ; un flottant lève
 *    une exception plutôt que de produire une empreinte ambiguë ;
 *  - booléens et null littéraux.
 *
 * Tâche O02 (JCS complet et vecteurs croisés) : cette classe n'en tient pas lieu pour les commandes signées ; elle
 * sert aux révisions cliniques, dont le contenu est borné à ces types.
 */
final class RevisionHash
{
    public static function canonical(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('Un flottant n\'a pas de forme canonique admise dans une révision clinique.');
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        }
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Type non pris en charge dans une révision clinique.');
        }

        if (array_is_list($value)) {
            return '['.implode(',', array_map(self::canonical(...), $value)).']';
        }

        ksort($value, SORT_STRING);
        $members = [];
        foreach ($value as $key => $member) {
            $members[] = self::canonical((string) $key).':'.self::canonical($member);
        }

        return '{'.implode(',', $members).'}';
    }

    /** SHA-256 hexadécimal minuscule (64 caractères) de la forme canonique. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonical($value));
    }
}
