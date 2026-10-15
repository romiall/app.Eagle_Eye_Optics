<?php

namespace App\Domain\Access\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Access\StaffAdministration;
use App\Domain\Access\StaffRoles;
use App\Domain\Access\StaffSessions;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Modification d'un compte par une gérante : nom, courriel, profils, activation (corps de l'action
 * UpdateStaffMember, exécuté dans la transaction du chemin central, sous le verrou du cabinet).
 *
 * Une désactivation ferme immédiatement toutes les sessions serveur du compte. Le cabinet garde toujours au
 * moins une gérante active capable de gérer les comptes, une gérante ne se désactive pas elle-même et personne
 * ne modifie ses propres profils (pas d'auto-attribution ni d'auto-rétrogradation qui contournerait le contrôle
 * d'une autre gérante).
 */
final class UpdateStaffMemberHandler implements CommandHandler
{
    public const COMMAND = 'UpdateStaffMember';

    public function __construct(
        private readonly StaffSessions $sessions,
        private readonly EffectivePermissions $permissions,
        private readonly StaffAdministration $administration,
    ) {}

    public function permissions(): array
    {
        return [StaffAdministration::MANAGE_PERMISSION];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks): CommandOutcome
    {
        $actor = $this->administration->authorizeLocked($context->user ?? throw new LogicException('Compte connecté requis.'));
        $clinicId = $context->clinicId;
        $userId = (string) $command->entityId;
        $data = $command->payload;

        /** @var object{id: string, display_name: string, email: ?string, active: int|bool, version: int}|null $target */
        $target = DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->lockForUpdate()->first();
        if ($target === null) {
            throw BusinessError::notFound();
        }
        if ((int) $target->version !== $command->expectedVersion) {
            throw BusinessError::versionConflict();
        }

        $diff = [];
        $changes = [];
        foreach (['display_name', 'email'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $target->{$field}) {
                $value = $data[$field] === null ? null : trim((string) $data[$field]);
                $changes[$field] = $value;
                $diff[$field] = ['avant' => $target->{$field}, 'apres' => $value];
            }
        }

        $deactivating = array_key_exists('active', $data) && ! (bool) $data['active'] && (bool) $target->active;
        $reactivating = array_key_exists('active', $data) && (bool) $data['active'] && ! (bool) $target->active;

        /** @var list<string>|null $wantedRoles */
        $wantedRoles = array_key_exists('roles', $data) ? array_values(array_map('strval', (array) $data['roles'])) : null;
        $currentRoles = StaffRoles::activeRoles($clinicId, $userId);
        $losingManager = $wantedRoles !== null && in_array(StaffRoles::MANAGER, $currentRoles, true) && ! in_array(StaffRoles::MANAGER, $wantedRoles, true);
        $removingRoles = $wantedRoles !== null && array_diff($currentRoles, $wantedRoles) !== [];

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
        if (($deactivating || $removingRoles) && $reason === '') {
            throw BusinessError::validation(['reason' => ['Le motif est obligatoire pour désactiver un compte ou retirer un profil.']]);
        }
        if ($deactivating && $userId === $actor->id) {
            throw new BusinessError('SELF_DEACTIVATION_FORBIDDEN', 'Une gérante ne peut pas désactiver son propre compte.', 409);
        }
        if ($wantedRoles !== null && $userId === $actor->id && self::sorted($wantedRoles) !== $currentRoles) {
            throw new BusinessError('SELF_ROLE_CHANGE_FORBIDDEN', 'Vos propres profils sont modifiés par une autre gérante.', 409);
        }
        if (($deactivating || $losingManager) && in_array(StaffRoles::MANAGER, $currentRoles, true)) {
            StaffRoles::assertAnotherActiveManager($clinicId, $userId);
        }

        if ($deactivating || $reactivating) {
            $changes['active'] = $reactivating;
            $diff['active'] = ['avant' => (bool) $target->active, 'apres' => $reactivating];
        }

        if ($wantedRoles !== null) {
            [$added, $revoked] = StaffRoles::sync($clinicId, $userId, $wantedRoles, $actor->id);
            if ($added !== [] || $revoked !== []) {
                $diff['roles'] = ['ajoutes' => $added, 'revoques' => $revoked];
            }
        }

        if ($diff === []) {
            return new CommandOutcome(['user_id' => $userId, 'version' => (int) $target->version], 'users', $userId);
        }

        $version = (int) $target->version + 1;
        $changes['version'] = $version;
        $changes['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->update($changes);

        if ($deactivating) {
            $this->sessions->closeAll($userId);
        }

        /** @var User $updated */
        $updated = User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
        $this->permissions->forget($updated);

        return new CommandOutcome(
            result: ['user_id' => $userId, 'version' => $version],
            resultType: 'users',
            resultId: $userId,
            audit: [new AuditEntry($deactivating ? 'staff.deactivated' : 'staff.updated', 'users', $userId, $reason !== '' ? $reason : null, $diff)],
        );
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private static function sorted(array $roles): array
    {
        sort($roles);

        return $roles;
    }
}
