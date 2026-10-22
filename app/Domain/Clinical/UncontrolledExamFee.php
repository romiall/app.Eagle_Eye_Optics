<?php

namespace App\Domain\Clinical;

use App\Application\Locking\TransactionLocks;
use stdClass;

/**
 * Contrôle du paiement de l'examen du premier jalon : il n'en existe aucun, et il répond toujours « non
 * contrôlé ». Il n'y a ni facture de frais ni approbation à vérifier (B04, B06 absents) : une preuve fournie par
 * le client est donc REFUSÉE plutôt qu'ignorée, car l'écran laisserait croire qu'elle a été enregistrée. La
 * visite garde `exam_invoice_id` et `exam_waiver_approval_id` à null.
 */
final class UncontrolledExamFee implements ExamFeeControl
{
    private const PROOFS = ['exam_invoice_id', 'exam_waiver_approval_id'];

    public function decide(stdClass $visit, array $charge, ?TransactionLocks $locks = null): ExamFeeDecision
    {
        $errors = [];
        foreach (self::PROOFS as $field) {
            if (($charge[$field] ?? null) !== null) {
                $errors[$field] = ['Le contrôle du paiement de l\'examen n\'est pas encore actif : aucune facture de frais ni dérogation ne peut être enregistrée (laissez ce champ vide).'];
            }
        }

        return new ExamFeeDecision(ExamFeeDecision::NOT_CONTROLLED, $errors);
    }
}
