<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Access\StaffSessions;
use App\Domain\Shared\BusinessError;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Changement de son propre mot de passe : mot de passe actuel exigé, politique de mot de passe, version
 * du compte incrémentée, autres sessions de ce compte fermées (la session courante reste ouverte).
 */
final class ChangeOwnPassword
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly StaffSessions $sessions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $user, array $input, ?string $currentSessionId): void
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'max:200'],
            'password' => [...PasswordPolicy::rules($user->login), 'confirmed'],
        ])->validate();

        if (! Hash::check((string) $data['current_password'], $user->password_hash)) {
            throw BusinessError::validation(['current_password' => ['Mot de passe actuel incorrect.']]);
        }

        DB::transaction(function () use ($user, $data, $currentSessionId): void {
            $updated = DB::table('users')
                ->where('clinic_id', $user->clinic_id)
                ->where('id', $user->id)
                ->where('version', $user->version)
                ->update([
                    'password_hash' => Hash::make((string) $data['password']),
                    'version' => $user->version + 1,
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);

            if ($updated !== 1) {
                throw BusinessError::versionConflict();
            }

            $this->audit->record($user->clinic_id, $user, 'auth.password_changed', 'users', $user->id);
            $this->sessions->closeAllExcept($user->id, $currentSessionId);
        });
    }
}
