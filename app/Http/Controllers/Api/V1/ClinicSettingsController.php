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
 * Paramètres administratifs du cabinet, réservés à la gérante (contrat 23) : lecture avec users.manage,
 * modification après réauthentification. Les autres profils reçoivent le nom, le fuseau et la monnaie du
 * cabinet dans GET /auth/me.
 */
final class ClinicSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => self::present($this->clinic($request))]);
    }

    /**
     * Identité du cabinet imprimée sur les documents (en-tête et pied : nom, coordonnées, identifiant fiscal,
     * mentions professionnelles, réglages des documents) : ce qu'un patient lit déjà sur chaque reçu ou
     * ordonnance. Lisible par tout profil qui produit un document (droit d'un document, route), pas seulement
     * par la gérante : sans elle, une ordonnance imprimée par le professionnel n'aurait aucune coordonnée.
     * Les paramètres administratifs complets restent réservés à la gérante (show).
     */
    public function documentIdentity(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        // Objet JSON (contrat) : un objet vide décodé en tableau PHP vide reste un objet, jamais une liste.
        $settings = $clinic->getAttribute('document_settings');
        if ($settings === []) {
            $settings = new \stdClass;
        }

        return new JsonResponse(['data' => [
            'name' => $clinic->name,
            'timezone' => $clinic->timezone,
            'currency' => $clinic->currency,
            'address' => $clinic->getAttribute('address'),
            'phone' => $clinic->getAttribute('phone'),
            'email' => $clinic->getAttribute('email'),
            'tax_identifier' => $clinic->getAttribute('tax_identifier'),
            'professional_mentions' => $clinic->getAttribute('professional_mentions'),
            'document_settings' => $settings,
            'production_documents_enabled' => $clinic->production_documents_enabled,
        ]]);
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
