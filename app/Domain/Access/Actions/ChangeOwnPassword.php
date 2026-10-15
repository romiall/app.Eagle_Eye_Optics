<?php

namespace App\Domain\Access\Actions;

use App\Application\TransactionRunner;
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
 *
 * Un échec sur le mot de passe actuel est journalisé ; la route partage la limite de débit de la
 * réauthentification (même compte), pour qu'une session laissée ouverte ne permette pas de deviner le
 * mot de passe sans limite.
 */
final class ChangeOwnPassword
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly StaffSessions $sessions,
        private readonly TransactionRunner $transactions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $user, #[\SensitiveParameter] array $input, ?string $currentSessionId): void
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'max:200'],
            'password' => [...PasswordPolicy::rules($user->login), 'confirmed'],
        ])->validate();

        if (! Hash::check((string) $data['current_password'], $user->password_hash)) {
            $this->audit->record($user->clinic_id, $user, 'auth.password_change_failed', 'users', $user->id);

            throw BusinessError::validation(['current_password' => ['Mot de passe actuel incorrect.']]);
        }

        $passwordHash = Hash::make((string) $data['password']);

        // Ordre des verrous du contrat 23 : cabinet (partagé) puis compte, comme l'administration des
        // comptes (verrou exclusif du cabinet puis compte) ; au plus trois tentatives sur interblocage.
        $this->transactions->run(function () use ($user, $passwordHash, $currentSessionId): void {
            DB::table('clinics')->where('id', $user->clinic_id)->sharedLock()->first();
            $updated = DB::table('users')
                ->where('clinic_id', $user->clinic_id)
                ->where('id', $user->id)
                ->where('version', $user->version)
                ->update([
                    'password_hash' => $passwordHash,
                    'version' => $user->version + 1,
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);

            if ($updated !== 1) {
                throw BusinessError::versionConflict();
            }

            $this->audit->record($user->clinic_id, $user, 'auth.password_changed', 'users', $user->id);
            $this->sessions->closeAllExcept($user->id, $currentSessionId);
        });

        // Le garde de session tient cette instance : AuthenticateSession enregistre en fin de requête
        // l'empreinte du hash qu'elle porte. Sans relecture, l'ancienne empreinte resterait en session et
        // la requête suivante fermerait la session courante.
        $user->refresh();
    }
}
