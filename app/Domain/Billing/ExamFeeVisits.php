<?php

namespace App\Domain\Billing;

use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Patients\Rules\VisitStatus;
use App\Domain\Shared\CommandContext;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Statut « en attente du paiement de l'examen » / « prêt pour l'examen » d'une visite (P03 ; fichier 02 : « La file du
 * jour distingue arrivé, en attente de paiement examen, prêt pour examen… »), écrit aux ÉVÉNEMENTS qui le changent,
 * dans leur transaction et sous le verrou de la visite : encaissement, contre-écriture, avoir et remboursement de la
 * facture de frais de la visite, décision de la gérante sur une dispense. Seulement quand le contrôle réel est actif
 * (`EEO_EXAM_FEE_CONTROL=billed`) ; sinon rien n'est écrit (« non contrôlé »).
 *
 * Seules les visites d'examen ou de suivi encore à ces deux statuts bougent : une visite arrivée avant l'activation du
 * contrôle reste « arrivée », une visite en consultation, terminée ou annulée n'est jamais réécrite. La facture de frais
 * réglée retenue est consignée (`exam_invoice_id`), retirée si elle ne l'est plus (contre-écriture, avoir remboursé).
 *
 * Verrou : la visite au rang 46, AVANT la propriété de facture (50) et la facture (60). `lockForInvoice` lit sans verrou
 * la visite d'une facture émise (immuable après émission), puis la verrouille.
 */
final class ExamFeeVisits
{
    public function __construct(private readonly BilledExamFeeControl $control) {}

    /**
     * Verrouille la visite rattachée à la facture, quand le contrôle est actif et que la facture en a une ; sinon null.
     */
    public function lockForInvoice(TransactionLocks $locks, string $clinicId, string $invoiceId): ?stdClass
    {
        if (! BilledExamFeeControl::isActive()) {
            return null;
        }
        $visitId = DB::table('invoices')->where('clinic_id', $clinicId)->where('id', $invoiceId)->value('visit_id');

        return is_string($visitId) ? $locks->visit($visitId) : null;
    }

    /**
     * Recalcule le statut de la visite verrouillée après le fait de la commande ; rend le changement à publier, ou null.
     */
    public function refresh(CommandContext $context, ?stdClass $visit): ?ChangeRecord
    {
        if ($visit === null || ! BilledExamFeeControl::isActive() || ! in_array((string) $visit->reason, VisitStatus::EXAM_REASONS, true)) {
            return null;
        }
        $status = (string) $visit->status;
        if (! in_array($status, [VisitStatus::AWAITING_EXAM_FEE, VisitStatus::READY_FOR_EXAM], true)) {
            return null;
        }

        $visitId = (string) $visit->id;
        $patientId = (string) $visit->patient_id;
        $paid = $this->control->paidInvoices($context->clinicId, $visitId, $patientId);
        $waived = $paid === [] && ExactApprovals::findValid($context->clinicId, ApprovalSubject::examFeeWaiver($visitId, $patientId), $context->now) !== null;
        $target = $paid !== [] || $waived ? VisitStatus::READY_FOR_EXAM : VisitStatus::AWAITING_EXAM_FEE;
        $invoiceId = $paid[0] ?? null;
        if ($target === $status && ($visit->exam_invoice_id ?? null) === $invoiceId) {
            return null;
        }

        $version = (int) $visit->version + 1;
        DB::table('visits')->where('clinic_id', $context->clinicId)->where('id', $visitId)->update([
            'status' => $target,
            'exam_invoice_id' => $invoiceId,
            'version' => $version,
            'updated_at' => $context->now->utc()->format('Y-m-d H:i:s.u'),
        ]);

        return new ChangeRecord('visits', $visitId, $version, ChangeVisibility::Administrative, ChangeKind::Updated);
    }
}
