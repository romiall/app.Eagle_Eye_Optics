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
use App\Domain\Scheduling\AvailabilityService;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;

/**
 * Fermeture (`is_available = false`) ou ouverture exceptionnelle (`true`) datée d'une ressource (S01 ; « exceptions »),
 * motif obligatoire, immuable une fois créée (une erreur se corrige par une exception inverse). Une fermeture qui
 * couvrirait des rendez-vous déjà pris est REFUSÉE (409 `SLOT_UNAVAILABLE`) : ils se déplacent ou s'annulent d'abord,
 * jamais en silence. Gérante seulement (`users.manage`), en ligne seulement.
 */
final class AddResourceExceptionHandler implements CommandHandler
{
    public const COMMAND = 'AddResourceException';

    public function permissions(): array
    {
        return ['users.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La configuration de l\'agenda se fait en ligne.');
        }
        $resourceId = (string) $command->entityId;
        $payload = $command->payload;
        $exceptionId = (string) $payload['exception_id'];

        $resource = $locks->schedulingResource($resourceId);
        if ($resource === null) {
            throw BusinessError::notFound('Ressource de l\'agenda introuvable dans ce cabinet.');
        }
        $errors = [];
        $start = SchedulingInput::instant($payload['starts_at'] ?? null, 'starts_at', $errors);
        $end = SchedulingInput::instant($payload['ends_at'] ?? null, 'ends_at', $errors);
        $reason = SchedulingInput::reason($payload['reason'] ?? null, 3, 1000, 'reason', $errors);
        if ($start !== null && $end !== null && ! $end->greaterThan($start)) {
            $errors['ends_at'][] = 'La fin de l\'exception doit suivre son début.';
        }
        if (DB::table('resource_exceptions')->where('id', $exceptionId)->exists()) {
            $errors['exception_id'][] = 'Cet identifiant d\'exception est déjà utilisé.';
        }
        SchedulingInput::throwIfAny($errors);
        assert($start !== null && $end !== null);

        $available = (bool) $payload['is_available'];
        if (! $available) {
            $covered = DB::table('appointments')
                ->where('clinic_id', $context->clinicId)
                ->where('resource_id', $resourceId)
                ->whereIn('status', AvailabilityService::OCCUPYING)
                ->where('starts_at', '<', SchedulingInput::stamp($end))
                ->where('ends_at', '>', SchedulingInput::stamp($start))
                ->count();
            if ($covered > 0) {
                throw new BusinessError(
                    'SLOT_UNAVAILABLE',
                    $covered.' rendez-vous existent sur cette période : déplacez-les ou annulez-les avant de fermer la ressource.',
                    409,
                    [],
                    null,
                    [],
                    ['conflicts' => $covered],
                );
            }
        }

        $stamp = SchedulingInput::stamp($context->now->utc());
        DB::table('resource_exceptions')->insert([
            'id' => $exceptionId,
            'clinic_id' => $context->clinicId,
            'resource_id' => $resourceId,
            'starts_at' => SchedulingInput::stamp($start),
            'ends_at' => SchedulingInput::stamp($end),
            'is_available' => $available,
            'reason' => $reason,
            'created_by' => $context->actorId,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return new CommandOutcome(
            result: ['exception_id' => $exceptionId, 'resource_id' => $resourceId],
            resultType: 'resource_exceptions',
            resultId: $exceptionId,
            audit: [new AuditEntry('agenda.exception_added', 'resource_exceptions', $exceptionId, $reason, [
                'resource_id' => $resourceId, 'is_available' => $available,
            ])],
            changes: [new ChangeRecord('resource_exceptions', $exceptionId, 1, ChangeVisibility::Administrative, ChangeKind::Created)],
        );
    }
}
