<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Access\StaffRoles;
use App\Domain\Access\StaffSessions;
use App\Domain\Shared\BusinessError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Réinitialisation contrôlée par la gérante (fichier 09, sans e-mail tant qu'aucun fournisseur n'est
 * actif) : nouveau mot de passe transmis en personne, motif obligatoire, toutes les sessions du compte
 * fermées. Pour son propre compte, utiliser POST /auth/password (mot de passe actuel exigé).
 */
final class ResetStaffPassword
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly StaffSessions $sessions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, string $userId, array $input): void
    {
        $clinicId = $actor->clinic_id;

        if ($userId === $actor->id) {
            throw new BusinessError('SELF_RESET_FORBIDDEN', 'Pour votre propre compte, utilisez le changement de mot de passe.', 409);
        }

        $login = DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->value('login');
        if ($login === null) {
            throw BusinessError::notFound();
        }

        $data = Validator::make($input, [
            'password' => [...PasswordPolicy::rules((string) $login), 'confirmed'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ])->validate();

        DB::transaction(function () use ($actor, $clinicId, $userId, $data): void {
            StaffRoles::lockClinic($clinicId);

            $version = DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->lockForUpdate()->value('version');
            DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->update([
                'password_hash' => Hash::make((string) $data['password']),
                'version' => (int) $version + 1,
                'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            $this->audit->record($clinicId, $actor, 'staff.password_reset', 'users', $userId, trim((string) $data['reason']));
            $this->sessions->closeAll($userId);
        });
    }
}
