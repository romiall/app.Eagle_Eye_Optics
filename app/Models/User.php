<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte individuel du cabinet (table `users` du catalogue : id ULID, login, display_name, password_hash,
 * active). Squelette de F02 aligné sur le catalogue ; F05 le complète (Policies, permissions, session
 * Sanctum, désactivation). Pas de table `users` Laravel par défaut : la migration vient de F04.
 */
class User extends Authenticatable
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['clinic_id', 'display_name', 'login', 'email', 'password_hash', 'active'];

    /** @var list<string> */
    protected $hidden = ['password_hash', 'mfa_secret_ciphertext'];

    /**
     * Le mot de passe est lu dans `password_hash` (jamais dans une colonne `password`).
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * « Rester connecté » est désactivé tant qu'aucun stockage de jeton compatible n'est prévu :
     * aucune colonne remember_token n'existe dans le catalogue.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
