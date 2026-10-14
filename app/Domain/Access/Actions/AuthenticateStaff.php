<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\ClinicContext;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Shared\BusinessError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Connexion d'un membre du personnel dans le cabinet de l'installation (fichiers 09 et 23).
 *
 * - Recherche par identifiant dans le seul cabinet configuré côté serveur.
 * - Réponse identique pour identifiant inconnu, mot de passe faux ou compte désactivé (pas
 *   d'énumération), avec un temps de calcul comparable (hash factice).
 * - Cinq échecs par minute pour un même identifiant et une même adresse, puis attente.
 * - Chaque tentative est journalisée dans l'audit, sans le mot de passe ni l'identifiant en clair.
 */
final class AuthenticateStaff
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly ClinicContext $clinics,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(string $login, string $password, string $ipAddress): User
    {
        $clinicId = $this->clinics->configuredClinicId();
        $normalized = PasswordPolicy::normalizeLogin($login);
        $throttleKey = 'login:'.hash('sha256', $clinicId.'|'.$normalized).'|'.$ipAddress;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException(
                'Trop de tentatives.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($throttleKey)],
            );
        }

        /** @var User|null $user */
        $user = User::query()->where('clinic_id', $clinicId)->where('login', $normalized)->first();

        // Hash factice si l'identifiant est inconnu : même coût de calcul, pas d'énumération par le temps.
        $passwordOk = Hash::check($password, $user !== null ? $user->password_hash : self::dummyHash());

        if ($user === null || ! $passwordOk || ! $user->active) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            $this->audit->record(
                $clinicId,
                $user,
                $user !== null && $passwordOk ? 'auth.login_refused_inactive' : 'auth.login_failed',
                'users',
                $user?->id,
                null,
                ['login_sha256' => hash('sha256', $normalized)],
                'Compte inconnu',
            );

            throw BusinessError::validation(['login' => ['Identifiant ou mot de passe incorrect.']], 'Identifiant ou mot de passe incorrect.');
        }

        RateLimiter::clear($throttleKey);

        DB::transaction(function () use ($user, $password, $clinicId): void {
            $changes = ['last_login_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')];
            if (Hash::needsRehash($user->password_hash)) {
                $changes['password_hash'] = Hash::make($password);
            }
            // Connexion : pas d'incrément de version (ce n'est pas une modification du compte).
            DB::table('users')->where('clinic_id', $clinicId)->where('id', $user->id)->update($changes);
            $this->audit->record($clinicId, $user, 'auth.login', 'users', $user->id);
        });

        return $user->refresh();
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('valeur-factice-jamais-utilisee-comme-mot-de-passe');
    }
}
