<?php

namespace App\Domain\Access\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Access\StaffAdministration;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Dérogation nominative datée et motivée (corps de l'action SetStaffPermissionOverride, exécuté dans la
 * transaction du chemin central). ALLOW ajoute, DENY retire malgré le profil, CLEAR supprime la dérogation
 * (l'historique reste dans l'audit). Les dérogations font partie de l'agrégat du compte : version attendue et
 * incrémentée comme pour les profils (verrouillage optimiste, contrat 23).
 */
final class SetStaffPermissionOverrideHandler implements CommandHandler
{
    public const COMMAND = 'SetStaffPermissionOverride';

    public function __construct(
        private readonly EffectivePermissions $permissions,
        private readonly StaffAdministration $administration,
    ) {}

    public function permissions(): array
    {
        return [StaffAdministration::MANAGE_PERMISSION];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $actor = $this->administration->authorizeLocked($context->user ?? throw new LogicException('Compte connecté requis.'));
        $clinicId = $context->clinicId;
        $userId = (string) $command->entityId;
        $data = $command->payload;

        $version = $locks->account($userId)?->version;
        if ($version === null) {
            throw BusinessError::notFound();
        }
        if ((int) $version !== $command->expectedVersion) {
            throw BusinessError::versionConflict();
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $code = (string) $data['permission_code'];
        $reason = trim((string) $data['reason']);
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
                'reason' => $reason,
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

        /** @var User $target */
        $target = User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
        $this->permissions->forget($target);

        return new CommandOutcome(
            result: ['user_id' => $userId, 'version' => (int) $version + 1],
            resultType: 'users',
            resultId: $userId,
            audit: [new AuditEntry('staff.permission_override', 'users', $userId, $reason, [
                'permission' => $code,
                'effet' => (string) $data['effect'],
                'expire_le' => $expiresAt,
                'avant' => $previous === null ? null : [
                    'effet' => (bool) $previous->allowed ? 'ALLOW' : 'DENY',
                    'expire_le' => $previous->expires_at,
                ],
            ])],
        );
    }
}
