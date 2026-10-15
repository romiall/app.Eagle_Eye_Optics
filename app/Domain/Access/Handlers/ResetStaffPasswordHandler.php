<?php

namespace App\Domain\Access\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\StaffAdministration;
use App\Domain\Access\StaffRoles;
use App\Domain\Access\StaffSessions;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Réinitialisation contrôlée du mot de passe par la gérante (corps de l'action ResetStaffPassword, exécuté
 * dans la transaction du chemin central) : motif obligatoire, toutes les sessions du compte fermées. Le mot de
 * passe arrive déjà haché ; l'audit ne porte jamais de secret.
 *
 * Le mot de passe d'une gérante ne se réinitialise pas par l'API : une autre gérante pourrait sinon se
 * connecter sous son nom et signer l'audit à sa place (procédure de secours console journalisée).
 */
final class ResetStaffPasswordHandler implements CommandHandler
{
    public const COMMAND = 'ResetStaffPassword';

    public function __construct(
        private readonly StaffSessions $sessions,
        private readonly StaffAdministration $administration,
    ) {}

    public function permissions(): array
    {
        return [StaffAdministration::MANAGE_PERMISSION];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks): CommandOutcome
    {
        $this->administration->authorizeLocked($context->user ?? throw new LogicException('Compte connecté requis.'));
        $clinicId = $context->clinicId;
        $userId = (string) $command->entityId;
        $data = $command->payload;

        if (in_array(StaffRoles::MANAGER, StaffRoles::activeRoles($clinicId, $userId), true)) {
            throw new BusinessError('MANAGER_RESET_REQUIRES_CONSOLE', 'Le mot de passe d\'une gérante se réinitialise par la procédure de secours sur le serveur.', 409);
        }

        $version = DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->lockForUpdate()->value('version');
        if ((int) $version !== $command->expectedVersion) {
            throw BusinessError::versionConflict();
        }
        DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->update([
            'password_hash' => (string) $data['password_hash'],
            'version' => (int) $version + 1,
            'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        $this->sessions->closeAll($userId);

        return new CommandOutcome(
            result: ['user_id' => $userId, 'version' => (int) $version + 1],
            resultType: 'users',
            resultId: $userId,
            audit: [new AuditEntry('staff.password_reset', 'users', $userId, trim((string) $data['reason']))],
        );
    }
}
