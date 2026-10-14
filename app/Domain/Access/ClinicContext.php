<?php

namespace App\Domain\Access;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Cabinet servi par cette installation, décidé côté serveur (contrat 23 : l'utilisateur ne choisit jamais
 * un clinic_id de confiance). EEO_CLINIC_ID s'il est défini, sinon l'unique cabinet de la base.
 */
final class ClinicContext
{
    public function configuredClinicId(): string
    {
        $configured = config('eeo.clinic_id');

        if (is_string($configured) && $configured !== '') {
            if (! Ulid::isValid($configured) || ! DB::table('clinics')->where('id', $configured)->exists()) {
                throw new BusinessError('CLINIC_NOT_CONFIGURED', 'Le cabinet configuré (EEO_CLINIC_ID) est introuvable.', 503);
            }

            return $configured;
        }

        $ids = DB::table('clinics')->orderBy('id')->limit(2)->pluck('id')->all();

        return match (count($ids)) {
            1 => (string) $ids[0],
            0 => throw new BusinessError('CLINIC_NOT_CONFIGURED', 'Aucun cabinet n\'est installé : lancer « php artisan eeo:setup-clinic ».', 503),
            default => throw new BusinessError('CLINIC_NOT_CONFIGURED', 'Plusieurs cabinets existent : définir EEO_CLINIC_ID.', 503),
        };
    }
}
