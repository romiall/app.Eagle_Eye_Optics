<?php

namespace App\Domain\Billing\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Billing\BillingErrors;
use App\Domain\Billing\ExamFeeVisits;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Rules\Settlement;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Contre-écriture d'un paiement enregistré à tort (B07, AT13, EE024, EE045 ; fichier 03 : « Un encaissement erroné reçoit
 * une contre-écriture explicitement liée au paiement initial, puis un paiement correct si nécessaire » ; « jamais de
 * bouton supprimer le paiement »).
 *
 * Le paiement, son reçu et son numéro restent tels qu'émis (triggers : un paiement confirmé et un reçu ne se modifient ni
 * ne se suppriment) : la contre-écriture est une ligne `payment_reversals` append-only, liée au paiement, avec son motif
 * (erreur comptable documentée) et son approbation exacte (contenu : facture, paiement, montant). Elle n'est PAS une
 * sortie d'argent : un vrai remboursement est `RefundCredit`. Le solde et la `ledger_version` sont recalculés ; l'encaissé
 * net de la facture baisse du montant contre-passé.
 *
 * Refus, dans l'ordre : facture détenue par un poste (`WRITE_OWNER_REQUIRED`), facture non émise ou paiement non confirmé
 * (`STATE_TRANSITION_REFUSED`), version périmée (`VERSION_CONFLICT` avec `current_version`), cumul des contre-écritures
 * au-delà du paiement (422, `details.reversible`), encaissé net qui deviendrait inférieur aux remboursements déjà versés
 * (422 : un remboursement ne reste jamais sans encaissement), approbation absente, inexacte, échue ou déjà utilisée
 * (422 `APPROVAL_REQUIRED_OR_EXPIRED`). La gérante peut exécuter directement : son autorisation est écrite consommée sous
 * l'identifiant fourni.
 *
 * Espèces : la session du paiement est verrouillée (rang 70) pour que la clôture ne lise jamais un total à moitié
 * corrigé. Une session déjà close n'est JAMAIS réécrite : la correction ultérieure est montrée à part (vue
 * `vw_cash_session_totals`) et signalée dans l'audit (`cash_session_closed`).
 *
 * Verrous : cabinet, idempotence, visite (46) de la facture de frais d'examen si le contrôle est actif, propriété de
 * facture (50), facture (60), session de caisse (70), approbation (90).
 */
final class ReversePaymentHandler implements CommandHandler
{
    public const COMMAND = 'ReversePayment';

    public function __construct(private readonly ExactApprovals $approvals, private readonly ExamFeeVisits $visits) {}

    public function permissions(): array
    {
        return ['payment.reverse'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La contre-écriture d\'un paiement');
        if ($idempotencyId === null || $context->actorId === null) {
            throw new LogicException('Une contre-écriture exige une clé d\'idempotence et un auteur identifié.');
        }

        $payload = $command->payload;
        $invoiceId = (string) $payload['invoice_id'];
        $paymentId = (string) $payload['payment_id'];
        $reversalId = (string) $payload['reversal_id'];
        $approvalId = (string) $payload['approval_id'];
        $amount = (int) $payload['amount'];
        $at = CarbonImmutable::now('UTC');
        $errors = [];

        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            $errors['reason'] = [$violation->getMessage()];
        }
        if ($reason === null && ! isset($errors['reason'])) {
            $errors['reason'] = ['Le motif (erreur comptable constatée) est obligatoire.'];
        }
        if (DB::table('payment_reversals')->where('id', $reversalId)->exists()) {
            $errors['reversal_id'] = ['Cet identifiant de contre-écriture est déjà utilisé.'];
        }
        $peek = DB::table('payments')->where('clinic_id', $context->clinicId)->where('id', $paymentId)->first();
        if ($peek === null || (string) $peek->invoice_id !== $invoiceId) {
            $errors['payment_id'] = ['Paiement introuvable sur cette facture.'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        // 1. Visite de la facture de frais (46), propriété (50), facture (60).
        $visit = $this->visits->lockForInvoice($locks, $context->clinicId, $invoiceId);
        foreach ($locks->invoiceOwners($invoiceId) as $owner) {
            if (in_array((string) $owner->status, ['ACTIVE', 'CLOSING'], true)) {
                $name = DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $owner->device_id)->value('name');
                throw BillingErrors::writeOwnerRequired(is_string($name) ? $name : 'poste');
            }
        }
        $invoice = $locks->invoice($invoiceId);
        if ($invoice === null) {
            throw BusinessError::notFound('Facture introuvable dans ce cabinet.');
        }
        if ((string) $invoice->status !== 'ISSUED') {
            throw BusinessError::stateRefused('Seul le paiement d\'une facture émise se contre-passe.', (string) $invoice->status);
        }
        // Les paiements d'une facture ne changent que sous le verrou de cette facture : relecture fiable.
        $payment = DB::table('payments')->where('clinic_id', $context->clinicId)->where('id', $paymentId)->first();
        if ($payment === null || (string) $payment->status !== 'CONFIRMED') {
            throw BusinessError::stateRefused('Seul un paiement confirmé se contre-passe.', $payment === null ? 'UNKNOWN' : (string) $payment->status);
        }

        $before = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        if ($command->expectedVersion === null || $command->expectedVersion !== $before->ledgerVersion) {
            throw BillingErrors::versionConflict($before->ledgerVersion, ['amount_due' => $before->amountDue()], 'La facture a changé depuis votre affichage : actualisez avant de contre-passer.');
        }
        $alreadyReversed = (int) DB::table('payment_reversals')->where('clinic_id', $context->clinicId)->where('payment_id', $paymentId)->sum('amount');
        try {
            LedgerBalance::assertReversal((int) $payment->amount, $alreadyReversed, $amount);
        } catch (RuleViolation $violation) {
            throw new BusinessError('VALIDATION_FAILED', $violation->getMessage(), 422, ['amount' => [$violation->getMessage()]], $violation, [], $violation->details);
        }
        if (Settlement::paidNet($before) - $amount < $before->refundsConfirmed) {
            throw BusinessError::validation(
                ['amount' => ['Un remboursement a déjà été versé sur cette facture : cette contre-écriture laisserait un remboursement sans encaissement.']],
                'Contre-écriture refusée : l\'encaissé net deviendrait inférieur aux remboursements déjà versés.',
            );
        }

        // 2. Session de caisse du paiement en espèces (70), jamais réécrite si elle est close.
        $sessionId = $payment->cash_session_id === null ? null : (string) $payment->cash_session_id;
        $sessionClosed = false;
        if ($sessionId !== null) {
            $session = $locks->cashSession($sessionId);
            $sessionClosed = $session === null || $session->closed_at !== null;
        }

        // 3. Approbation exacte (90), consommée, ou exécution directe de la gérante.
        $authorization = $this->approvals->authorize($locks, $context, $approvalId, ApprovalSubject::paymentReversal($invoiceId, $paymentId, $amount), (string) $reason);

        // 4. Fait : contre-écriture append-only ; ni le paiement, ni son reçu, ni la facture ne sont modifiés.
        $nowDb = $at->format('Y-m-d H:i:s.u');
        DB::table('payment_reversals')->insert([
            'id' => $reversalId,
            'clinic_id' => $context->clinicId,
            'payment_id' => $paymentId,
            'amount' => $amount,
            'reason' => (string) $reason,
            'approval_id' => $approvalId,
            'recorded_at' => $nowDb,
            'created_by' => $context->actorId,
            'idempotency_id' => $idempotencyId,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);

        $after = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        $publicationVersion = InvoiceLedger::publicationVersion((int) $invoice->version, $after->ledgerVersion);
        $receiptNumber = DB::table('receipts')->where('clinic_id', $context->clinicId)->where('payment_id', $paymentId)->value('number');
        $recordedAt = $at->format('Y-m-d\TH:i:s.v\Z');

        $changes = [
            new ChangeRecord('invoices', $invoiceId, $publicationVersion, ChangeVisibility::Financial, ChangeKind::Updated),
            new ChangeRecord('payment_reversals', $reversalId, 1, ChangeVisibility::Financial, ChangeKind::Created),
        ];
        $visitChange = $this->visits->refresh($context, $visit);
        if ($visitChange !== null) {
            $changes[] = $visitChange;
        }

        return new CommandOutcome(
            result: [
                'reversal_id' => $reversalId,
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'recorded_at' => $recordedAt,
                'ledger_version' => $after->ledgerVersion,
                'balance' => Settlement::balanceArray('ISSUED', (int) $invoice->total_gross, $after),
            ],
            resultType: 'payment_reversals',
            resultId: $reversalId,
            audit: [new AuditEntry('payment.reversed', 'payments', $paymentId, $reason, [
                'invoice_id' => $invoiceId,
                'reversal_id' => $reversalId,
                'amount' => $amount,
                'payment_amount' => (int) $payment->amount,
                'already_reversed' => $alreadyReversed,
                'receipt_number' => is_string($receiptNumber) ? $receiptNumber : null,
                'approval_id' => $approvalId,
                'approval_mode' => $authorization['mode'],
                'ledger_version_before' => $before->ledgerVersion,
                'ledger_version_after' => $after->ledgerVersion,
                'balance_after' => $after->signedBalance(),
                'cash_session_id' => $sessionId,
                'cash_session_closed' => $sessionClosed,
            ])],
            changes: $changes,
        );
    }
}
