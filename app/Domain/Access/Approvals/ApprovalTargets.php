<?php

namespace App\Domain\Access\Approvals;

use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\BilledExamFeeControl;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Patients\Rules\VisitStatus;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Cible d'une DEMANDE d'approbation (B06) : elle existe dans le cabinet et l'opération demandée y est encore possible au
 * moment de la demande. La gérante ne reçoit donc jamais à décider une opération déjà impossible (paiement déjà
 * contre-passé, facture non émise, visite déjà en consultation…). L'opération approuvée revérifie TOUT au moment de son
 * exécution : cette vérification ne remplace aucun contrôle de la commande elle-même.
 *
 * Verrous dans l'ordre du contrat 23 : dossier patient (45), visite (46), facture (60). Erreurs sur les champs de
 * `subject` (422) ; état devenu impossible : 409 `STATE_TRANSITION_REFUSED`.
 */
final class ApprovalTargets
{
    public function __construct(private readonly BilledExamFeeControl $examFee) {}

    public function lockAndCheck(TransactionLocks $locks, CommandContext $context, ApprovalSubject $subject): void
    {
        $content = $subject->content;

        $lines = is_array($content['lines'] ?? null) ? $content['lines'] : [];

        switch ($subject->kind) {
            case ApprovalKind::ExamFeeWaiver:
                $this->examFeeWaiver($locks, $context, (string) $content['visit_id'], (string) $content['patient_id']);
                break;
            case ApprovalKind::DraftLineRights:
                $this->draft($locks, $context, (string) $content['invoice_id'], (string) $content['patient_id'], $lines);
                break;
            case ApprovalKind::PaymentReversal:
                $this->reversal($locks, $context, (string) $content['invoice_id'], (string) $content['payment_id'], (int) $content['amount']);
                break;
            case ApprovalKind::CreditNote:
                $this->creditNote($locks, $context, (string) $content['invoice_id'], (int) $content['amount_gross'], $lines);
                break;
            case ApprovalKind::Refund:
                $this->refund($locks, $context, (string) $content['invoice_id'], (string) $content['credit_note_id'], (int) $content['amount']);
                break;
        }
    }

    private function examFeeWaiver(TransactionLocks $locks, CommandContext $context, string $visitId, string $patientId): void
    {
        if (! BilledExamFeeControl::isActive()) {
            throw BusinessError::stateRefused('Le contrôle du paiement de l\'examen n\'est pas actif dans ce cabinet : aucune dispense n\'est à demander.', 'NOT_CONTROLLED');
        }
        $visit = $locks->visit($visitId);
        if ($visit === null) {
            throw BusinessError::validation(['subject.visit_id' => ['Visite introuvable dans ce cabinet.']]);
        }
        if ((string) $visit->patient_id !== $patientId) {
            throw BusinessError::validation(['subject.patient_id' => ['Cette visite concerne un autre patient.']]);
        }
        if (! in_array((string) $visit->reason, VisitStatus::EXAM_REASONS, true)) {
            throw BusinessError::validation(['subject.visit_id' => ['Une visite d\'achat ou de retrait ne comporte pas de frais d\'examen.']]);
        }
        $started = DB::table('consultations')->where('clinic_id', $context->clinicId)->where('visit_id', $visitId)->exists();
        if ($started || ! in_array((string) $visit->status, [VisitStatus::ARRIVED, VisitStatus::AWAITING_EXAM_FEE, VisitStatus::READY_FOR_EXAM], true)) {
            throw BusinessError::stateRefused('L\'examen de cette visite est déjà démarré, terminé ou annulé : aucune dispense n\'est à demander.', (string) $visit->status);
        }
        if ($this->examFee->paidInvoices($context->clinicId, $visitId, $patientId) !== []) {
            throw BusinessError::stateRefused('Les frais d\'examen de cette visite sont déjà encaissés : aucune dispense n\'est à demander.', 'PAID');
        }
    }

    /**
     * @param  array<int, mixed>  $lines
     */
    private function draft(TransactionLocks $locks, CommandContext $context, string $invoiceId, string $patientId, array $lines): void
    {
        if ($lines === []) {
            throw BusinessError::validation(['subject.lines' => ['Le brouillon à approuver comporte au moins une ligne.']]);
        }
        $patient = $locks->patient($patientId);
        if ($patient === null) {
            throw BusinessError::validation(['subject.patient_id' => ['Patient introuvable dans ce cabinet.']]);
        }
        $invoice = $locks->invoice($invoiceId);
        if ($invoice === null) {
            // Brouillon pas encore créé : l'identifiant ne doit être pris nulle part (clé primaire globale).
            if (DB::table('invoices')->where('id', $invoiceId)->exists()) {
                throw BusinessError::validation(['subject.invoice_id' => ['Cet identifiant de facture est déjà utilisé.']]);
            }
            if ($patient->archived_at !== null || $patient->merged_into_patient_id !== null) {
                throw BusinessError::validation(['subject.patient_id' => ['Cette fiche est archivée ou fusionnée : ouvrez la fiche patient actuelle.']]);
            }

            return;
        }
        if ((string) $invoice->status !== 'DRAFT') {
            throw BusinessError::stateRefused('Cette facture n\'est plus un brouillon : ses lignes ne se modifient plus (avoir ou contre-écriture liés).', (string) $invoice->status);
        }
        if ((string) $invoice->patient_id !== $patientId) {
            throw BusinessError::validation(['subject.patient_id' => ['Ce brouillon concerne un autre patient.']]);
        }
    }

    private function reversal(TransactionLocks $locks, CommandContext $context, string $invoiceId, string $paymentId, int $amount): void
    {
        $invoice = $locks->invoice($invoiceId);
        if ($invoice === null) {
            throw BusinessError::validation(['subject.invoice_id' => ['Facture introuvable dans ce cabinet.']]);
        }
        $payment = DB::table('payments')->where('clinic_id', $context->clinicId)->where('id', $paymentId)->first();
        if ($payment === null || (string) $payment->invoice_id !== $invoiceId) {
            throw BusinessError::validation(['subject.payment_id' => ['Paiement introuvable sur cette facture.']]);
        }
        if ((string) $payment->status !== 'CONFIRMED') {
            throw BusinessError::stateRefused('Seul un paiement confirmé se contre-passe.', (string) $payment->status);
        }
        $reversed = (int) DB::table('payment_reversals')->where('clinic_id', $context->clinicId)->where('payment_id', $paymentId)->sum('amount');
        try {
            LedgerBalance::assertReversal((int) $payment->amount, $reversed, $amount);
        } catch (RuleViolation $violation) {
            throw new BusinessError('VALIDATION_FAILED', $violation->getMessage(), 422, ['subject.amount' => [$violation->getMessage()]], $violation, [], $violation->details);
        }
    }

    /**
     * @param  array<int, mixed>  $lines
     */
    private function creditNote(TransactionLocks $locks, CommandContext $context, string $invoiceId, int $amount, array $lines): void
    {
        $invoice = $this->issued($locks, $invoiceId);
        if ($lines === []) {
            throw BusinessError::validation(['subject.lines' => ['Un avoir porte au moins une ligne de la facture.']]);
        }
        $known = DB::table('invoice_lines')->where('clinic_id', $context->clinicId)->where('invoice_id', $invoiceId)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $sum = 0;
        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line) || ! in_array((string) ($line['invoice_line_id'] ?? ''), $known, true)) {
                throw BusinessError::validation(['subject.lines.'.$index.'.invoice_line_id' => ['Cette ligne n\'appartient pas à la facture.']]);
            }
            $sum += (int) ($line['amount_gross'] ?? 0);
        }
        if ($sum !== $amount) {
            throw BusinessError::validation(['subject.amount_gross' => ['Le montant de l\'avoir doit être la somme de ses lignes.']]);
        }
        $ledger = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        try {
            $ledger->assertCreditNote($amount);
        } catch (RuleViolation $violation) {
            throw new BusinessError('VALIDATION_FAILED', $violation->getMessage(), 422, ['subject.amount_gross' => [$violation->getMessage()]], $violation, [], $violation->details);
        }
    }

    private function refund(TransactionLocks $locks, CommandContext $context, string $invoiceId, string $creditNoteId, int $amount): void
    {
        $invoice = $this->issued($locks, $invoiceId);
        $creditNote = DB::table('credit_notes')->where('clinic_id', $context->clinicId)->where('id', $creditNoteId)->first();
        if ($creditNote === null || (string) $creditNote->invoice_id !== $invoiceId || (string) $creditNote->status !== 'ISSUED') {
            throw BusinessError::validation(['subject.credit_note_id' => ['Avoir émis introuvable sur cette facture.']]);
        }
        $ledger = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        try {
            $ledger->assertRefund($amount);
        } catch (RuleViolation $violation) {
            throw new BusinessError('VALIDATION_FAILED', $violation->getMessage(), 422, ['subject.amount' => [$violation->getMessage()]], $violation, [], $violation->details);
        }
    }

    private function issued(TransactionLocks $locks, string $invoiceId): \stdClass
    {
        $invoice = $locks->invoice($invoiceId);
        if ($invoice === null) {
            throw BusinessError::validation(['subject.invoice_id' => ['Facture introuvable dans ce cabinet.']]);
        }
        if ((string) $invoice->status !== 'ISSUED') {
            throw BusinessError::stateRefused('Seule une facture émise se corrige par avoir ou remboursement.', (string) $invoice->status);
        }

        return $invoice;
    }
}
