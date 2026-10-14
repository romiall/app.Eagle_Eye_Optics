<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Access\StaffAdministration;
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
 *
 * users.manage n'est jamais délégable (fichier 09 : gestion des utilisateurs réservée à la gérante) : une
 * dérogation temporaire suffirait sinon à réinitialiser le mot de passe d'une gérante, à s'attribuer le
 * profil Gérante et à garder le contrôle après expiration ; un DENY sur une gérante pourrait laisser le
 * cabinet sans aucun compte capable de gérer les droits. Seuls l'ajout ou le retrait du profil Gérante,
 * sous l'invariant « au moins une gérante active », modifient ce droit ; CLEAR d'une ligne existante reste
 * possible (retour aux droits du profil).
 */
final class SetStaffPermissionOverride
{
    public const INSTANT_PATTERN = '/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{3}Z\z/';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly EffectivePermissions $permissions,
        private readonly StaffAdministration $administration,
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
            // Instant UTC du contrat (2026-10-16T17:00:00.000Z) : jamais une date civile convertie
            // implicitement à minuit UTC (contrat 23).
            'expires_at' => ['nullable', 'string', 'regex:'.self::INSTANT_PATTERN, 'date', 'after:now'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ], [
            'expires_at.regex' => 'La date d\'expiration est un instant UTC complet (AAAA-MM-JJTHH:MM:SS.mmmZ).',
        ])->validate();

        // CLEAR reste admis : supprimer une dérogation ne peut que revenir aux droits du profil.
        if ($data['permission_code'] === StaffAdministration::MANAGE_PERMISSION && $data['effect'] !== 'CLEAR') {
            throw BusinessError::validation(['permission_code' => ['La gestion des comptes est réservée au profil Gérante : elle ne s\'attribue ni ne se retire par dérogation.']]);
        }

        return DB::transaction(function () use ($actor, $clinicId, $userId, $data): User {
            $actor = $this->administration->lockAndAuthorize($actor);

            // Les dérogations font partie de l'agrégat du compte : version attendue et incrémentée comme pour
            // les profils (verrouillage optimiste, contrat 23).
            $version = DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->lockForUpdate()->value('version');
            if ($version === null) {
                throw BusinessError::notFound();
            }
            if ((int) $version !== (int) $data['expected_version']) {
                throw BusinessError::versionConflict();
            }

            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            $code = (string) $data['permission_code'];
            $existing = DB::table('user_permissions')->where('clinic_id', $clinicId)->where('user_id', $userId)->where('permission_code', $code);
            $expiresAt = isset($data['expires_at']) ? CarbonImmutable::parse((string) $data['expires_at'])->utc()->format('Y-m-d H:i:s.u') : null;
            /** @var object{allowed: int|bool, expires_at: ?string}|null $previous */
            $previous = $existing->clone()->first(['allowed', 'expires_at']);

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

            DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->update([
                'version' => (int) $version + 1,
                'updated_at' => $now,
            ]);

            $this->audit->record($clinicId, $actor, 'staff.permission_override', 'users', $userId, trim((string) $data['reason']), [
                'permission' => $code,
                'effet' => (string) $data['effect'],
                'expire_le' => $expiresAt,
                'avant' => $previous === null ? null : [
                    'effet' => (bool) $previous->allowed ? 'ALLOW' : 'DENY',
                    'expire_le' => $previous->expires_at,
                ],
            ]);

            /** @var User $target */
            $target = User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
            $this->permissions->forget($target);

            return $target;
        }, 3);
    }
}
