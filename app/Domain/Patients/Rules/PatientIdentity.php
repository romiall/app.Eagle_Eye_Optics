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

    /**
     * @param  array<string, mixed>  $input  état complet de l'identité (clés de FIELDS)
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

        $lastName = Text::trim((string) ($input['last_name'] ?? ''));
        $nameKey = $attempt('last_name', static fn (): string => Text::searchKey($lastName));
        if ($lastName === '' || $nameKey === '') {
            $fail('last_name', 'Le nom est obligatoire.');
        } elseif (mb_strlen($lastName, 'UTF-8') > 120) {
            $fail('last_name', 'Ce texte dépasse 120 caractères.');
        }
        $firstNames = $attempt('first_names', static fn (): ?string => Text::optional(self::text($input['first_names'] ?? null), 180, 'first_names'));

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
        $birthReason = $attempt('birth_date_missing_reason', static fn (): ?string => Text::optional(self::text($input['birth_date_missing_reason'] ?? null), 255, 'birth_date_missing_reason'));
        if ($birth === null && $birthReason === null && ! isset($errors[$fieldPrefix.'birth_date'])) {
            $fail('birth_date_missing_reason', 'Indiquez pourquoi la date de naissance est inconnue : aucune date n\'est inventée.');
        }

        $primary = $attempt('phone_primary', static fn (): ?string => Text::optional(self::text($input['phone_primary'] ?? null), 32, 'phone_primary'));
        $primaryNormalized = $primary === null ? null : $attempt('phone_primary', static fn (): ?string => Text::phone($primary, 'phone_primary'));
        $secondary = $attempt('phone_secondary', static fn (): ?string => Text::optional(self::text($input['phone_secondary'] ?? null), 32, 'phone_secondary'));
        $secondaryNormalized = $secondary === null ? null : $attempt('phone_secondary', static fn (): ?string => Text::phone($secondary, 'phone_secondary'));
        $phoneReason = $attempt('phone_missing_reason', static fn (): ?string => Text::optional(self::text($input['phone_missing_reason'] ?? null), 255, 'phone_missing_reason'));
        if ($primary === null && $phoneReason === null) {
            $fail('phone_missing_reason', 'Indiquez pourquoi le téléphone est inconnu.');
        }

        $address = $attempt('address', static fn (): ?string => Text::optional(self::text($input['address'] ?? null), 4000, 'address'));
        $profession = $attempt('profession', static fn (): ?string => Text::optional(self::text($input['profession'] ?? null), 180, 'profession'));

        $guardian = $input['guardian_contact'] ?? null;
        if ($guardian !== null && ! is_array($guardian)) {
            $fail('guardian_contact', 'Le contact du responsable doit être un objet.');
            $guardian = null;
        }

        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $nameSearch = Text::searchKey($lastName.' '.($firstNames ?? ''));

        return [
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
            'guardian_contact' => $guardian === null || $guardian === [] ? null : json_encode((object) $guardian, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
