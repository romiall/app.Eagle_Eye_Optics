<?php

namespace App\Models;

use App\Domain\Access\EffectivePermissions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte individuel du cabinet (table `users` du catalogue) : id ULID, login, display_name,
 * password_hash, active, version. Jamais supprimé pour effacer un auteur : on le désactive.
 *
 * Aucune affectation de masse de clinic_id, password_hash, active ou version : ces champs ne sont écrits
 * que par les actions du domaine Accès (contrat 23 : l'utilisateur ne choisit pas un clinic_id).
 *
 * @property string $id
 * @property string $clinic_id
 * @property string $display_name
 * @property string $login
 * @property string|null $email
 * @property string $password_hash
 * @property bool $active
 * @property int $version
 */
class User extends Authenticatable
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = ['display_name', 'email'];

    /** @var list<string> */
    protected $hidden = ['password_hash', 'mfa_secret_ciphertext', 'mfa_key_id'];

    /**
     * Le mot de passe est lu dans `password_hash` (jamais dans une colonne `password`).
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * « Rester connecté » est désactivé : aucune colonne remember_token n'existe dans le catalogue.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function hasPermission(string $permission): bool
    {
        return app(EffectivePermissions::class)->has($this, $permission);
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return app(EffectivePermissions::class)->for($this);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'version' => 'integer',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
