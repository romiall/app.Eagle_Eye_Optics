<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Scheduling\AgendaReader;
use App\Domain\Scheduling\AvailabilityService;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\SchedulingPresenter;
use App\Models\Clinic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Configuration de l'agenda (S01), module `agenda` : lecture par tout compte qui voit l'agenda (`agenda.read`),
 * écritures de la gérante (`users.manage`, middleware des routes) par le chemin central (ressources, créneaux
 * hebdomadaires, exceptions, prestations), et créneaux libres d'un jour pour la prise de rendez-vous.
 */
final class AgendaController extends CommandController
{
    public function configuration(Request $request, AgendaReader $reader): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->configurationData($actor->clinic_id, $reader)]);
    }

    public function storeResource(Request $request, AgendaReader $reader): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if ($expected !== null || ! $present) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création.']]);
        }
        $id = (string) ($payload['resource_id'] ?? '');
        $this->dispatchCommand($request, new Command('SaveSchedulingResource', $payload, $id, null));

        return new JsonResponse(['data' => $this->resourceData($this->actor($request)->clinic_id, $id, $reader)], 201);
    }

    public function updateResource(Request $request, AgendaReader $reader, string $id): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);
        $this->assertBodyId($payload, 'resource_id', $id);
        if ($expected === null) {
            throw BusinessError::validation(['expected_version' => ['Champ obligatoire (version de la ressource).']]);
        }
        $this->dispatchCommand($request, new Command('SaveSchedulingResource', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->resourceData($this->actor($request)->clinic_id, $id, $reader)]);
    }

    public function openingHours(Request $request, AgendaReader $reader, string $id): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);
        $this->dispatchCommand($request, new Command('ReplaceOpeningHours', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->resourceData($this->actor($request)->clinic_id, $id, $reader)]);
    }

    public function exception(Request $request, string $id): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);
        if ($expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null pour une exception.']]);
        }
        $result = $this->dispatchCommand($request, new Command('AddResourceException', $payload, $id, null));
        $row = DB::table('resource_exceptions')
            ->where('clinic_id', $this->actor($request)->clinic_id)
            ->where('id', (string) $result->resultId)
            ->first();
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return new JsonResponse(['data' => SchedulingPresenter::exception($row)], 201);
    }

    public function storeService(Request $request): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if ($expected !== null || ! $present) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création.']]);
        }
        $id = (string) ($payload['service_id'] ?? '');
        $this->dispatchCommand($request, new Command('SaveAppointmentService', $payload, $id, null));

        return new JsonResponse(['data' => $this->serviceData($this->actor($request)->clinic_id, $id)], 201);
    }

    public function updateService(Request $request, string $id): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);
        $this->assertBodyId($payload, 'service_id', $id);
        if ($expected === null) {
            throw BusinessError::validation(['expected_version' => ['Champ obligatoire (version de la prestation).']]);
        }
        $this->dispatchCommand($request, new Command('SaveAppointmentService', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->serviceData($this->actor($request)->clinic_id, $id)]);
    }

    /**
     * Créneaux libres d'un jour pour une ressource et une prestation, lus maintenant : la réservation revérifie
     * sous verrou (un créneau affiché peut être pris entre-temps, 409 `SLOT_UNAVAILABLE`).
     */
    public function slots(Request $request, AvailabilityService $availability): JsonResponse
    {
        $actor = $this->actor($request);
        $errors = [];
        $day = SchedulingInput::date($request->query('date'), 'date', $errors);
        $resourceId = $request->query('resource_id');
        $serviceId = $request->query('service_id');
        $resource = is_string($resourceId) ? DB::table('scheduling_resources')->where('clinic_id', $actor->clinic_id)->where('id', $resourceId)->first() : null;
        $service = is_string($serviceId) ? DB::table('appointment_services')->where('clinic_id', $actor->clinic_id)->where('id', $serviceId)->first() : null;
        if ($resource === null) {
            $errors['resource_id'][] = 'Ressource de l\'agenda introuvable dans ce cabinet.';
        }
        if ($service === null) {
            $errors['service_id'][] = 'Prestation introuvable dans ce cabinet.';
        }
        SchedulingInput::throwIfAny($errors);
        assert($day !== null && $resource !== null && $service !== null);
        $timezone = (string) Clinic::query()->findOrFail($actor->clinic_id)->timezone;

        return new JsonResponse(['data' => [
            'date' => $day->toString(),
            'resource_id' => (string) $resource->id,
            'service_id' => (string) $service->id,
            'duration_minutes' => (int) $service->duration_minutes,
            'slots' => $availability->slots($actor->clinic_id, $timezone, $resource, $service, $day, CarbonImmutable::now('UTC')),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationData(string $clinicId, AgendaReader $reader): array
    {
        $configuration = $reader->configuration($clinicId, CarbonImmutable::now('UTC'));

        return [
            'resources' => array_map(
                static fn (stdClass $row): array => SchedulingPresenter::resource($row, $configuration['hours'][(string) $row->id] ?? []),
                $configuration['resources'],
            ),
            'services' => array_map(static fn (stdClass $row): array => SchedulingPresenter::service($row), $configuration['services']),
            'exceptions' => array_map(static fn (stdClass $row): array => SchedulingPresenter::exception($row), $configuration['exceptions']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceData(string $clinicId, string $id, AgendaReader $reader): array
    {
        $row = $reader->resource($clinicId, $id);
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return SchedulingPresenter::resource($row, $reader->hours($clinicId, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceData(string $clinicId, string $id): array
    {
        $row = DB::table('appointment_services')->where('clinic_id', $clinicId)->where('id', $id)->first();
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return SchedulingPresenter::service($row);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertBodyId(array $payload, string $field, string $id): void
    {
        if (($payload[$field] ?? null) !== $id) {
            throw BusinessError::validation([$field => ['L\'identifiant du corps doit être celui de l\'adresse.']]);
        }
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
