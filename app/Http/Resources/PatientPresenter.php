<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation d'une fiche patient pour l'API (schemas/resources/patients.json) : identité administrative
 * seulement, jamais de donnée clinique. Les instants sont rendus en UTC, les dates civiles telles quelles.
 */
final class PatientPresenter
{
    /**
     * Fiche en liste (`PatientSummary`).
     *
     * @return array<string, mixed>
     */
    public static function summary(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'record_number' => $row->record_number,
            'last_name' => $row->last_name,
            'first_names' => $row->first_names,
            'sex' => $row->sex,
            'birth_date' => $row->birth_date,
            'phone_primary' => $row->phone_primary,
            'archived' => $row->archived_at !== null,
            'merged_into_patient_id' => $row->merged_into_patient_id,
            'version' => (int) $row->version,
        ];
    }

    /**
     * Fiche complète (`Patient`).
     *
     * @return array<string, mixed>
     */
    public static function patient(stdClass $row): array
    {
        $guardian = $row->guardian_contact;

        return self::summary($row) + [
            'birth_date_missing_reason' => $row->birth_date_missing_reason,
            'phone_secondary' => $row->phone_secondary,
            'phone_missing_reason' => $row->phone_missing_reason,
            'address' => $row->address,
            'profession' => $row->profession,
            // Objet JSON (jamais `[]` pour un objet vide) ou null.
            'guardian_contact' => is_string($guardian) ? json_decode($guardian, false, 512, JSON_THROW_ON_ERROR) : null,
            'legacy_record' => (bool) $row->legacy_record,
            'first_known_visit_on' => $row->first_known_visit_on,
            'first_visit_certified' => (bool) $row->first_visit_certified,
            'legacy_record_number' => $row->legacy_record_number,
            'created_at' => self::instant((string) $row->created_at),
            'updated_at' => self::instant((string) $row->updated_at),
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
