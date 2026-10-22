<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Clinical\VisualAcuityScales;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Échelles d'acuité visuelle du cabinet (C02) : référentiel de lecture, réservé à `clinical.read` (l'écran de
 * consultation y choisit l'échelle de chaque valeur). Aucune conversion entre échelles n'est rendue ni présumée.
 */
final class VisualAcuityScaleController extends Controller
{
    public function index(Request $request, VisualAcuityScales $scales): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return new JsonResponse(['data' => $scales->all((string) $user->clinic_id)]);
    }
}
