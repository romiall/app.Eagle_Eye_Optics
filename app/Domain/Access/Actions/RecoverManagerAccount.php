<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\ClinicContext;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Access\StaffAdministration;
use App\Domain\Access\StaffRoles;
use App\Domain\Access\StaffSessions;
use App\Domain\Shared\BusinessError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Procédure de secours, uniquement depuis la console du serveur : rétablit l'accès d'une gérante (mot
 * de passe perdu, compte désactivé, profil retiré par erreur, cabinet sans gérante capable de gérer les
 * comptes). Le mot de passe d'une gérante ne se réinitialise pas par l'API (ResetStaffPassword).
 *
 * Le compte visé doit être ou avoir été gérante (profil MANAGER actif ou révoqué) : la procédure rétablit
 * une gérante, elle ne promeut pas un autre compte. Effets, dans une transaction sous le verrou du
 * cabinet : nouveau mot de passe, compte actif, profil Gérante actif, dérogations users.manage
 * supprimées, toutes les sessions du compte fermées, événement d'audit sans auteur authentifié avec le
 * motif obligatoire.
 */
final class RecoverManagerAccount
{
    public const ACTOR = 'Procédure de secours (console du serveur)';

    public function __construct(
        private readonly ClinicContext $clinics,
        private readonly AuditRecorder $audit,
        private readonly StaffSessions $sessions,
        private readonly EffectivePermissions $permissions,
    ) {}

    /**
     * @return array{user_id: string, reactivated: bool, manager_role_restored: bool, overrides_removed: int}
     */
    public function __invoke(string $login, #[\SensitiveParameter] string $password, string $reason): array
    {
        $clinicId = $this->clinics->configuredClinicId();
        $normalized = PasswordPolicy::normalizeLogin($login);

        $data = Validator::make(['password' => $password, 'reason' => $reason], [
            'password' => PasswordPolicy::rules($normalized),
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ])->validate();

        $passwordHash = Hash::make((string) $data['password']);

        return DB::transaction(function () use ($clinicId, $normalized, $passwordHash, $data): array {
            StaffRoles::lockClinic($clinicId);

            /** @var object{id: string, active: int|bool, version: int}|null $target */
            $target = DB::table('users')->where('clinic_id', $clinicId)->where('login', $normalized)->lockForUpdate()->first(['id', 'active', 'version']);
            if ($target === null) {
                throw BusinessError::notFound('Compte introuvable dans le cabinet configuré.');
            }

            $everManager = DB::table('user_roles')
                ->where('clinic_id', $clinicId)->where('user_id', $target->id)->where('role_code', StaffRoles::MANAGER)
                ->exists();
            if (! $everManager) {
                throw new BusinessError('RECOVERY_TARGET_NOT_MANAGER', 'La procédure de secours rétablit une gérante existante ou ancienne ; ce compte n\'a jamais eu le profil Gérante.', 409);
            }

            $roles = StaffRoles::activeRoles($clinicId, $target->id);
            $roleRestored = ! in_array(StaffRoles::MANAGER, $roles, true);
            if ($roleRestored) {
                StaffRoles::sync($clinicId, $target->id, [...$roles, StaffRoles::MANAGER], $target->id);
            }

            $overridesRemoved = DB::table('user_permissions')
                ->where('clinic_id', $clinicId)->where('user_id', $target->id)
                ->where('permission_code', StaffAdministration::MANAGE_PERMISSION)
                ->delete();

            DB::table('users')->where('clinic_id', $clinicId)->where('id', $target->id)->update([
                'password_hash' => $passwordHash,
                'active' => true,
                'version' => (int) $target->version + 1,
                'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            $reactivated = ! (bool) $target->active;
            $this->audit->record($clinicId, null, 'staff.recovered_by_console', 'users', $target->id, trim((string) $data['reason']), [
                'compte_reactive' => $reactivated,
                'profil_gerante_retabli' => $roleRestored,
                'derogations_users_manage_supprimees' => $overridesRemoved,
            ], self::ACTOR);
            $this->sessions->closeAll($target->id);
            $this->permissions->forget();

            return [
                'user_id' => $target->id,
                'reactivated' => $reactivated,
                'manager_role_restored' => $roleRestored,
                'overrides_removed' => $overridesRemoved,
            ];
        });
    }
}
