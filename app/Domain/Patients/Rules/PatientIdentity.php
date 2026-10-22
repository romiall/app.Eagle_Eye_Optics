<?php

namespace App\Domain\Patients\Rules;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Text;
use stdClass;

/**
 * Identité administrative d'un patient (fichier 02, « Patients et accueil ») : validation de l'état
 * RÉSULTANT d'une création ou d'une modification, et colonnes dérivées (recherche, téléphones normalisés).
 *
 * - Le nom est obligatoire ; un prénom peut être absent.
 * - La date de naissance est connue et non future, ou son absence est expliquée : jamais de date inventée.
 * - Le téléphone principal est renseigné, ou son absence est expliquée. Un téléphone peut être partagé.
 * - Les colonnes `name_search` et `*_normalized` sont toujours recalculées ici, jamais reprises du client.
 * - Aucun caractère de contrôle dans les champs d'une ligne (l'adresse admet les retours à la ligne).
 * - Le contact du responsable d'un mineur (extension facultative) ne porte que `name`, `relationship` et `phone`.
 * - Les champs d'import historique (création seulement) sont cohérents entre eux : une première visite connue
 *   n'est ni future ni antérieure à la naissance, « certifiée » exige une date, un ancien numéro exige un
 *   dossier d'import.
 *
 * Toutes les erreurs sont rendues ensemble (422 `VALIDATION_FAILED`, une entrée par champ) : l'écran
 * conserve la saisie et désigne chaque champ en cause. `$fieldPrefix` désigne le conteneur dans une
 * modification (`changes.`).
 */
final class PatientIdentity
{
    public const SEXES = ['F', 'M', 'UNKNOWN'];

    /** Champs d'identité communs à la création et à la modification. */
    public const FIELDS = [
        'last_name', 'first_names', 'sex', 'birth_date', 'birth_date_missing_reason',
        'phone_primary', 'phone_secondary', 'phone_missing_reason', 'address', 'profession', 'guardian_contact',
    ];

    /** Clés admises du contact du responsable et longueur maximale de leur valeur. */
    private const GUARDIAN_KEYS = ['name' => 180, 'relationship' => 80, 'phone' => 32];

    /** Longueur de la colonne `name_search` (VARCHAR(320)) : la décomposition NFD peut allonger un nom. */
    private const NAME_SEARCH_MAX = 320;

    /**
     * @param  array<string, mixed>  $input  état complet de l'identité (clés de FIELDS) ; avec la clé
     *                                       `legacy_record`, les champs d'import historique sont aussi validés
     * @return array<string, mixed> colonnes de `patient_records` à écrire (dérivées comprises)
     *
     * @throws BusinessError
     */
    public static function columns(array $input, CivilDate $today, string $fieldPrefix = ''): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        $fail = static function (string $field, string $message) use (&$errors, $fieldPrefix): void {
            $errors[$fieldPrefix.$field][] = $message;
        };
        $attempt = static function (string $field, callable $rule) use ($fail): mixed {
            try {
                return $rule();
            } catch (RuleViolation $violation) {
                $fail($field, $violation->getMessage());

                return null;
            }
        };
        $oneLine = static function (string $field, ?string $value) use ($fail): ?string {
            if ($value !== null && preg_match('/\p{Cc}/u', $value) === 1) {
                $fail($field, 'Ce champ ne peut pas contenir de caractère de contrôle.');
            }

            return $value;
        };

        $lastName = Text::trim((string) ($input['last_name'] ?? ''));
        $nameKey = $attempt('last_name', static fn (): string => Text::searchKey($lastName));
        if ($lastName === '' || $nameKey === '') {
            $fail('last_name', 'Le nom est obligatoire.');
        } elseif (mb_strlen($lastName, 'UTF-8') > 120) {
            $fail('last_name', 'Ce texte dépasse 120 caractères.');
        }
        $oneLine('last_name', $lastName);
        $firstNames = $oneLine('first_names', $attempt('first_names', static fn (): ?string => Text::optional(self::text($input['first_names'] ?? null), 180, 'first_names')));

        $sex = $input['sex'] ?? 'UNKNOWN';
        if (! is_string($sex) || ! in_array($sex, self::SEXES, true)) {
            $fail('sex', 'Choisissez Féminin, Masculin ou Non renseigné.');
            $sex = 'UNKNOWN';
        }

        $birth = null;
        $birthRaw = $input['birth_date'] ?? null;
        if ($birthRaw !== null) {
            $birth = $attempt('birth_date', static fn (): ?CivilDate => CivilDate::parse(is_string($birthRaw) ? $birthRaw : '', 'birth_date'));
            if ($birth !== null && $birth->compare($today) > 0) {
                $fail('birth_date', 'La date de naissance ne peut pas être dans le futur.');
            }
        }
        $birthReason = $oneLine('birth_date_missing_reason', $attempt('birth_date_missing_reason', static fn (): ?string => Text::optional(self::text($input['birth_date_missing_reason'] ?? null), 255, 'birth_date_missing_reason')));
        if ($birth === null && $birthReason === null && ! isset($errors[$fieldPrefix.'birth_date'])) {
            $fail('birth_date_missing_reason', 'Indiquez pourquoi la date de naissance est inconnue : aucune date n\'est inventée.');
        }

        $primary = $oneLine('phone_primary', $attempt('phone_primary', static fn (): ?string => Text::optional(self::text($input['phone_primary'] ?? null), 32, 'phone_primary')));
        $primaryNormalized = $primary === null ? null : $attempt('phone_primary', static fn (): ?string => Text::phone($primary, 'phone_primary'));
        $secondary = $oneLine('phone_secondary', $attempt('phone_secondary', static fn (): ?string => Text::optional(self::text($input['phone_secondary'] ?? null), 32, 'phone_secondary')));
        $secondaryNormalized = $secondary === null ? null : $attempt('phone_secondary', static fn (): ?string => Text::phone($secondary, 'phone_secondary'));
        $phoneReason = $oneLine('phone_missing_reason', $attempt('phone_missing_reason', static fn (): ?string => Text::optional(self::text($input['phone_missing_reason'] ?? null), 255, 'phone_missing_reason')));
        if ($primary === null && $phoneReason === null) {
            $fail('phone_missing_reason', 'Indiquez pourquoi le téléphone est inconnu.');
        }

        $address = $attempt('address', static fn (): ?string => Text::optional(self::text($input['address'] ?? null), 4000, 'address'));
        if ($address !== null && preg_match('/[^\P{Cc}\n\r\t]/u', $address) === 1) {
            $fail('address', 'Ce champ ne peut pas contenir de caractère de contrôle.');
        }
        $profession = $oneLine('profession', $attempt('profession', static fn (): ?string => Text::optional(self::text($input['profession'] ?? null), 180, 'profession')));

        $guardian = self::guardian($input['guardian_contact'] ?? null, $fail);

        $columns = [];
        if (array_key_exists('legacy_record', $input)) {
            $columns = self::legacy($input, $birth, $today, $fail, $oneLine);
        }

        $nameSearch = $errors === [] ? Text::searchKey($lastName.' '.($firstNames ?? '')) : '';
        if (mb_strlen($nameSearch, 'UTF-8') > self::NAME_SEARCH_MAX) {
            $fail('last_name', 'Le nom et les prénoms sont trop longs pour la recherche.');
        }

        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        return $columns + [
            'last_name' => $lastName,
            'first_names' => $firstNames,
            'sex' => $sex,
            'birth_date' => $birth?->toString(),
            'birth_date_missing_reason' => $birth === null ? $birthReason : null,
            'phone_primary' => $primary,
            'phone_primary_normalized' => $primaryNormalized,
            'phone_secondary' => $secondary,
            'phone_secondary_normalized' => $secondaryNormalized,
            'phone_missing_reason' => $primary === null ? $phoneReason : null,
            'name_search' => $nameSearch,
            'address' => $address,
            'profession' => $profession,
            'guardian_contact' => $guardian === null ? null : json_encode((object) $guardian, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Valeurs d'identité d'une fiche existante, au format d'entrée de `columns()`.
     *
     * @return array<string, mixed>
     */
    public static function fromRow(stdClass $row): array
    {
        $guardian = $row->guardian_contact ?? null;

        return [
            'last_name' => $row->last_name,
            'first_names' => $row->first_names,
            'sex' => $row->sex,
            'birth_date' => $row->birth_date,
            'birth_date_missing_reason' => $row->birth_date_missing_reason,
            'phone_primary' => $row->phone_primary,
            'phone_secondary' => $row->phone_secondary,
            'phone_missing_reason' => $row->phone_missing_reason,
            'address' => $row->address,
            'profession' => $row->profession,
            'guardian_contact' => is_string($guardian) ? json_decode($guardian, true, 512, JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * Contact du responsable : `name`, `relationship`, `phone` (texte non vide borné), rien d'autre. Vide → null.
     *
     * @return array<string, string>|null
     */
    private static function guardian(mixed $value, callable $fail): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $fail('guardian_contact', 'Le contact du responsable est un objet (nom, lien, téléphone).');

            return null;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $max = self::GUARDIAN_KEYS[$key] ?? null;
            $text = is_string($item) ? Text::trim($item) : '';
            if ($max === null) {
                $fail('guardian_contact', 'Seuls le nom, le lien et le téléphone du responsable sont admis.');
            } elseif ($text === '' || mb_strlen($text, 'UTF-8') > $max || preg_match('/\p{Cc}/u', $text) === 1) {
                $fail('guardian_contact', "La valeur « {$key} » du responsable est vide, trop longue ou invalide.");
            } else {
                $clean[$key] = $text;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Champs d'import historique d'une création, cohérents entre eux.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function legacy(array $input, ?CivilDate $birth, CivilDate $today, callable $fail, callable $oneLine): array
    {
        $legacy = $input['legacy_record'] === true;
        $certified = ($input['first_visit_certified'] ?? false) === true;

        $first = null;
        $firstRaw = $input['first_known_visit_on'] ?? null;
        if ($firstRaw !== null) {
            try {
                $first = CivilDate::parse(is_string($firstRaw) ? $firstRaw : '', 'first_known_visit_on');
            } catch (RuleViolation $violation) {
                $fail('first_known_visit_on', $violation->getMessage());
            }
            if ($first !== null && $first->compare($today) > 0) {
                $fail('first_known_visit_on', 'La première visite connue ne peut pas être dans le futur.');
            } elseif ($first !== null && $birth !== null && $first->compare($birth) < 0) {
                $fail('first_known_visit_on', 'La première visite connue ne peut pas précéder la naissance.');
            }
        }
        if ($certified && $first === null && $firstRaw === null) {
            $fail('first_visit_certified', 'Une première visite certifiée exige sa date.');
        }

        $number = null;
        try {
            $number = $oneLine('legacy_record_number', Text::optional(self::text($input['legacy_record_number'] ?? null), 64, 'legacy_record_number'));
        } catch (RuleViolation $violation) {
            $fail('legacy_record_number', $violation->getMessage());
        }
        if ($number !== null && ! $legacy) {
            $fail('legacy_record_number', 'Un ancien numéro de dossier n\'existe que pour un dossier repris de l\'ancien registre.');
        }

        return [
            'legacy_record' => $legacy,
            'first_known_visit_on' => $first?->toString(),
            'first_visit_certified' => $certified,
            'legacy_record_number' => $number,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
