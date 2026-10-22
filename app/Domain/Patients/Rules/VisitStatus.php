<?php

namespace App\Domain\Patients\Rules;

/**
 * États d'une visite (`visits.status`) et marqueur du contrôle de paiement de l'examen.
 *
 * Premier jalon (P03 sans B04 ni B06) : le contrôle « examen payé ou dérogation » n'existe pas encore.
 * `RegisterVisit` écrit `ARRIVED`, `StartConsultation` écrira `IN_CONSULTATION` ; `AWAITING_EXAM_FEE` et
 * `READY_FOR_EXAM` ne sont JAMAIS écrits tant que le contrôle n'existe pas (« prêt pour examen » dirait que la
 * règle est satisfaite, ce qui serait faux). L'interface le dit : « Paiement de l'examen non contrôlé ».
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
     * Marqueur de contrôle du paiement de l'examen pour une visite. Tant que B04 et B06 ne l'activent pas, une
     * visite d'examen est « non contrôlée » : jamais « payée », « soldée » ni « gratuite ».
     */
    public static function examFeeControl(string $reason): string
    {
        return in_array($reason, self::EXAM_REASONS, true) ? self::FEE_NOT_CONTROLLED : self::FEE_NOT_APPLICABLE;
    }
}
