<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Access\StaffRoles;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Dérogation nominative datée et motivée (fichier 09 : « toute permission supplémentaire est attribuée
 * explicitement, datée et journalisée »). ALLOW ajoute, DENY retire malgré le profil, CLEAR supprime la
 * dérogation (l'historique reste dans l'audit). Pas de dérogation sur son propre compte.
 */
final class SetStaffPermissionOverride
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly EffectivePermissions $permissions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, string $userId, array $input): User
    {
        $clinicId = $actor->clinic_id;

        if ($userId === $actor->id) {
            throw new BusinessError('SELF_OVERRIDE_FORBIDDEN', 'Une dérogation ne s\'applique pas à son propre compte.', 409);
        }

        $data = Validator::make($input, [
            'permission_code' => ['required', 'string', Rule::in($this->permissions->known())],
            'effect' => ['required', 'string', Rule::in(['ALLOW', 'DENY', 'CLEAR'])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ])->validate();

        return DB::transaction(function () use ($actor, $clinicId, $userId, $data): User {
            StaffRoles::lockClinic($clinicId);

            if (! DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->exists()) {
                throw BusinessError::notFound();
            }

            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            $code = (string) $data['permission_code'];
            $existing = DB::table('user_permissions')->where('clinic_id', $clinicId)->where('user_id', $userId)->where('permission_code', $code);
            $expiresAt = isset($data['expires_at']) ? CarbonImmutable::parse((string) $data['expires_at'])->utc()->format('Y-m-d H:i:s.u') : null;

            if ($data['effect'] === 'CLEAR') {
                $existing->delete();
            } else {
                $values = [
                    'allowed' => $data['effect'] === 'ALLOW',
                    'assigned_by' => $actor->id,
                    'reason' => trim((string) $data['reason']),
                    'expires_at' => $expiresAt,
                    'updated_at' => $now,
                ];
                if ($existing->clone()->exists()) {
                    $existing->update($values);
                } else {
                    DB::table('user_permissions')->insert($values + [
                        'id' => Ulid::generate(),
                        'clinic_id' => $clinicId,
                        'user_id' => $userId,
                        'permission_code' => $code,
                        'created_at' => $now,
                    ]);
                }
            }

            $this->audit->record($clinicId, $actor, 'staff.permission_override', 'users', $userId, trim((string) $data['reason']), [
                'permission' => $code,
                'effet' => (string) $data['effect'],
                'expire_le' => $expiresAt,
            ]);

            /** @var User $target */
            $target = User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
            $this->permissions->forget($target);

            return $target;
        });
    }
}
