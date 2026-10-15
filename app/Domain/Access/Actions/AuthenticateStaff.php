<?php

namespace App\Domain\Access\Actions;

use App\Application\TransactionRunner;
use App\Domain\Access\AuditRecorder;
use App\Domain\Access\ClinicContext;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Shared\BusinessError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Connexion d'un membre du personnel dans le cabinet de l'installation (fichiers 09 et 23).
 *
 * - Recherche par identifiant dans le seul cabinet configuré côté serveur ; un identifiant hors du format
 *   des comptes (CreateStaffMember::LOGIN_PATTERN) est refusé sans recherche : la collation de la colonne
 *   (accents, caractères invisibles ou pleine chasse ignorés) ne fournit pas de variantes illimitées d'un
 *   même compte pour contourner la limite.
 * - Réponse identique pour identifiant inconnu, mot de passe faux ou compte désactivé (pas
 *   d'énumération), avec un seul calcul de hash dans tous les cas (hash factice constant, précalculé).
 * - Limites : 5 essais par minute pour un identifiant depuis une adresse, 10 par dix minutes pour un
 *   identifiant toutes adresses confondues. Le compteur est incrémenté avant la vérification (opération
 *   atomique du cache) : des requêtes parallèles ne dépassent pas la limite. Une connexion réussie remet
 *   les compteurs de l'identifiant à zéro.
 * - Chaque tentative est journalisée dans l'audit, sans le mot de passe ni l'identifiant en clair ; un
 *   échec n'a pas d'auteur (tentative non authentifiée) et ne porte qu'une empreinte HMAC de l'identifiant.
 */
final class AuthenticateStaff
{
    private const ADDRESS_MAX_ATTEMPTS = 5;

    private const ADDRESS_DECAY_SECONDS = 60;

    private const ACCOUNT_MAX_ATTEMPTS = 10;

    private const ACCOUNT_DECAY_SECONDS = 600;

    private const UNAUTHENTICATED_ACTOR = 'Tentative de connexion non authentifiée';

    public function __construct(
        private readonly ClinicContext $clinics,
        private readonly AuditRecorder $audit,
        private readonly TransactionRunner $transactions,
    ) {}

    public function __invoke(string $login, #[\SensitiveParameter] string $password, string $ipAddress): User
    {
        $clinicId = $this->clinics->configuredClinicId();
        $normalized = PasswordPolicy::normalizeLogin($login);
        $accountKey = 'login-account:'.hash('sha256', $clinicId.'|'.$normalized);
        $addressKey = 'login:'.hash('sha256', $clinicId.'|'.$normalized).'|'.$ipAddress;

        $this->countAttempt($addressKey, self::ADDRESS_MAX_ATTEMPTS, self::ADDRESS_DECAY_SECONDS);
        $this->countAttempt($accountKey, self::ACCOUNT_MAX_ATTEMPTS, self::ACCOUNT_DECAY_SECONDS);

        /** @var User|null $user */
        $user = preg_match(CreateStaffMember::LOGIN_PATTERN, $normalized) === 1
            ? User::query()->where('clinic_id', $clinicId)->where('login', $normalized)->first()
            : null;

        // Un seul calcul de hash, que le compte existe ou non : pas d'énumération par le temps.
        $passwordOk = Hash::check($password, $user !== null ? $user->password_hash : self::dummyHash());

        if ($user === null || ! $passwordOk || ! $user->active) {
            $this->audit->record(
                $clinicId,
                null,
                $user !== null && $passwordOk ? 'auth.login_refused_inactive' : 'auth.login_failed',
                'users',
                $user?->id,
                null,
                ['login_hmac' => hash_hmac('sha256', $normalized, self::auditKey())],
                self::UNAUTHENTICATED_ACTOR,
            );

            throw BusinessError::validation(['login' => ['Identifiant ou mot de passe incorrect.']], 'Identifiant ou mot de passe incorrect.');
        }

        RateLimiter::clear($addressKey);
        RateLimiter::clear($accountKey);

        $rehash = Hash::needsRehash($user->password_hash) ? Hash::make($password) : null;

        // Ordre des verrous du contrat 23 : cabinet d'abord (partagé : les connexions ne se bloquent pas
        // entre elles), puis la ligne du compte. Sans cela, l'UPDATE de users puis l'INSERT d'audit (clé
        // étrangère vers clinics) verrouillent dans l'ordre inverse de l'administration des comptes :
        // interblocage. Au plus trois tentatives sur interblocage, sans effet partiel (TransactionRunner).
        $this->transactions->run(function () use ($user, $rehash, $clinicId): void {
            DB::table('clinics')->where('id', $clinicId)->sharedLock()->first();
            $changes = ['last_login_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')];
            if ($rehash !== null) {
                $changes['password_hash'] = $rehash;
            }
            // Connexion : pas d'incrément de version (ce n'est pas une modification du compte).
            DB::table('users')->where('clinic_id', $clinicId)->where('id', $user->id)->update($changes);
            $this->audit->record($clinicId, $user, 'auth.login', 'users', $user->id);
        });

        return $user->refresh();
    }

    private function countAttempt(string $key, int $maxAttempts, int $decaySeconds): void
    {
        if (RateLimiter::hit($key, $decaySeconds) > $maxAttempts) {
            throw new ThrottleRequestsException(
                'Trop de tentatives.',
                null,
                ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))],
            );
        }
    }

    /**
     * Hash factice d'une valeur aléatoire jamais transmise, calculé une fois par configuration de hachage
     * puis conservé dans le cache applicatif : chaque refus coûte exactement une vérification, comme un
     * compte existant (une variable statique serait recalculée à chaque requête sous PHP-FPM).
     */
    private static function dummyHash(): string
    {
        $configuration = hash('sha256', json_encode(config('hashing'), JSON_THROW_ON_ERROR));

        return Cache::rememberForever('eeo:auth:dummy-hash:'.$configuration, static fn (): string => Hash::make(Str::random(40)));
    }

    private static function auditKey(): string
    {
        return 'eeo-audit-login|'.(string) config('app.key');
    }
}
