<?php

namespace App\Domain\Scheduling\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;

/**
 * Création (version attendue null) ou modification (version de la ressource attendue, 409 sinon) d'une ressource de
 * l'agenda (S01) : professionnel ou salle réservable, pas d'agenda en minutes, active ou non. Le professionnel désigné
 * est un compte ACTIF du cabinet. Aucun horaire n'est créé ici : les créneaux hebdomadaires se saisissent à part
 * (`ReplaceOpeningHours`), sans valeur par défaut inventée.
 *
 * Gérante seulement : faute de droit dédié dans le référentiel, la configuration de l'agenda suit `users.manage`
 * (hypothèse documentée dans docs/implementation/agenda.md). En ligne seulement.
 */
final class SaveSchedulingResourceHandler implements CommandHandler
{
    public const COMMAND = 'SaveSchedulingResource';

    public function permissions(): array
    {
        return ['users.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La configuration de l\'agenda se fait en ligne.');
        }
        $payload = $command->payload;
        $resourceId = (string) $payload['resource_id'];
        $errors = [];

        $name = SchedulingInput::text($payload['name'] ?? null, 180, 'name', $errors);
        if (! isset($errors['name']) && ($name === null || mb_strlen($name) < 2)) {
            $errors['name'][] = 'Le nom de la ressource est obligatoire (au moins 2 caractères).';
        }
        $professional = $payload['professional_user_id'] ?? null;
        if ($professional !== null && ! DB::table('users')->where('clinic_id', $context->clinicId)->where('id', $professional)->where('active', true)->exists()) {
            $errors['professional_user_id'][] = 'Ce compte n\'est pas un compte actif du cabinet.';
        }
        $slot = (int) $payload['slot_duration_minutes'];
        $active = (bool) $payload['active'];

        $now = $context->now->utc();
        $stamp = SchedulingInput::stamp($now);

        if ($command->expectedVersion === null) {
            if (DB::table('scheduling_resources')->where('id', $resourceId)->exists()) {
                $errors['resource_id'][] = 'Cet identifiant de ressource est déjà utilisé.';
            }
            SchedulingInput::throwIfAny($errors);
            DB::table('scheduling_resources')->insert([
                'id' => $resourceId,
                'clinic_id' => $context->clinicId,
                'name' => $name,
                'professional_user_id' => $professional,
                'active' => $active,
                'slot_duration_minutes' => $slot,
                'version' => 1,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            return new CommandOutcome(
                result: ['resource_id' => $resourceId, 'version' => 1],
                resultType: 'scheduling_resources',
                resultId: $resourceId,
                audit: [new AuditEntry('agenda.resource_created', 'scheduling_resources', $resourceId, null, [
                    'slot_duration_minutes' => $slot, 'active' => $active,
                ])],
                changes: [new ChangeRecord('scheduling_resources', $resourceId, 1, ChangeVisibility::Administrative, ChangeKind::Created)],
            );
        }

        $resource = $locks->schedulingResource($resourceId);
        if ($resource === null) {
            throw BusinessError::notFound('Ressource de l\'agenda introuvable dans ce cabinet.');
        }
        if ((int) $resource->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'La ressource a changé depuis l\'affichage : actualisez avant de la modifier.', 409, [], null, [], ['current_version' => (int) $resource->version]);
        }
        SchedulingInput::throwIfAny($errors);

        $version = (int) $resource->version + 1;
        DB::table('scheduling_resources')->where('clinic_id', $context->clinicId)->where('id', $resourceId)->update([
            'name' => $name,
            'professional_user_id' => $professional,
            'active' => $active,
            'slot_duration_minutes' => $slot,
            'version' => $version,
            'updated_at' => $stamp,
        ]);

        return new CommandOutcome(
            result: ['resource_id' => $resourceId, 'version' => $version],
            resultType: 'scheduling_resources',
            resultId: $resourceId,
            audit: [new AuditEntry('agenda.resource_updated', 'scheduling_resources', $resourceId, null, [
                'before' => ['slot_duration_minutes' => (int) $resource->slot_duration_minutes, 'active' => (bool) $resource->active],
                'after' => ['slot_duration_minutes' => $slot, 'active' => $active],
                'version' => $version,
            ])],
            changes: [new ChangeRecord('scheduling_resources', $resourceId, $version, ChangeVisibility::Administrative, ChangeKind::Updated)],
        );
    }
}
