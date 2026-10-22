<?php

namespace App\Domain\Clinical\Rules;

/**
 * Erreurs de validation d'une rubrique clinique, par champ, rendues TOUTES ensemble (422 `VALIDATION_FAILED`) :
 * l'écran conserve la saisie et désigne chaque champ en cause. Les clés sont des chemins pointés relatifs au
 * corps de la requête, comme ceux du validateur de contrat (`step.data.complaints.0.other_text`).
 */
final class FieldErrors
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function add(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
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
}
