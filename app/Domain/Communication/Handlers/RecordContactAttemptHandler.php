<?php

namespace App\Domain\Communication\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Communication\ContactTasks;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;

/**
 * Tentative de contact d'une tâche interne (S02 ; fichier 02 « une liste quotidienne permet contacté, RDV pris, sans
 * réponse, refus et reporté ») : la tentative est historisée (immuable, `contact_attempts`), la tâche prend son
 * prochain état explicite, version de la tâche attendue (409 sinon). Combinaisons admises :
 *
 *   CONTACTED → CONTACTED ; BOOKED → BOOKED (rendez-vous du même patient obligatoire) ; REFUSED → REFUSED ;
 *   NO_RESPONSE → NO_RESPONSE ou POSTPONED ; POSTPONED → POSTPONED ; FAILED → PENDING ou NO_RESPONSE.
 *
 * Reporté exige la date de nouvelle tentative (future) ; un état clos n'en a pas. Une tâche close ne reçoit plus de
 * tentative (409 `STATE_TRANSITION_REFUSED`). Aucun message n'est envoyé : la tentative est faite par une personne.
 * Verrous : cabinet, idempotence, rendez-vous désigné (49), tâche (51).
 */
final class RecordContactAttemptHandler implements CommandHandler
{
    public const COMMAND = 'RecordContactAttempt';

    /** @var array<string, list<string>> */
    public const NEXT = [
        'CONTACTED' => ['CONTACTED'],
        'BOOKED' => ['BOOKED'],
        'REFUSED' => ['REFUSED'],
        'NO_RESPONSE' => ['NO_RESPONSE', 'POSTPONED'],
        'POSTPONED' => ['POSTPONED'],
        'FAILED' => ['PENDING', 'NO_RESPONSE'],
    ];

    public function permissions(): array
    {
        return ['agenda.write'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Une tentative de contact s\'enregistre en ligne dans cette version.');
        }
        $payload = $command->payload;
        $attemptId = (string) $payload['attempt_id'];
        $errors = [];

        $appointmentId = $payload['appointment_id'] ?? null;
        $appointment = is_string($appointmentId) ? $locks->appointment($appointmentId) : null;

        $task = $locks->contactTask((string) $payload['task_id']);
        if ($task === null) {
            throw BusinessError::notFound('Tâche de contact introuvable dans ce cabinet.');
        }
        if ($command->expectedVersion === null || (int) $task->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'La tâche a changé depuis l\'affichage : la liste est relue.', 409, [], null, [], ['current_version' => (int) $task->version]);
        }
        if (! in_array((string) $task->status, ContactTasks::OPEN, true)) {
            throw BusinessError::stateRefused('Cette tâche est déjà traitée.', (string) $task->status);
        }

        $result = (string) $payload['result'];
        $next = (string) $payload['next_status'];
        if (! in_array($next, self::NEXT[$result] ?? [], true)) {
            $errors['next_status'][] = 'Ce résultat ne mène pas à cet état de la tâche.';
        }
        if ($result === 'BOOKED') {
            if (! is_string($appointmentId)) {
                $errors['appointment_id'][] = 'Indiquez le rendez-vous pris.';
            } elseif ($appointment === null) {
                $errors['appointment_id'][] = 'Rendez-vous introuvable dans ce cabinet.';
            } elseif ($appointment->patient_id !== $task->patient_id) {
                $errors['appointment_id'][] = 'Ce rendez-vous concerne un autre patient.';
            } elseif (in_array((string) $appointment->status, ['CANCELLED', 'NO_SHOW'], true)) {
                $errors['appointment_id'][] = 'Ce rendez-vous est annulé ou manqué.';
            }
        } elseif ($appointmentId !== null) {
            $errors['appointment_id'][] = 'Un rendez-vous ne se joint qu\'au résultat « RDV pris ».';
        }

        $now = $context->now->utc();
        $occurred = SchedulingInput::instant($payload['occurred_at'] ?? null, 'occurred_at', $errors);
        $skew = (int) config('eeo.offline.max_clock_skew_seconds', 300);
        if ($occurred !== null && $occurred->greaterThan($now->addSeconds($skew))) {
            $errors['occurred_at'][] = 'La tentative ne peut pas être dans le futur.';
        }
        $nextAttempt = ($payload['next_attempt_at'] ?? null) === null ? null : SchedulingInput::instant($payload['next_attempt_at'], 'next_attempt_at', $errors);
        if ($next === 'POSTPONED' && $nextAttempt === null && ! isset($errors['next_attempt_at'])) {
            $errors['next_attempt_at'][] = 'Indiquez quand rappeler.';
        }
        if ($nextAttempt !== null && ! $nextAttempt->greaterThan($now)) {
            $errors['next_attempt_at'][] = 'La nouvelle tentative est dans le futur.';
        }
        if ($nextAttempt !== null && ! in_array($next, ContactTasks::OPEN, true)) {
            $errors['next_attempt_at'][] = 'Une tâche close n\'a pas de nouvelle tentative.';
        }
        $note = SchedulingInput::text($payload['administrative_note'] ?? null, 4000, 'administrative_note', $errors);
        if (DB::table('contact_attempts')->where('id', $attemptId)->exists()) {
            $errors['attempt_id'][] = 'Cet identifiant de tentative est déjà utilisé.';
        }
        SchedulingInput::throwIfAny($errors);
        assert($occurred !== null);

        $stamp = SchedulingInput::stamp($now);
        DB::table('contact_attempts')->insert([
            'id' => $attemptId,
            'clinic_id' => $context->clinicId,
            'task_id' => (string) $task->id,
            'actor_id' => $context->actorId,
            'occurred_at' => SchedulingInput::stamp($occurred),
            'result' => $result,
            'appointment_id' => is_string($appointmentId) ? $appointmentId : null,
            'administrative_note' => $note,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
        $version = (int) $task->version + 1;
        DB::table('contact_tasks')->where('clinic_id', $context->clinicId)->where('id', $task->id)->update([
            'status' => $next,
            'next_attempt_at' => $nextAttempt === null ? null : SchedulingInput::stamp($nextAttempt),
            'version' => $version,
            'updated_at' => $stamp,
        ]);

        return new CommandOutcome(
            result: ['attempt_id' => $attemptId, 'task_id' => (string) $task->id, 'version' => $version, 'status' => $next],
            resultType: 'contact_tasks',
            resultId: (string) $task->id,
            audit: [new AuditEntry('contact_task.attempt_recorded', 'contact_tasks', (string) $task->id, null, [
                'attempt_id' => $attemptId, 'result' => $result, 'next_status' => $next, 'version' => $version,
            ])],
            changes: [
                new ChangeRecord('contact_attempts', $attemptId, 1, ChangeVisibility::Administrative, ChangeKind::Created),
                new ChangeRecord('contact_tasks', (string) $task->id, $version, ChangeVisibility::Administrative, ChangeKind::Updated),
            ],
        );
    }
}
