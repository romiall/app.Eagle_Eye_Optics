<?php

namespace App\Domain\Access\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\StaffAdministration;
use App\Domain\Access\StaffRoles;
use App\Domain\Shared\CommandContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Création d'un compte nominatif par une gérante (corps de l'action CreateStaffMember, exécuté dans la
 * transaction du chemin central). L'identifiant du compte est fourni par la commande, comme pour toute entité
 * créée par une commande : il est connu avant le commit. Le mot de passe arrive déjà haché (calcul bcrypt hors
 * transaction : le verrou du cabinet n'est jamais tenu pendant un hachage).
 */
final class CreateStaffMemberHandler implements CommandHandler
{
    public const COMMAND = 'CreateStaffMember';

    public function __construct(private readonly StaffAdministration $administration) {}

    public function permissions(): array
    {
        return [StaffAdministration::MANAGE_PERMISSION];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $actor = $this->administration->authorizeLocked($context->user ?? throw new LogicException('Compte connecté requis.'));
        $clinicId = $context->clinicId;
        $id = (string) $command->entityId;
        $data = $command->payload;
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        DB::table('users')->insert([
            'id' => $id,
            'clinic_id' => $clinicId,
            'display_name' => trim((string) $data['display_name']),
            'login' => (string) $data['login'],
            'email' => isset($data['email']) ? (string) $data['email'] : null,
            'password_hash' => (string) $data['password_hash'],
            'active' => true,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var list<string> $roles */
        $roles = array_values(array_map('strval', (array) $data['roles']));
        [$added] = StaffRoles::sync($clinicId, $id, $roles, $actor->id);

        return new CommandOutcome(
            result: ['user_id' => $id, 'version' => 1],
            resultType: 'users',
            resultId: $id,
            audit: [new AuditEntry('staff.created', 'users', $id, null, [
                'login' => (string) $data['login'],
                'display_name' => trim((string) $data['display_name']),
                'roles' => $added,
            ])],
        );
    }
}
