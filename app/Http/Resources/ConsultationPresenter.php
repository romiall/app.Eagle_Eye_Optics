<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation d'une consultation pour l'API (schemas/resources/clinical.json#/$defs/Consultation) : données
 * cliniques complètes, réservées aux comptes qui détiennent `clinical.read`. Les instants sont rendus en UTC.
 * Les rubriques et les révisions sont lues par `ConsultationReader` ; ici, seule la mise en forme.
 */
final class ConsultationPresenter
{
    /**
     * @param  array<string, array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $revisions
     * @return array<string, mixed>
     */
    public static function consultation(stdClass $row, array $steps, array $revisions): array
    {
        return [
            'id' => $row->id,
            'visit_id' => $row->visit_id,
            'patient_id' => $row->patient_id,
            'clinician_id' => $row->clinician_id,
            'effective_at' => self::instant((string) $row->effective_at),
            'date_change_reason' => $row->date_change_reason,
            'status' => $row->status,
            'current_revision' => (int) $row->current_revision,
            'completed_at' => $row->completed_at === null ? null : self::instant((string) $row->completed_at),
            'finalization_missing_data_reason' => $row->finalization_missing_data_reason,
            'version' => (int) $row->version,
            'steps' => $steps,
            'revisions' => array_map(static fn (array $revision): array => [...$revision, 'recorded_at' => self::instant((string) $revision['recorded_at'])], $revisions),
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
