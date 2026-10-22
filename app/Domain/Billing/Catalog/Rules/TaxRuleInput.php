<?php

namespace App\Domain\Billing\Catalog\Rules;

use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Rate;
use App\Domain\Shared\ValueObjects\Text;
use stdClass;

/**
 * Règle fiscale saisie par la gérante (B01, EE020) : AUCUNE taxe n'est préconfigurée ni inventée par le code, le
 * régime réel vient de la gérante et du comptable (Q03). Une règle n'est APPLIQUÉE aux documents que si elle est
 * active et validée (taux, validateur et date : CHECK `ck_tax_rules_02`).
 *
 *  - `validate = true` enregistre la validation par l'auteur, à l'instant de la commande ;
 *  - une règle ACTIVE exige un taux et une validation ;
 *  - modifier le TAUX d'une règle déjà validée retire sa validation : une règle active doit alors être
 *    revalidée dans la même demande, sinon elle est refusée (le taux validé n'est jamais changé en silence) ;
 *  - désactiver une règle ne retire pas sa validation passée (l'historique des pièces émises ne dépend pas d'elle).
 */
final class TaxRuleInput
{
    /**
     * @param  array<string, mixed>  $payload  charge sans `expected_version`
     * @param  stdClass|null  $current  règle actuelle (modification) ou null (création)
     * @return array{code: string, label: string, rate_percent: ?string, active: bool, validated: bool, keep_validation: bool, legal_note: ?string}|null
     */
    public static function columns(array $payload, ?stdClass $current, FieldErrors $errors): ?array
    {
        $before = $errors->all();

        $code = self::text($payload['code'] ?? null, 40, 'code', $errors);
        $label = self::text($payload['label'] ?? null, 180, 'label', $errors);
        $note = null;
        if (($payload['legal_note'] ?? null) !== null) {
            try {
                $note = Text::optional((string) $payload['legal_note'], 4000, 'legal_note');
            } catch (RuleViolation $violation) {
                $errors->addViolation('', $violation);
            }
        }

        $rate = null;
        if (($payload['rate_percent'] ?? null) !== null) {
            try {
                $rate = Rate::parse((string) $payload['rate_percent'], 'rate_percent')?->canonical();
            } catch (RuleViolation $violation) {
                $errors->addViolation('', $violation);
            }
        }

        $active = ($payload['active'] ?? null) === true;
        $validate = ($payload['validate'] ?? null) === true;

        if ($validate && $rate === null) {
            $errors->add('validate', 'Une règle sans taux ne peut pas être validée.');
        }

        $rateChanged = $current !== null && $current->rate_percent !== null && $rate !== (string) $current->rate_percent;
        $wasValidated = $current !== null && $current->validated_at !== null;
        // Validation conservée seulement si le taux n'a pas changé et que la demande n'en enregistre pas une nouvelle.
        $keepValidation = $wasValidated && ! $rateChanged && ! $validate;
        $validated = $validate || $keepValidation;

        if ($active && ! $validated) {
            $errors->add('active', $rateChanged
                ? 'Le taux a changé : revalidez la règle (validate) pour la laisser active.'
                : 'Une règle active doit être validée par la gérante (validate).');
        }
        if ($active && $rate === null) {
            $errors->add('active', 'Une règle active exige un taux.');
        }

        if ($errors->all() !== $before || $code === null || $label === null) {
            return null;
        }

        return [
            'code' => $code,
            'label' => $label,
            'rate_percent' => $rate,
            'active' => $active,
            'validated' => $validate,
            'keep_validation' => $keepValidation,
            'legal_note' => $note,
        ];
    }

    private static function text(mixed $value, int $maxLength, string $field, FieldErrors $errors): ?string
    {
        if (! is_string($value)) {
            $errors->add($field, 'Ce champ est obligatoire.');

            return null;
        }
        try {
            $text = Text::optional($value, $maxLength, $field);
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);

            return null;
        }
        if ($text === null) {
            $errors->add($field, 'Ce champ est obligatoire.');
        }

        return $text;
    }
}
