<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Actions\UpdateClinicSettings;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paramètres administratifs du cabinet : lecture par tout compte connecté (en-têtes des documents,
 * fuseau d'affichage), modification par la gérante réauthentifiée.
 */
final class ClinicSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => self::present($this->clinic($request))]);
    }

    public function update(Request $request, UpdateClinicSettings $update): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return new JsonResponse(['data' => self::present($update($user, $request->all()))]);
    }

    private function clinic(Request $request): Clinic
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        /** @var Clinic */
        return Clinic::query()->findOrFail($user->clinic_id);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Clinic $clinic): array
    {
        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'timezone' => $clinic->timezone,
            'currency' => $clinic->currency,
            'address' => $clinic->getAttribute('address'),
            'phone' => $clinic->getAttribute('phone'),
            'email' => $clinic->getAttribute('email'),
            'tax_identifier' => $clinic->getAttribute('tax_identifier'),
            'professional_mentions' => $clinic->getAttribute('professional_mentions'),
            'document_settings' => $clinic->getAttribute('document_settings'),
            'production_documents_enabled' => $clinic->production_documents_enabled,
        ];
    }
}
