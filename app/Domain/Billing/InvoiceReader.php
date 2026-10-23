<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Reports\ReportDay;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecture des factures du cabinet (B02, B03, B04) : la ligne `invoices`, ses lignes, ses encaissements avec leur
 * reçu, ses contre-écritures, ses avoirs, ses remboursements, le poste qui la détient et son document, et le solde
 * PROJETÉ depuis ces faits (jamais une colonne). Toujours limitée au cabinet ; un identifiant d'un autre cabinet est
 * « introuvable ».
 *
 * Le présentateur (`InvoicePresenter`) met en forme ce que ce lecteur rend, au format du contrat
 * (`schemas/resources/billing.json`).
 */
final class InvoiceReader
{
    public const PER_PAGE = 25;

    public function find(string $clinicId, string $invoiceId): ?stdClass
    {
        $row = DB::table('invoices')->where('clinic_id', $clinicId)->where('id', $invoiceId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Facture complète : lignes, faits financiers, propriétaire, document et solde projeté.
     *
     * @return array{invoice: stdClass, lines: list<stdClass>, payments: list<stdClass>, reversals: list<stdClass>, credit_notes: list<stdClass>, refunds: list<stdClass>, owner: ?stdClass, document: ?stdClass, ledger: ?LedgerBalance}
     */
    public function detail(stdClass $invoice): array
    {
        $clinicId = (string) $invoice->clinic_id;
        $invoiceId = (string) $invoice->id;

        $lines = array_values(DB::table('invoice_lines')->where('clinic_id', $clinicId)->where('invoice_id', $invoiceId)->orderBy('position')->get()->all());

        $payments = array_values(DB::table('payments')
            ->leftJoin('receipts', static function ($join): void {
                $join->on('receipts.clinic_id', '=', 'payments.clinic_id')->on('receipts.payment_id', '=', 'payments.id');
            })
            // Nom ACTUEL du compte qui a encaissé (users.display_name du cabinet) : le reçu, lui, garde le nom figé
            // dans son snapshot.
            ->leftJoin('users as collectors', static function ($join): void {
                $join->on('collectors.clinic_id', '=', 'payments.clinic_id')->on('collectors.id', '=', 'payments.received_by');
            })
            ->where('payments.clinic_id', $clinicId)
            ->where('payments.invoice_id', $invoiceId)
            ->orderBy('payments.effective_at')
            ->orderBy('payments.created_at')
            ->orderBy('payments.id')
            ->get(['payments.*', 'receipts.id as receipt_id', 'receipts.number as receipt_number', 'collectors.display_name as received_by_name'])
            ->all());
        $reversed = [];
        if ($payments !== []) {
            foreach (DB::table('payment_reversals')
                ->where('clinic_id', $clinicId)
                ->whereIn('payment_id', array_map(static fn (stdClass $payment): string => (string) $payment->id, $payments))
                ->selectRaw('payment_id, SUM(amount) AS reversed')
                ->groupBy('payment_id')
                ->get() as $row) {
                $reversed[(string) $row->payment_id] = (int) $row->reversed;
            }
        }
        foreach ($payments as $payment) {
            $payment->reversed_amount = $reversed[(string) $payment->id] ?? 0;
        }

        // Contre-écritures (B07) : pièces liées aux paiements de cette facture, jamais une modification du paiement ; nom
        // ACTUEL du compte qui les a enregistrées.
        $reversals = array_values(DB::table('payment_reversals')
            ->join('payments', static function ($join): void {
                $join->on('payments.clinic_id', '=', 'payment_reversals.clinic_id')->on('payments.id', '=', 'payment_reversals.payment_id');
            })
            ->leftJoin('users as recorders', static function ($join): void {
                $join->on('recorders.clinic_id', '=', 'payment_reversals.clinic_id')->on('recorders.id', '=', 'payment_reversals.created_by');
            })
            ->where('payment_reversals.clinic_id', $clinicId)
            ->where('payments.invoice_id', $invoiceId)
            ->orderBy('payment_reversals.recorded_at')
            ->orderBy('payment_reversals.id')
            ->get(['payment_reversals.*', 'recorders.display_name as recorded_by_name'])
            ->all());

        $creditNotes = array_values(DB::table('credit_notes')->where('clinic_id', $clinicId)->where('invoice_id', $invoiceId)->orderBy('created_at')->orderBy('id')->get()->all());
        $refunds = array_values(DB::table('refunds')->where('clinic_id', $clinicId)->where('invoice_id', $invoiceId)->orderBy('effective_at')->orderBy('id')->get()->all());
        // Part de chaque avoir déjà remboursée (B08 : un remboursement est lié à un avoir et ne le dépasse jamais).
        $refundedByNote = [];
        foreach ($refunds as $refund) {
            $note = (string) $refund->credit_note_id;
            $refundedByNote[$note] = ($refundedByNote[$note] ?? 0) + (int) $refund->amount;
        }
        foreach ($creditNotes as $note) {
            $note->refunded_amount = $refundedByNote[(string) $note->id] ?? 0;
        }

        $owner = DB::table('invoice_write_owners')
            ->where('clinic_id', $clinicId)
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', ['ACTIVE', 'CLOSING'])
            ->first();
        $document = DB::table('generated_documents')
            ->where('clinic_id', $clinicId)
            ->where('invoice_id', $invoiceId)
            ->where('document_type', 'INVOICE')
            ->orderByDesc('revision_no')
            ->orderByDesc('created_at')
            ->first();

        // Nom ACTUEL du compte émetteur (aucune identité inventée : null si la facture n'est pas émise).
        $issuerName = $invoice->issued_by === null ? null : DB::table('users')
            ->where('clinic_id', $clinicId)
            ->where('id', (string) $invoice->issued_by)
            ->value('display_name');
        $invoice->issued_by_name = is_string($issuerName) ? $issuerName : null;

        return [
            'invoice' => $invoice,
            'lines' => $lines,
            'payments' => $payments,
            'reversals' => $reversals,
            'credit_notes' => $creditNotes,
            'refunds' => $refunds,
            'owner' => $owner instanceof stdClass ? $owner : null,
            'document' => $document instanceof stdClass ? $document : null,
            'ledger' => (string) $invoice->status === 'ISSUED' ? InvoiceLedger::load($clinicId, $invoiceId, (int) $invoice->total_gross) : null,
        ];
    }

    /**
     * Liste des factures (plus récentes d'abord), 25 par page, avec leur solde projeté et l'identité de base de leur
     * patient (numéro de dossier, nom, prénoms : colonnes `patient_*` de la ligne). Filtres : état, patient, et jour
     * civil d'ÉMISSION (`issued_on`, fuseau du cabinet : seules les factures émises ce jour-là, jamais un minuit UTC).
     *
     * @return array{page: LengthAwarePaginator<int, stdClass>, ledgers: array<string, LedgerBalance>}
     */
    public function page(string $clinicId, mixed $status, mixed $patientId, mixed $page = null, mixed $issuedOn = null, string $timezone = 'Africa/Douala'): array
    {
        $query = DB::table('invoices')
            ->leftJoin('patient_records', static function ($join): void {
                $join->on('patient_records.clinic_id', '=', 'invoices.clinic_id')->on('patient_records.id', '=', 'invoices.patient_id');
            })
            ->where('invoices.clinic_id', $clinicId)
            ->select(
                'invoices.*',
                'patient_records.record_number as patient_record_number',
                'patient_records.last_name as patient_last_name',
                'patient_records.first_names as patient_first_names',
            );
        if ($status !== null && $status !== '') {
            if (! is_string($status) || ! in_array($status, ['DRAFT', 'ISSUED', 'ABANDONED'], true)) {
                throw BusinessError::validation(['status' => ['Statut attendu : DRAFT, ISSUED ou ABANDONED.']]);
            }
            $query->where('invoices.status', $status);
        }
        if ($patientId !== null && $patientId !== '') {
            if (! is_string($patientId) || ! Ulid::isValid($patientId)) {
                throw BusinessError::validation(['patient_id' => ['L\'identifiant du patient est un ULID.']]);
            }
            $query->where('invoices.patient_id', $patientId);
        }
        $day = ReportDay::optional($timezone, $issuedOn, 'issued_on');
        if ($day !== null) {
            $query->where('invoices.status', 'ISSUED')
                ->where('invoices.issued_at', '>=', $day->start)
                ->where('invoices.issued_at', '<', $day->end);
        }

        $pageNumber = 1;
        if ($page !== null) {
            if ((! is_string($page) && ! is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }

        $paginator = $query
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoices.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);

        return ['page' => $paginator, 'ledgers' => $this->ledgers($clinicId, array_values($paginator->items()))];
    }

    /**
     * Soldes projetés d'un lot de factures ÉMISES, en quatre lectures groupées (jamais une lecture par facture).
     *
     * @param  list<stdClass>  $invoices
     * @return array<string, LedgerBalance> par identifiant de facture émise
     */
    public function ledgers(string $clinicId, array $invoices): array
    {
        $issued = array_values(array_filter($invoices, static fn (stdClass $invoice): bool => (string) $invoice->status === 'ISSUED'));
        if ($issued === []) {
            return [];
        }
        $ids = array_map(static fn (stdClass $invoice): string => (string) $invoice->id, $issued);

        $payments = [];
        foreach (DB::table('payments')->where('clinic_id', $clinicId)->whereIn('invoice_id', $ids)->where('status', 'CONFIRMED')->orderBy('id')->get(['invoice_id', 'amount']) as $row) {
            $payments[(string) $row->invoice_id][] = (int) $row->amount;
        }
        $reversals = [];
        foreach (DB::table('payment_reversals')
            ->join('payments', static function ($join): void {
                $join->on('payments.clinic_id', '=', 'payment_reversals.clinic_id')->on('payments.id', '=', 'payment_reversals.payment_id');
            })
            ->where('payments.clinic_id', $clinicId)
            ->whereIn('payments.invoice_id', $ids)
            ->orderBy('payment_reversals.id')
            ->get(['payments.invoice_id as invoice_id', 'payment_reversals.amount as amount']) as $row) {
            $reversals[(string) $row->invoice_id][] = (int) $row->amount;
        }
        $credits = [];
        foreach (DB::table('credit_notes')->where('clinic_id', $clinicId)->whereIn('invoice_id', $ids)->where('status', 'ISSUED')->orderBy('id')->get(['invoice_id', 'amount_gross']) as $row) {
            $credits[(string) $row->invoice_id][] = (int) $row->amount_gross;
        }
        $refunds = [];
        foreach (DB::table('refunds')->where('clinic_id', $clinicId)->whereIn('invoice_id', $ids)->orderBy('id')->get(['invoice_id', 'amount']) as $row) {
            $refunds[(string) $row->invoice_id][] = (int) $row->amount;
        }

        $ledgers = [];
        foreach ($issued as $invoice) {
            $id = (string) $invoice->id;
            $ledgers[$id] = LedgerBalance::of((int) $invoice->total_gross, $payments[$id] ?? [], $reversals[$id] ?? [], $credits[$id] ?? [], $refunds[$id] ?? []);
        }

        return $ledgers;
    }

    /**
     * Avoir du cabinet (B07) avec ses lignes, le nom ACTUEL de son émetteur et l'état de son document ; null s'il n'existe
     * pas dans ce cabinet. Le snapshot figé à l'émission reste la source de son PDF.
     *
     * @return array{credit_note: stdClass, lines: list<stdClass>, document: ?stdClass}|null
     */
    public function creditNote(string $clinicId, string $creditNoteId): ?array
    {
        $note = DB::table('credit_notes')->where('clinic_id', $clinicId)->where('id', $creditNoteId)->first();
        if (! $note instanceof stdClass) {
            return null;
        }
        $issuerName = $note->issued_by === null ? null : DB::table('users')
            ->where('clinic_id', $clinicId)
            ->where('id', (string) $note->issued_by)
            ->value('display_name');
        $note->issued_by_name = is_string($issuerName) ? $issuerName : null;
        $note->refunded_amount = (int) DB::table('refunds')
            ->where('clinic_id', $clinicId)
            ->where('credit_note_id', $creditNoteId)
            ->sum('amount');

        $lines = array_values(DB::table('credit_note_lines')
            ->leftJoin('invoice_lines', static function ($join): void {
                $join->on('invoice_lines.clinic_id', '=', 'credit_note_lines.clinic_id')->on('invoice_lines.id', '=', 'credit_note_lines.invoice_line_id');
            })
            ->where('credit_note_lines.clinic_id', $clinicId)
            ->where('credit_note_lines.credit_note_id', $creditNoteId)
            ->orderBy('invoice_lines.position')
            ->orderBy('credit_note_lines.id')
            ->get(['credit_note_lines.*', 'invoice_lines.description_snapshot as description_snapshot'])
            ->all());
        $document = DB::table('generated_documents')
            ->where('clinic_id', $clinicId)
            ->where('credit_note_id', $creditNoteId)
            ->where('document_type', 'CREDIT_NOTE')
            ->orderByDesc('revision_no')
            ->orderByDesc('created_at')
            ->first();

        return ['credit_note' => $note, 'lines' => $lines, 'document' => $document instanceof stdClass ? $document : null];
    }

    /**
     * Remboursement du cabinet (B08) avec le nom ACTUEL de son auteur et l'état de son document ; null s'il n'existe pas
     * dans ce cabinet. Le snapshot figé reste la source de son PDF.
     *
     * @return array{refund: stdClass, document: ?stdClass}|null
     */
    public function refund(string $clinicId, string $refundId): ?array
    {
        $refund = DB::table('refunds')->where('clinic_id', $clinicId)->where('id', $refundId)->first();
        if (! $refund instanceof stdClass) {
            return null;
        }
        $creatorName = DB::table('users')
            ->where('clinic_id', $clinicId)
            ->where('id', (string) $refund->created_by)
            ->value('display_name');
        $refund->created_by_name = is_string($creatorName) ? $creatorName : null;
        $document = DB::table('generated_documents')
            ->where('clinic_id', $clinicId)
            ->where('refund_id', $refundId)
            ->where('document_type', 'REFUND')
            ->orderByDesc('revision_no')
            ->orderByDesc('created_at')
            ->first();

        return ['refund' => $refund, 'document' => $document instanceof stdClass ? $document : null];
    }

    public function receipt(string $clinicId, string $receiptId): ?stdClass
    {
        $row = DB::table('receipts')->where('clinic_id', $clinicId)->where('id', $receiptId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Document PDF d'un reçu (le dernier en date) : seulement son identifiant et son état.
     */
    public function receiptDocument(string $clinicId, string $receiptId): ?stdClass
    {
        $row = DB::table('generated_documents')
            ->where('clinic_id', $clinicId)
            ->where('receipt_id', $receiptId)
            ->where('document_type', 'RECEIPT')
            ->orderByDesc('revision_no')
            ->orderByDesc('created_at')
            ->first();

        return $row instanceof stdClass ? $row : null;
    }
}
