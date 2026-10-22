<?php

namespace App\Http\Resources;

use App\Domain\Patients\Rules\VisitStatus;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation d'une visite pour l'API (schemas/resources/patients.json#/$defs/Visit) : administratif seulement,
 * l'identité de base du patient et le marqueur du contrôle de paiement de l'examen. Jamais de donnée clinique.
 */
final class VisitPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function visit(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'patient_id' => $row->patient_id,
            'reason' => $row->reason,
            'occurred_at' => self::instant((string) $row->occurred_at),
            'status' => $row->status,
            'appointment_id' => $row->appointment_id,
            'exam_invoice_id' => $row->exam_invoice_id,
            'exam_waiver_approval_id' => $row->exam_waiver_approval_id,
            'arrival_at' => $row->arrival_at === null ? null : self::instant((string) $row->arrival_at),
            'closed_at' => $row->closed_at === null ? null : self::instant((string) $row->closed_at),
            'administrative_note' => $row->administrative_note,
            'version' => (int) $row->version,
            'patient' => [
                'id' => $row->patient_id,
                'record_number' => $row->patient_record_number,
                'last_name' => $row->patient_last_name,
                'first_names' => $row->patient_first_names,
                'sex' => $row->patient_sex,
                'birth_date' => $row->patient_birth_date,
            ],
            // Tant que B04 et B06 n'existent pas, le paiement de l'examen n'est contrôlé nulle part.
            'exam_fee_control' => VisitStatus::examFeeControl((string) $row->reason),
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
