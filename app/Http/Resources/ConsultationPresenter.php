<?php

namespace App\Http\Resources;

use App\Domain\Clinical\ConsultationView;
use Carbon\CarbonImmutable;

/**
 * Représentation d'une consultation pour l'API (schemas/resources/clinical.json#/$defs/Consultation) : données
 * cliniques complètes, réservées aux comptes qui détiennent `clinical.read`. Les instants sont rendus en UTC.
 * Les rubriques, les révisions et le patient sont lus par `ConsultationReader::view` (un seul instantané cohérent) ;
 * ici, seule la mise en forme.
 */
final class ConsultationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function consultation(ConsultationView $view): array
    {
        $row = $view->row;

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
            'patient' => $view->patient,
            'steps' => $view->steps,
            'revisions' => array_map(static fn (array $revision): array => [...$revision, 'recorded_at' => self::instant((string) $revision['recorded_at'])], $view->revisions),
        ];
    }

    /**
     * Révision figée avec son snapshot (`ClinicalRevisionDetail`).
     *
     * @param  array<string, mixed>  $revision
     * @return array<string, mixed>
     */
    public static function revision(array $revision): array
    {
        return [...$revision, 'recorded_at' => self::instant((string) $revision['recorded_at'])];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
