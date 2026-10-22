<?php

namespace App\Http\Resources;

use App\Domain\Patients\Rules\VisitStatus;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation d'une visite pour l'API (schemas/resources/patients.json#/$defs/Visit) : administratif seulement,
 * l'identité de base du patient et le marqueur du contrôle de paiement de l'examen. Jamais de donnée clinique.
 *
 * La consultation de la visite (`consultation_id`, `consultation_status`) n'est rendue qu'à un compte qui détient
 * `clinical.read` : pour tout autre compte (accueil, caisse) les deux champs valent null, même quand la
 * consultation existe. C'est une défense en profondeur sur l'identifiant et le statut CLINIQUE (brouillon, en cours,
 * terminé), pas une dissimulation de l'examen : le statut ADMINISTRATIF de la visite (`status`, par exemple « en
 * consultation » ou « terminée ») reste rendu à tous, car la file du jour le distingue (fichier 02).
 */
final class VisitPresenter
{
    /**
     * `$examFee` : marqueur du contrôle RÉEL du paiement de l'examen (`BilledExamFeeControl::queueMarker`), quand il est
     * actif ; null sinon (contrôle inactif : « non contrôlé » ou « sans objet », statut tel qu'enregistré).
     *
     * @param  array{control: string, receipt_number: ?string, status: string}|null  $examFee
     * @return array<string, mixed>
     */
    public static function visit(stdClass $row, bool $withConsultation = false, ?array $examFee = null): array
    {
        return [
            'id' => $row->id,
            'patient_id' => $row->patient_id,
            'reason' => $row->reason,
            'occurred_at' => self::instant((string) $row->occurred_at),
            'status' => $examFee['status'] ?? $row->status,
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
            // Contrôle inactif (défaut) : « non contrôlé » pour un examen ou un suivi, jamais « payé » ni « gratuit ». Actif :
            // à encaisser, dispense demandée, encaissés (numéro du dernier reçu), dispense accordée, ou sans objet.
            'exam_fee_control' => $examFee['control'] ?? VisitStatus::examFeeControl((string) $row->reason),
            'exam_fee_receipt_number' => $examFee['receipt_number'] ?? null,
            'consultation_id' => $withConsultation && $row->consultation_id !== null ? (string) $row->consultation_id : null,
            'consultation_status' => $withConsultation && $row->consultation_status !== null ? (string) $row->consultation_status : null,
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
