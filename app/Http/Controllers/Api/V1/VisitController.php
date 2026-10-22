<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Billing\BilledExamFeeControl;
use App\Domain\Patients\VisitQueue;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\VisitPresenter;
use App\Models\Clinic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visites du cabinet de la personne connectée (P03) : enregistrement d'une arrivée par le chemin central de
 * commandes et file du jour. Identité administrative seulement ; toujours limitée au cabinet.
 */
final class VisitController extends CommandController
{
    public function __construct(private readonly BilledExamFeeControl $examFee) {}

    public function index(Request $request, VisitQueue $queue): JsonResponse
    {
        $actor = $this->actor($request);
        $clinic = Clinic::query()->findOrFail($actor->clinic_id);

        $page = $queue($actor->clinic_id, $clinic->timezone, CarbonImmutable::now('UTC'), $request->query('date'), $request->query('page'));
        $clinical = $actor->can('clinical.read');

        return new JsonResponse([
            'data' => array_map(fn (\stdClass $row): array => VisitPresenter::visit($row, $clinical, $this->marker($row)), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $expected = $payload['expected_version'] ?? null;
        unset($payload['expected_version']);
        if ($expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création.']]);
        }

        $visitId = is_string($payload['visit_id'] ?? null) ? $payload['visit_id'] : null;
        $result = $this->dispatchCommand($request, new Command('RegisterVisit', $payload, $visitId, null));

        $row = VisitQueue::find($this->actor($request)->clinic_id, (string) $result->resultId);
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return new JsonResponse(['data' => VisitPresenter::visit($row, $this->actor($request)->can('clinical.read'), $this->marker($row))], 201);
    }

    /**
     * Marqueur du contrôle RÉEL du paiement de l'examen (P03), quand il est actif (`EEO_EXAM_FEE_CONTROL=billed`) ; null
     * sinon : la file reste « non contrôlée », exactement comme au premier jalon.
     *
     * @return array{control: string, receipt_number: ?string, status: string}|null
     */
    private function marker(\stdClass $row): ?array
    {
        return BilledExamFeeControl::isActive() ? $this->examFee->queueMarker($row, CarbonImmutable::now('UTC')) : null;
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
