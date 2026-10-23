<?php

namespace App\Http\Resources;

use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Rules\Settlement;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation des factures, encaissements et reçus pour l'API (schémas `Invoice`, `InvoiceSummary`, `Receipt` de
 * `schemas/resources/billing.json`). Les instants sont rendus en UTC à la milliseconde, les dates civiles telles
 * quelles, les montants en entiers FCFA, les quantités en chaînes exactes.
 *
 * `draft_version` est `invoices.version` (figée à l'émission) ; `ledger_version` est la projection des faits
 * financiers, nulle pour un brouillon. Aucun statut « payé » n'existe : `balance.settlement` est dérivé du solde.
 * Les snapshots (patient, cabinet, taxe) ne sont rendus que pour une facture émise : ce sont eux, jamais l'identité
 * ni les prix actuels, qui servent à imprimer la pièce.
 */
final class InvoicePresenter
{
    /**
     * @param  array{invoice: stdClass, lines: list<stdClass>, payments: list<stdClass>, reversals: list<stdClass>, credit_notes: list<stdClass>, refunds: list<stdClass>, owner: ?stdClass, document: ?stdClass, ledger: ?LedgerBalance}  $detail
     * @return array<string, mixed>
     */
    public static function invoice(array $detail): array
    {
        $invoice = $detail['invoice'];
        $ledger = $detail['ledger'];

        return [
            'id' => $invoice->id,
            'patient_id' => $invoice->patient_id,
            'visit_id' => $invoice->visit_id,
            // Provenance (L1D07) : le devis dont ce brouillon est né, null sinon.
            'quote_id' => $invoice->quote_id ?? null,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'issued_at' => self::nullableInstant($invoice->issued_at),
            'issued_by' => $invoice->issued_by,
            // Nom ACTUEL du compte émetteur (users.display_name) : jamais figé dans la pièce, null si non émise.
            'issued_by_name' => isset($invoice->issued_by_name) && is_string($invoice->issued_by_name) ? $invoice->issued_by_name : null,
            'due_date' => $invoice->due_date,
            'currency' => $invoice->currency,
            'total_gross' => (int) $invoice->total_gross,
            'origin' => $invoice->origin,
            'legacy_reference' => $invoice->legacy_reference,
            'draft_version' => (int) $invoice->version,
            'ledger_version' => $ledger?->ledgerVersion,
            'balance' => Settlement::balanceArray((string) $invoice->status, (int) $invoice->total_gross, $ledger),
            'lines' => array_map(self::line(...), $detail['lines']),
            'payments' => array_map(self::payment(...), $detail['payments']),
            // Contre-écritures (B07) : pièces liées, le paiement et son reçu restent tels qu'émis.
            'reversals' => array_map(static fn (stdClass $reversal): array => [
                'id' => $reversal->id,
                'payment_id' => $reversal->payment_id,
                'amount' => (int) $reversal->amount,
                'reason' => $reversal->reason,
                'recorded_at' => self::instant((string) $reversal->recorded_at),
                'recorded_by' => $reversal->created_by,
                'recorded_by_name' => isset($reversal->recorded_by_name) && is_string($reversal->recorded_by_name) ? $reversal->recorded_by_name : null,
            ], $detail['reversals']),
            'credit_notes' => array_map(static fn (stdClass $note): array => [
                'id' => $note->id,
                'number' => $note->number,
                'status' => $note->status,
                'amount_gross' => (int) $note->amount_gross,
                'issued_at' => self::nullableInstant($note->issued_at),
                'reason' => $note->reason,
                // Part de l'avoir déjà remboursée (B08) : le reste est remboursable, au plus le crédit de la facture.
                'refunded_amount' => (int) ($note->refunded_amount ?? 0),
            ], $detail['credit_notes']),
            'refunds' => array_map(static fn (stdClass $refund): array => [
                'id' => $refund->id,
                'number' => $refund->number,
                'amount' => (int) $refund->amount,
                'method' => $refund->method,
                'effective_at' => self::instant((string) $refund->effective_at),
                'credit_note_id' => $refund->credit_note_id,
            ], $detail['refunds']),
            'write_owner' => $detail['owner'] === null ? null : [
                'device_id' => $detail['owner']->device_id,
                'epoch' => (int) $detail['owner']->epoch,
                'status' => $detail['owner']->status,
            ],
            'document' => $detail['document'] === null ? null : self::documentState($detail['document']),
            'patient_snapshot' => self::snapshot($invoice->patient_snapshot),
            'clinic_snapshot' => self::snapshot($invoice->clinic_snapshot),
            'tax_snapshot' => self::snapshot($invoice->tax_snapshot),
        ];
    }

    /**
     * Ligne de liste (`InvoiceSummary`) avec son solde projeté (null pour un brouillon).
     *
     * @return array<string, mixed>
     */
    public static function summary(stdClass $invoice, ?LedgerBalance $ledger): array
    {
        return [
            'id' => $invoice->id,
            'patient_id' => $invoice->patient_id,
            // Identité de base du patient (dossier courant, jamais une donnée clinique) : null si la ligne n'a pas été
            // lue avec son patient (lecture sans jointure).
            'patient' => isset($invoice->patient_record_number) ? [
                'id' => $invoice->patient_id,
                'record_number' => (string) $invoice->patient_record_number,
                'last_name' => (string) $invoice->patient_last_name,
                'first_names' => $invoice->patient_first_names,
            ] : null,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'issued_at' => self::nullableInstant($invoice->issued_at),
            'total_gross' => (int) $invoice->total_gross,
            'balance_due' => Settlement::balanceArray((string) $invoice->status, (int) $invoice->total_gross, $ledger)['balance_due'],
            'draft_version' => (int) $invoice->version,
            'ledger_version' => $ledger?->ledgerVersion,
        ];
    }

    /**
     * Reçu (`Receipt`) : pièce immuable d'un encaissement, avec son snapshot figé et l'état de son document.
     *
     * @return array<string, mixed>
     */
    public static function receipt(stdClass $receipt, ?stdClass $document): array
    {
        return [
            'id' => $receipt->id,
            'payment_id' => $receipt->payment_id,
            'invoice_id' => $receipt->invoice_id,
            'number' => $receipt->number,
            'issued_at' => self::instant((string) $receipt->issued_at),
            'issued_by' => $receipt->issued_by,
            'snapshot' => self::snapshot($receipt->snapshot),
            'document' => $document === null ? null : self::documentState($document),
        ];
    }

    /**
     * Avoir (`CreditNote`, B07) : pièce liée à la facture, lignes d'origine créditées, snapshot figé à l'émission
     * (source de son document) et état du document. Le nom de l'émetteur est son nom ACTUEL.
     *
     * @param  array{credit_note: stdClass, lines: list<stdClass>, document: ?stdClass}  $detail
     * @return array<string, mixed>
     */
    public static function creditNote(array $detail): array
    {
        $note = $detail['credit_note'];

        return [
            'id' => $note->id,
            'invoice_id' => $note->invoice_id,
            'number' => $note->number,
            'status' => $note->status,
            'amount_gross' => (int) $note->amount_gross,
            'reason' => $note->reason,
            'approval_id' => $note->approval_id,
            'issued_at' => self::nullableInstant($note->issued_at),
            'issued_by' => $note->issued_by,
            'issued_by_name' => isset($note->issued_by_name) && is_string($note->issued_by_name) ? $note->issued_by_name : null,
            'refunded_amount' => (int) ($note->refunded_amount ?? 0),
            'lines' => array_map(static fn (stdClass $line): array => [
                'id' => $line->id,
                'invoice_line_id' => $line->invoice_line_id,
                'description_snapshot' => $line->description_snapshot,
                'quantity' => $line->quantity === null ? null : (string) $line->quantity,
                'amount_gross' => (int) $line->amount_gross,
                'tax_amount' => $line->tax_amount === null ? null : (int) $line->tax_amount,
                'reason' => $line->reason,
            ], $detail['lines']),
            'snapshot' => self::snapshot($note->snapshot),
            'document' => $detail['document'] === null ? null : self::documentState($detail['document']),
        ];
    }

    /**
     * Remboursement (`Refund`, B08) : sortie d'argent liée à un avoir, snapshot figé (source de son document) et état
     * du document. Le nom de l'auteur est son nom ACTUEL.
     *
     * @param  array{refund: stdClass, document: ?stdClass}  $detail
     * @return array<string, mixed>
     */
    public static function refund(array $detail): array
    {
        $refund = $detail['refund'];

        return [
            'id' => $refund->id,
            'invoice_id' => $refund->invoice_id,
            'credit_note_id' => $refund->credit_note_id,
            'number' => $refund->number,
            'amount' => (int) $refund->amount,
            'method' => $refund->method,
            'reference' => $refund->reference,
            'approval_id' => $refund->approval_id,
            'effective_at' => self::instant((string) $refund->effective_at),
            'cash_session_id' => $refund->cash_session_id,
            'created_by' => $refund->created_by,
            'created_by_name' => isset($refund->created_by_name) && is_string($refund->created_by_name) ? $refund->created_by_name : null,
            'snapshot' => self::snapshot($refund->snapshot),
            'document' => $detail['document'] === null ? null : self::documentState($detail['document']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(stdClass $line): array
    {
        return [
            'id' => $line->id,
            'position' => (int) $line->position,
            'catalog_item_id' => $line->catalog_item_id,
            'reference_snapshot' => $line->reference_snapshot,
            'description_snapshot' => $line->description_snapshot,
            'category_snapshot' => $line->category_snapshot,
            'unit_snapshot' => $line->unit_snapshot,
            'quantity' => (string) $line->quantity,
            'unit_price_gross' => (int) $line->unit_price_gross,
            'discount_gross' => (int) $line->discount_gross,
            'line_total_gross' => (int) $line->line_total_gross,
            'tax_code_snapshot' => $line->tax_code_snapshot,
            'tax_rate_snapshot' => $line->tax_rate_snapshot === null ? null : (string) $line->tax_rate_snapshot,
            'tax_amount' => $line->tax_amount === null ? null : (int) $line->tax_amount,
            'prescription_id' => $line->prescription_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payment(stdClass $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (int) $payment->amount,
            'method' => $payment->method,
            'reference' => $payment->reference,
            'effective_at' => self::instant((string) $payment->effective_at),
            'confirmed_at' => self::nullableInstant($payment->confirmed_at),
            'status' => $payment->status,
            'receipt_id' => $payment->receipt_id,
            'receipt_number' => $payment->receipt_number,
            'reversed_amount' => (int) $payment->reversed_amount,
            'received_by' => $payment->received_by,
            // Nom ACTUEL du compte qui a encaissé (le reçu garde le nom figé dans son snapshot).
            'received_by_name' => isset($payment->received_by_name) && is_string($payment->received_by_name) ? $payment->received_by_name : null,
        ];
    }

    /**
     * @return array{document_id: string, document_status: string}
     */
    private static function documentState(stdClass $document): array
    {
        return ['document_id' => (string) $document->id, 'document_status' => (string) $document->status];
    }

    /**
     * Colonne JSON rendue comme OBJET (un `{}` vide reste un objet), ou null.
     */
    private static function snapshot(mixed $stored): mixed
    {
        return is_string($stored) ? json_decode($stored, false, 512, JSON_THROW_ON_ERROR) : null;
    }

    private static function nullableInstant(mixed $stored): ?string
    {
        return $stored === null ? null : self::instant((string) $stored);
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
