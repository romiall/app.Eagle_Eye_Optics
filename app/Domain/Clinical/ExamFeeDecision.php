<?php

namespace App\Domain\Clinical;

/**
 * Verdict du contrôle du paiement de l'examen. `status` est tracé dans l'audit du démarrage
 * (`consultation.started`, `exam_fee_control`). `errors` : refus par champ de la charge (422), vide si le
 * démarrage est admis. Plus tard : `PAID`, `WAIVED`, et le refus `EXAM_PAYMENT_REQUIRED`.
 */
final readonly class ExamFeeDecision
{
    /** Aucun contrôle actif : ni « payé » ni « dérogé » ni « gratuit » n'est affirmé. */
    public const NOT_CONTROLLED = 'NOT_CONTROLLED';

    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(public string $status, public array $errors = []) {}
}
