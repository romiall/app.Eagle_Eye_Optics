<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;

/**
 * Représentation d'une prescription optique pour l'API (schemas/resources/clinical.json#/$defs/Prescription) :
 * données cliniques, réservées aux comptes qui détiennent `clinical.read`. Les instants sont rendus en UTC à la
 * milliseconde ; `updated_at` sert de jeton de concurrence à la modification d'un brouillon.
 */
final class PrescriptionPresenter
{
    /**
     * @param  array<string, mixed>  $view  forme de `OpticalPrescriptions::view`
     * @return array<string, mixed>
     */
    public static function prescription(array $view): array
    {
        return [
            ...$view,
            'validated_at' => $view['validated_at'] === null ? null : self::instant((string) $view['validated_at']),
            'updated_at' => self::instant((string) $view['updated_at']),
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
