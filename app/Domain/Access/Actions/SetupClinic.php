<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Access\StaffRoles;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Premier démarrage contrôlé (contrat 23) : crée le cabinet, puis le compte de la première gérante, puis
 * lui attribue le profil MANAGER avec assigned_by = ce compte désormais existant. Refusé si un cabinet
 * existe déjà. Aucun mot de passe universel ni par défaut : il est saisi par la personne qui installe.
 */
final class SetupClinic
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{clinic_name: mixed, manager_name: mixed, login: mixed, email?: mixed, password: mixed}  $input
     * @return array{clinic_id: string, user_id: string}
     */
    public function __invoke(#[\SensitiveParameter] array $input): array
    {
        $login = is_string($input['login']) ? PasswordPolicy::normalizeLogin($input['login']) : null;

        $data = Validator::make(['login' => $login] + $input, [
            'clinic_name' => ['required', 'string', 'min:2', 'max:180'],
            'manager_name' => ['required', 'string', 'min:2', 'max:180'],
            'login' => ['required', 'string', 'regex:'.CreateStaffMember::LOGIN_PATTERN],
            'email' => ['nullable', 'email', 'max:254'],
            'password' => PasswordPolicy::rules($login),
        ])->validate();

        $passwordHash = Hash::make((string) $data['password']);

        return DB::transaction(function () use ($data, $passwordHash): array {
            // Sentinelle : la ligne de référence du profil Gérante (seedée par la baseline, toujours
            // présente) sérialise les mises en service quel que soit le niveau d'isolation. Un verrou sur
            // la table clinics vide reposerait sur les verrous d'intervalle de REPEATABLE READ (interblocage)
            // et ne protégerait rien en READ COMMITTED. La vérification suivante est une lecture verrouillante :
            // elle voit toujours la dernière version validée.
            DB::table('roles')->where('code', StaffRoles::MANAGER)->lockForUpdate()->first();
            if (DB::table('clinics')->lockForUpdate()->exists()) {
                throw new BusinessError('CLINIC_ALREADY_SET_UP', 'Un cabinet est déjà installé : la mise en service initiale est terminée.', 409);
            }

            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            $clinicId = Ulid::generate();
            $userId = Ulid::generate();

            DB::table('clinics')->insert([
                'id' => $clinicId,
                'name' => trim((string) $data['clinic_name']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')->insert([
                'id' => $userId,
                'clinic_id' => $clinicId,
                'display_name' => trim((string) $data['manager_name']),
                'login' => (string) $data['login'],
                'email' => isset($data['email']) ? (string) $data['email'] : null,
                'password_hash' => $passwordHash,
                'active' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            StaffRoles::sync($clinicId, $userId, [StaffRoles::MANAGER], $userId);

            /** @var User $manager */
            $manager = User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
            $this->audit->record($clinicId, $manager, 'clinic.setup', 'clinics', $clinicId, 'Mise en service initiale');
            $this->audit->record($clinicId, $manager, 'staff.created', 'users', $userId, null, [
                'login' => (string) $data['login'],
                'roles' => [StaffRoles::MANAGER],
            ]);

            return ['clinic_id' => $clinicId, 'user_id' => $userId];
        }, 3);
    }
}
