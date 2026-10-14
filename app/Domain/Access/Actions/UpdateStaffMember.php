<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Access\StaffAdministration;
use App\Domain\Access\StaffRoles;
use App\Domain\Access\StaffSessions;
use App\Domain\Shared\BusinessError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Modification d'un compte par une gérante : nom, courriel, profils, activation. Version attendue
 * (verrouillage optimiste), motif obligatoire pour une désactivation ou un retrait de profil.
 *
 * Une désactivation ferme immédiatement toutes les sessions serveur du compte. Le cabinet garde toujours
 * au moins une gérante active capable de gérer les comptes, une gérante ne se désactive pas elle-même et
 * personne ne modifie ses propres profils (pas d'auto-attribution ni d'auto-rétrogradation qui
 * contournerait le contrôle d'une autre gérante).
 */
final class UpdateStaffMember
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly StaffSessions $sessions,
        private readonly EffectivePermissions $permissions,
        private readonly StaffAdministration $administration,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, string $userId, array $input): User
    {
        $clinicId = $actor->clinic_id;

        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'display_name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254', Rule::unique('users', 'email')->where('clinic_id', $clinicId)->ignore($userId, 'id')],
            'active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(StaffRoles::known())],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            return DB::transaction(fn (): User => $this->apply($actor, $clinicId, $userId, $data), 3);
        } catch (UniqueConstraintViolationException) {
            // Course entre la validation `unique` (hors verrou) et l'écriture : même réponse qu'une
            // validation, sans message SQL.
            throw BusinessError::validation(['email' => ['La valeur du champ adresse électronique est déjà utilisée.']]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function apply(User $actor, string $clinicId, string $userId, array $data): User
    {
        $actor = $this->administration->lockAndAuthorize($actor);

        /** @var object{id: string, display_name: string, email: ?string, active: int|bool, version: int}|null $target */
        $target = DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->lockForUpdate()->first();
        if ($target === null) {
            throw BusinessError::notFound();
        }
        if ((int) $target->version !== (int) $data['expected_version']) {
            throw BusinessError::versionConflict();
        }

        $diff = [];
        $changes = [];
        foreach (['display_name', 'email'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $target->{$field}) {
                $value = $data[$field] === null ? null : trim((string) $data[$field]);
                $changes[$field] = $value;
                $diff[$field] = ['avant' => $target->{$field}, 'apres' => $value];
            }
        }

        $deactivating = array_key_exists('active', $data) && ! (bool) $data['active'] && (bool) $target->active;
        $reactivating = array_key_exists('active', $data) && (bool) $data['active'] && ! (bool) $target->active;

        /** @var list<string>|null $wantedRoles */
        $wantedRoles = array_key_exists('roles', $data) ? array_values(array_map('strval', (array) $data['roles'])) : null;
        $currentRoles = StaffRoles::activeRoles($clinicId, $userId);
        $losingManager = $wantedRoles !== null && in_array(StaffRoles::MANAGER, $currentRoles, true) && ! in_array(StaffRoles::MANAGER, $wantedRoles, true);
        $removingRoles = $wantedRoles !== null && array_diff($currentRoles, $wantedRoles) !== [];

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
        if (($deactivating || $removingRoles) && $reason === '') {
            throw BusinessError::validation(['reason' => ['Le motif est obligatoire pour désactiver un compte ou retirer un profil.']]);
        }
        if ($deactivating && $userId === $actor->id) {
            throw new BusinessError('SELF_DEACTIVATION_FORBIDDEN', 'Une gérante ne peut pas désactiver son propre compte.', 409);
        }
        if ($wantedRoles !== null && $userId === $actor->id && self::sorted($wantedRoles) !== $currentRoles) {
            throw new BusinessError('SELF_ROLE_CHANGE_FORBIDDEN', 'Vos propres profils sont modifiés par une autre gérante.', 409);
        }
        if (($deactivating || $losingManager) && in_array(StaffRoles::MANAGER, $currentRoles, true)) {
            StaffRoles::assertAnotherActiveManager($clinicId, $userId);
        }

        if ($deactivating || $reactivating) {
            $changes['active'] = $reactivating;
            $diff['active'] = ['avant' => (bool) $target->active, 'apres' => $reactivating];
        }

        if ($wantedRoles !== null) {
            [$added, $revoked] = StaffRoles::sync($clinicId, $userId, $wantedRoles, $actor->id);
            if ($added !== [] || $revoked !== []) {
                $diff['roles'] = ['ajoutes' => $added, 'revoques' => $revoked];
            }
        }

        if ($diff === []) {
            return User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
        }

        $changes['version'] = (int) $target->version + 1;
        $changes['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->update($changes);

        $this->audit->record($clinicId, $actor, $deactivating ? 'staff.deactivated' : 'staff.updated', 'users', $userId, $reason !== '' ? $reason : null, $diff);

        if ($deactivating) {
            $this->sessions->closeAll($userId);
        }

        /** @var User $updated */
        $updated = User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
        $this->permissions->forget($updated);

        return $updated;
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private static function sorted(array $roles): array
    {
        sort($roles);

        return $roles;
    }
}
