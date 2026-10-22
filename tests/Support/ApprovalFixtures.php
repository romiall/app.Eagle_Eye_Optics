<?php

namespace Tests\Support;

use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Approbations exactes (B06) et corrections des pièces (B07, B08) par l'API réelle, données FICTIVES. S'utilise avec
 * `BillingFixtures` (cabinet, comptes, catalogue, factures). Les écritures sensibles (décision, corrections) passent par
 * une session réauthentifiée (`actingAsReauthenticated`), comme l'exige la route.
 */
trait ApprovalFixtures
{
    /**
     * Écriture d'un compte réauthentifié récemment, avec sa clé d'idempotence (nouvelle par défaut).
     *
     * @param  array<string, mixed>  $body
     */
    protected function writeReauthenticated(string $method, string $uri, array $body, User $as, ?string $key = null): TestResponse
    {
        return $this->actingAsReauthenticated($as)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->json($method, $uri, $body);
    }

    /**
     * Demande d'approbation par l'API (`POST /approvals`).
     *
     * @param  array<string, mixed>  $subject
     */
    protected function requestApproval(array $subject, User $as, string $reason = 'Demande fictive de test', ?string $id = null, ?string $expiresAt = null, ?string $key = null): TestResponse
    {
        return $this->write('POST', '/api/v1/approvals', [
            'expected_version' => null,
            'approval_id' => $id ?? Ulid::generate(),
            'subject' => $subject,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ], $as, $key);
    }

    /**
     * Décision de la gérante (réauthentifiée par défaut).
     */
    protected function decide(string $approvalId, string $decision, User $as, ?string $reason = null, bool $reauthenticated = true, ?string $key = null): TestResponse
    {
        $body = ['expected_version' => null, 'approval_id' => $approvalId, 'decision' => $decision, 'reason' => $reason];
        if (! $reauthenticated) {
            return $this->write('POST', '/api/v1/approvals/'.$approvalId.'/decision', $body, $as, $key);
        }

        return $this->writeReauthenticated('POST', '/api/v1/approvals/'.$approvalId.'/decision', $body, $as, $key);
    }

    /**
     * Demande puis accord de la gérante : identifiant de l'approbation accordée.
     *
     * @param  array<string, mixed>  $subject
     */
    protected function approved(array $subject, User $requester): string
    {
        $id = (string) $this->requestApproval($subject, $requester)->assertCreated()->json('data.id');
        $this->decide($id, 'APPROVED', $this->manager)->assertOk();

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function reversalBody(string $invoiceId, string $paymentId, int $ledgerVersion, int $amount, array $overrides = []): array
    {
        return array_merge([
            'expected_version' => $ledgerVersion,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'reversal_id' => Ulid::generate(),
            'amount' => $amount,
            'reason' => 'Paiement saisi deux fois par erreur (fictif)',
            'approval_id' => Ulid::generate(),
        ], $overrides);
    }

    /**
     * Ligne d'avoir sur une ligne d'origine.
     *
     * @return array<string, mixed>
     */
    protected function creditLine(string $invoiceLineId, int $amount, ?string $quantity = null): array
    {
        return ['credit_note_line_id' => Ulid::generate(), 'invoice_line_id' => $invoiceLineId, 'quantity' => $quantity, 'amount_gross' => $amount, 'reason' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function creditNoteBody(string $invoiceId, int $ledgerVersion, array $lines, array $overrides = []): array
    {
        $amount = array_sum(array_map(static fn (array $line): int => (int) $line['amount_gross'], $lines));

        return array_merge([
            'expected_version' => $ledgerVersion,
            'invoice_id' => $invoiceId,
            'credit_note_id' => Ulid::generate(),
            'number_usage_id' => Ulid::generate(),
            'number' => null,
            'amount_gross' => $amount,
            'reason' => 'Geste commercial validé (fictif)',
            'approval_id' => Ulid::generate(),
            'document_id' => Ulid::generate(),
            'lines' => $lines,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function refundBody(string $invoiceId, string $creditNoteId, int $ledgerVersion, int $amount, string $method = 'MTN_MOMO', array $overrides = []): array
    {
        return array_merge([
            'expected_version' => $ledgerVersion,
            'invoice_id' => $invoiceId,
            'refund_id' => Ulid::generate(),
            'credit_note_id' => $creditNoteId,
            'number_usage_id' => Ulid::generate(),
            'number' => null,
            'amount' => $amount,
            'method' => $method,
            'reference' => $method === 'CASH' ? null : 'REFERENCE_FICTIVE',
            'approval_id' => Ulid::generate(),
            'effective_at' => self::NOW,
            'cash_session_id' => null,
            'document_id' => Ulid::generate(),
        ], $overrides);
    }

    /**
     * Contenu exact correspondant à une charge d'avoir (pour la demande d'approbation de la caisse).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function creditNoteSubject(array $body): array
    {
        return ApprovalSubject::creditNote((string) $body['invoice_id'], (int) $body['amount_gross'], $body['lines'])->toArray();
    }

    /** Identifiant de la première ligne d'une facture. */
    protected function firstLine(string $invoiceId): string
    {
        return (string) DB::table('invoice_lines')->where('invoice_id', $invoiceId)->orderBy('position')->value('id');
    }

    /** Paiement d'une facture (le plus ancien). */
    protected function firstPayment(string $invoiceId): string
    {
        return (string) DB::table('payments')->where('invoice_id', $invoiceId)->orderBy('created_at')->orderBy('id')->value('id');
    }
}
