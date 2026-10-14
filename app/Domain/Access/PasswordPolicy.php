<?php

namespace App\Domain\Access;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * Mots de passe du personnel : au moins 12 caractères avec lettres et chiffres, au plus 72 octets
 * (limite de bcrypt), différent de l'identifiant. Aucune vérification par service externe (pas d'appel
 * réseau). Aucun mot de passe universel ni par défaut n'existe dans l'application.
 */
final class PasswordPolicy
{
    /**
     * @return list<mixed>
     */
    public static function rules(?string $login = null): array
    {
        return [
            'required',
            'string',
            Password::min(12)->letters()->numbers(),
            static function (string $attribute, mixed $value, Closure $fail) use ($login): void {
                if (! is_string($value)) {
                    return;
                }
                if (strlen($value) > 72) {
                    $fail('Le mot de passe ne doit pas dépasser 72 octets.');
                }
                if ($login !== null && mb_strtolower(trim($value)) === mb_strtolower(trim($login))) {
                    $fail('Le mot de passe doit être différent de l\'identifiant.');
                }
            },
        ];
    }

    public static function normalizeLogin(string $login): string
    {
        return mb_strtolower(trim($login));
    }
}
