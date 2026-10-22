<?php

namespace App\Domain\Patients\Rules;

/**
 * États d'une visite (`visits.status`) et marqueur du contrôle de paiement de l'examen.
 *
 * Contrôle inactif (`EEO_EXAM_FEE_CONTROL=off`, valeur par défaut) : `RegisterVisit` écrit `ARRIVED`,
 * `StartConsultation` écrit `IN_CONSULTATION` ; `AWAITING_EXAM_FEE` et `READY_FOR_EXAM` ne sont JAMAIS écrits
 * (« prêt pour examen » dirait que la règle est satisfaite, ce qui serait faux). L'interface le dit : « Paiement de
 * l'examen non contrôlé ».
 *
 * Contrôle actif (`billed`, P03 avec B04 et B06) : une visite d'examen ou de suivi arrive `AWAITING_EXAM_FEE`
 * (`initial`) ; l'encaissement de sa facture de frais ou la dispense accordée par la gérante la passent
 * `READY_FOR_EXAM`, une correction qui annule ce règlement la ramène `AWAITING_EXAM_FEE`
 * (`App\Domain\Billing\ExamFeeVisits`). Un achat ou un retrait arrive toujours `ARRIVED`.
 */
final class VisitStatus
{
    public const ARRIVED = 'ARRIVED';

    public const AWAITING_EXAM_FEE = 'AWAITING_EXAM_FEE';

    public const READY_FOR_EXAM = 'READY_FOR_EXAM';

    public const IN_CONSULTATION = 'IN_CONSULTATION';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    /** Motifs d'une visite (`visits.reason`). */
    public const REASONS = ['EXAM', 'DIRECT_PURCHASE', 'PICKUP', 'FOLLOW_UP'];

    /** Motifs qui appellent un examen : le paiement de l'examen leur est applicable. */
    public const EXAM_REASONS = ['EXAM', 'FOLLOW_UP'];

    /** Marqueur de contrôle de l'examen : aucun contrôle actif dans ce jalon. */
    public const FEE_NOT_CONTROLLED = 'NOT_CONTROLLED';

    /** Marqueur : la visite ne comporte pas d'examen (achat direct, retrait). */
    public const FEE_NOT_APPLICABLE = 'NOT_APPLICABLE';

    /**
     * Statut écrit à l'arrivée : `AWAITING_EXAM_FEE` pour un examen ou un suivi quand le contrôle du paiement de
     * l'examen est actif, `ARRIVED` sinon.
     */
    public static function initial(string $reason, bool $examFeeControlled): string
    {
        return $examFeeControlled && in_array($reason, self::EXAM_REASONS, true) ? self::AWAITING_EXAM_FEE : self::ARRIVED;
    }

    /**
     * Marqueur de contrôle du paiement de l'examen pour une visite, contrôle INACTIF : une visite d'examen est « non
     * contrôlée », jamais « payée », « soldée » ni « gratuite ». Contrôle actif : voir
     * `App\Domain\Billing\BilledExamFeeControl::queueMarker`.
     */
    public static function examFeeControl(string $reason): string
    {
        return in_array($reason, self::EXAM_REASONS, true) ? self::FEE_NOT_CONTROLLED : self::FEE_NOT_APPLICABLE;
    }
}
