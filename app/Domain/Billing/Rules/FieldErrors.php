<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\RuleViolation;

/**
 * Erreurs de validation d'une commande de facturation, par champ, rendues TOUTES ensemble (422
 * `VALIDATION_FAILED`) : l'écran conserve la saisie et désigne chaque champ en cause. Les clés sont des chemins
 * pointés relatifs au corps de la requête, comme ceux du validateur de contrat (`lines.2.discount_gross`).
 */
final class FieldErrors
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function add(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }

    /**
     * Refus d'une règle pure rattaché à un chemin (`lines.0` + le champ de la règle, quand elle en nomme un).
     */
    public function addViolation(string $prefix, RuleViolation $violation): void
    {
        $path = $violation->field === '' ? $prefix : ($prefix === '' ? $violation->field : $prefix.'.'.$violation->field);
        $this->add($path, $violation->getMessage());
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * Lève le refus 422 si au moins une erreur a été relevée.
     */
    public function throwIfAny(): void
    {
        if ($this->errors !== []) {
            throw BusinessError::validation($this->errors);
        }
    }
}
