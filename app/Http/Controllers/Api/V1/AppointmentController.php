<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Scheduling\AgendaReader;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\SchedulingPresenter;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * Rendez-vous (S01), module `agenda` : liste d'une période de jours civils du cabinet (`agenda.read`), prise de
 * rendez-vous, déplacement, annulation et absence constatée (`agenda.write`), toutes par le chemin central. Une
 * réponse rend le rendez-vous relu après le commit : il n'est « confirmé » qu'à ce moment-là.
 */
final class AppointmentController extends CommandController
{
    public function index(Request $request, AgendaReader $reader): JsonResponse
    {
        $actor = $this->actor($request);
        $errors = [];
        $from = SchedulingInput::date($request->query('from'), 'from', $errors);
        $to = SchedulingInput::date($request->query('to'), 'to', $errors);
        if ($from !== null && $to !== null) {
            if ($to->compare($from) < 0) {
                $errors['to'][] = 'La fin de la période précède son début.';
            } elseif ($to->dayNumber() - $from->dayNumber() + 1 > AgendaReader::MAX_APPOINTMENT_DAYS) {
                $errors['to'][] = 'La période couvre au plus '.AgendaReader::MAX_APPOINTMENT_DAYS.' jours.';
            }
        }
        $resourceId = $request->query('resource_id');
        $patientId = $request->query('patient_id');
        foreach (['resource_id' => $resourceId, 'patient_id' => $patientId] as $field => $value) {
            if ($value !== null && (! is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1)) {
                $errors[$field][] = 'Identifiant invalide.';
            }
        }
        SchedulingInput::throwIfAny($errors);
        assert($from !== null && $to !== null);
        $timezone = (string) Clinic::query()->findOrFail($actor->clinic_id)->timezone;

        $rows = $reader->appointments(
            $actor->clinic_id,
            $timezone,
            $from,
            $to,
            is_string($resourceId) ? $resourceId : null,
            is_string($patientId) ? $patientId : null,
        );

        return new JsonResponse(['data' => array_map(static fn (stdClass $row): array => SchedulingPresenter::appointment($row), $rows)]);
    }

    public function store(Request $request, AgendaReader $reader): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);
        if ($expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création.']]);
        }
        $id = is_string($payload['appointment_id'] ?? null) ? $payload['appointment_id'] : null;
        $result = $this->dispatchCommand($request, new Command('CreateAppointment', $payload, $id, null));

        return new JsonResponse(['data' => $this->appointmentData($request, (string) $result->resultId, $reader)], 201);
    }

    public function reschedule(Request $request, AgendaReader $reader, string $id): JsonResponse
    {
        return $this->transition($request, $reader, 'RescheduleAppointment', $id);
    }

    public function cancel(Request $request, AgendaReader $reader, string $id): JsonResponse
    {
        return $this->transition($request, $reader, 'CancelAppointment', $id);
    }

    public function noShow(Request $request, AgendaReader $reader, string $id): JsonResponse
    {
        return $this->transition($request, $reader, 'MarkAppointmentNoShow', $id);
    }

    private function transition(Request $request, AgendaReader $reader, string $command, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected === null) {
            throw BusinessError::validation(['expected_version' => ['Champ obligatoire (version du rendez-vous).']]);
        }
        $this->dispatchCommand($request, new Command($command, $payload, $id, $expected));

        return new JsonResponse(['data' => $this->appointmentData($request, $id, $reader)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentData(Request $request, string $id, AgendaReader $reader): array
    {
        $row = $reader->appointment($this->actor($request)->clinic_id, $id);
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return SchedulingPresenter::appointment($row);
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
