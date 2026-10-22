<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Clinical\ConsultationDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationSummaryPresenter;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * Liste des examens du cabinet (écran « Consultations »), lecture seule : `clinical.read`, module
 * « consultations ». Aucune valeur clinique dans la liste ; l'ouverture d'un dossier passe par
 * GET /consultations/{id}, qui est tracée dans l'audit. Débit limité par compte comme les lectures de dossier.
 */
final class ConsultationListController extends Controller
{
    public function index(Request $request, ConsultationDirectory $directory): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }
        /** @var Clinic $clinic */
        $clinic = Clinic::query()->findOrFail($actor->clinic_id);

        $page = $directory(
            $actor->clinic_id,
            $clinic->timezone,
            (string) $actor->id,
            $request->query('date'),
            $request->query('status'),
            $request->query('mine'),
            $request->query('page'),
        );

        return new JsonResponse([
            'data' => array_map(static fn (stdClass $row): array => ConsultationSummaryPresenter::summary($row), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
