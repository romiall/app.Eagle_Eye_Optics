<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Scheduling\AgendaReader;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Instant;
use App\Http\Controllers\CommandController;
use App\Http\Resources\SchedulingPresenter;
use App\Models\Clinic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * Tâches de contact internes (S02), module `agenda` : liste quotidienne (`agenda.read` : retards compris, et les tâches
 * traitées ce jour-là) et tentative de contact (`agenda.write`) par le chemin central. Aucun envoi : la personne qui
 * appelle note le résultat (contacté, RDV pris, sans réponse, refus, reporté).
 */
final class ContactTaskController extends CommandController
{
    public function index(Request $request, AgendaReader $reader): JsonResponse
    {
        $actor = $this->actor($request);
        $timezone = (string) Clinic::query()->findOrFail($actor->clinic_id)->timezone;
        $errors = [];
        $requested = $request->query('due_date');
        $day = $requested === null || $requested === ''
            ? Instant::fromEpochMilliseconds((int) CarbonImmutable::now('UTC')->format('Uv'))->civilDate($timezone)
            : SchedulingInput::date($requested, 'due_date', $errors);
        SchedulingInput::throwIfAny($errors);
        assert($day instanceof CivilDate);

        $rows = $reader->contactTasks($actor->clinic_id, $timezone, $day);
        $attempts = $reader->attempts($actor->clinic_id, array_map(static fn (stdClass $row): string => (string) $row->id, $rows));

        return new JsonResponse(['data' => array_map(
            static fn (stdClass $row): array => SchedulingPresenter::contactTask($row, $attempts[(string) $row->id] ?? []),
            $rows,
        )]);
    }

    public function attempt(Request $request, AgendaReader $reader, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (($payload['task_id'] ?? null) !== $id) {
            throw BusinessError::validation(['task_id' => ['L\'identifiant de la tâche du corps doit être celui de l\'adresse.']]);
        }
        if (! $present || $expected === null) {
            throw BusinessError::validation(['expected_version' => ['Champ obligatoire (version de la tâche).']]);
        }
        $this->dispatchCommand($request, new Command('RecordContactAttempt', $payload, $id, $expected));

        $clinicId = $this->actor($request)->clinic_id;
        $row = $reader->contactTask($clinicId, $id);
        if ($row === null) {
            throw BusinessError::notFound();
        }
        $attempts = $reader->attempts($clinicId, [$id]);

        return new JsonResponse(['data' => [
            'attempt_id' => (string) $payload['attempt_id'],
            'task' => SchedulingPresenter::contactTask($row, $attempts[$id] ?? []),
        ]], 201);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
