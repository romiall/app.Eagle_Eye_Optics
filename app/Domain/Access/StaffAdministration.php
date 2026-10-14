<?php

namespace App\Domain\Access;

use App\Domain\Shared\BusinessError;
use App\Models\User;

/**
 * Garde commune des mutations de comptes et du cabinet, appelée DANS la transaction de l'action.
 *
 * Le middleware `can:users.manage` évalue les droits avant l'action, hors verrou : une gérante désactivée
 * ou rétrogradée pendant que sa requête attend le verrou du cabinet ne doit pas pouvoir agir, et deux
 * restrictions croisées simultanées ne doivent pas passer chacune un contrôle fait avant l'autre. Sous le
 * verrou de la ligne du cabinet (premier verrou de l'ordre du contrat 23), l'auteur est relu en base :
 * compte actif, profil Gérante actif et permission effective `users.manage` (fichier 09 : gestion des
 * utilisateurs réservée à la gérante).
 */
final class StaffAdministration
{
    /** Permission réservée au profil Gérante : jamais attribuée ni retirée par dérogation nominative. */
    public const MANAGE_PERMISSION = 'users.manage';

    public function __construct(private readonly EffectivePermissions $permissions) {}

    public function lockAndAuthorize(User $actor): User
    {
        StaffRoles::lockClinic($actor->clinic_id);

        /** @var User|null $fresh */
        $fresh = User::query()->where('clinic_id', $actor->clinic_id)->find($actor->getKey());
        if ($fresh === null || ! $fresh->active || ! in_array(StaffRoles::MANAGER, StaffRoles::activeRoles($actor->clinic_id, $fresh->id), true)) {
            throw BusinessError::forbidden();
        }

        $this->permissions->forget($fresh);
        if (! $this->permissions->has($fresh, self::MANAGE_PERMISSION)) {
            throw BusinessError::forbidden();
        }

        return $fresh;
    }
}
