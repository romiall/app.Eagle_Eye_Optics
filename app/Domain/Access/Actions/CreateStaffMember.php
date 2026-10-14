<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Access\StaffRoles;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Création d'un compte nominatif par une gérante (aucune inscription publique, fichier 09). Le mot de
 * passe initial est fixé par la gérante et transmis en personne ; le compte le change ensuite par
 * POST /auth/password. Profils parmi les quatre des sources, au moins un.
 */
final class CreateStaffMember
{
    public const LOGIN_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,63}\z/';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, array $input): User
    {
        $clinicId = $actor->clinic_id;
        $login = is_string($input['login'] ?? null) ? PasswordPolicy::normalizeLogin($input['login']) : null;

        $data = Validator::make(['login' => $login] + $input, [
            'login' => ['required', 'string', 'regex:'.self::LOGIN_PATTERN, Rule::unique('users', 'login')->where('clinic_id', $clinicId)],
            'display_name' => ['required', 'string', 'min:2', 'max:180'],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->where('clinic_id', $clinicId)],
            'password' => PasswordPolicy::rules($login),
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(StaffRoles::known())],
        ])->validate();

        return DB::transaction(function () use ($actor, $clinicId, $data): User {
            StaffRoles::lockClinic($clinicId);
            $id = Ulid::generate();
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

            DB::table('users')->insert([
                'id' => $id,
                'clinic_id' => $clinicId,
                'display_name' => trim((string) $data['display_name']),
                'login' => (string) $data['login'],
                'email' => isset($data['email']) ? (string) $data['email'] : null,
                'password_hash' => Hash::make((string) $data['password']),
                'active' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var list<string> $roles */
            $roles = array_values(array_map('strval', (array) $data['roles']));
            [$added] = StaffRoles::sync($clinicId, $id, $roles, $actor->id);

            $this->audit->record($clinicId, $actor, 'staff.created', 'users', $id, null, [
                'login' => (string) $data['login'],
                'display_name' => trim((string) $data['display_name']),
                'roles' => $added,
            ]);

            return User::query()->where('clinic_id', $clinicId)->findOrFail($id);
        });
    }
}
