<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Clinical\ActivityReports;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statistiques du dossier patient (R02), module `reports` : deux lectures séparées par leurs droits (middlewares des
 * routes) — l'activité administrative (`statistics.activity`, gérante et accueil) et les statistiques cliniques
 * (`statistics.clinical`, gérante et professionnel). Comptages seulement, limités au cabinet de la personne
 * connectée, sur une période de jours civils du cabinet (`from`, `to`).
 */
final class StatisticsController extends Controller
{
    public function activity(Request $request, ActivityReports $reports): JsonResponse
    {
        [$clinicId, $timezone] = $this->clinic($request);
        [$from, $to] = ActivityReports::period($request->query('from'), $request->query('to'));

        return new JsonResponse(['data' => $reports->activity($clinicId, $timezone, $from, $to)]);
    }

    public function clinical(Request $request, ActivityReports $reports): JsonResponse
    {
        [$clinicId, $timezone] = $this->clinic($request);
        [$from, $to] = ActivityReports::period($request->query('from'), $request->query('to'));

        return new JsonResponse(['data' => $reports->clinical($clinicId, $timezone, $from, $to)]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function clinic(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
        $clinic = Clinic::query()->findOrFail($user->clinic_id);

        return [(string) $user->clinic_id, (string) $clinic->timezone];
    }
}
