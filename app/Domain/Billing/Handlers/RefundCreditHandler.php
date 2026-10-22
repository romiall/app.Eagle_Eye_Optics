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
use App\Domain\Billing\Documents\PendingDocuments;
use App\Domain\Billing\ExamFeeVisits;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Numbering\BillingNumbers;
use App\Domain\Billing\Numbering\NumberUsages;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Billing\Rules\PaymentRules;
use App\Domain\Billing\Rules\Settlement;
use App\Domain\Billing\Snapshots\InvoiceSnapshots;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Remboursement réel d'un crédit, avec sa pièce numérotée (B08, AT14, AT25, EE024, EE030, EE045 ; fichier 03 : « Un vrai
 * remboursement est un objet distinct avec mode, référence, montant et reçu de remboursement, limité au crédit à
 * rembourser et approuvé »).
 *
 * Une SORTIE d'argent, distincte de l'avoir (qui réduit la créance) et du stock (qui ne bouge pas) : elle est liée à un
 * avoir émis de la facture, limitée au crédit réellement disponible de la facture (`refundable_credit`, recalculé sous le
 * verrou de la facture) ET à la part de cet avoir non encore remboursée. La facture, l'avoir et les reçus ne sont jamais
 * modifiés : le solde et la `ledger_version` sont des projections recalculées. Une clé d'idempotence ne rembourse
 * qu'une fois ; deux remboursements concurrents du même crédit sont sérialisés (verrou du cabinet puis de la facture) et
 * le second apprend le crédit restant.
 *
 * Espèces : session de caisse OUVERTE de l'auteur, antérieure au remboursement (rang 70 : la clôture compte la sortie) ;
 * autres modes : aucune session, référence obligatoire pour mobile money et virement (même paramétrage que
 * l'encaissement). La date effective ne peut être ni future ni d'un jour civil antérieur (le contrat n'a pas de motif de
 * saisie rétroactive) : le remboursement est constaté le jour où l'argent sort.
 *
 * Écritures : usage du numéro `TEST-RMB-AAAA-NNNNNN` (série REFUND, sans trou), remboursement avec son snapshot (reçu de
 * remboursement : avoir, facture, bénéficiaire figé, montant, mode, référence), document PDF `PENDING` (`refund.v1`).
 *
 * Verrous : cabinet, idempotence, séquence REFUND (40), visite (46) si le contrôle de l'examen est actif, propriété de
 * facture (50), facture (60), session de caisse (70), approbation (90).
 */
final class RefundCreditHandler implements CommandHandler
{
    public const COMMAND = 'RefundCredit';

    public function __construct(private readonly ExactApprovals $approvals, private readonly ExamFeeVisits $visits) {}

    public function permissions(): array
    {
        return ['refund.execute'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'Le remboursement d\'un crédit');
        if ($idempotencyId === null || $context->actorId === null) {
            throw new LogicException('Un remboursement exige une clé d\'idempotence et un auteur identifié.');
        }

        $payload = $command->payload;
        $invoiceId = (string) $payload['invoice_id'];
        $refundId = (string) $payload['refund_id'];
        $creditNoteId = (string) $payload['credit_note_id'];
        $usageId = (string) $payload['number_usage_id'];
        $documentId = (string) $payload['document_id'];
        $approvalId = (string) $payload['approval_id'];
        $amount = (int) $payload['amount'];
        $method = (string) $payload['method'];
        $sessionId = is_string($payload['cash_session_id'] ?? null) ? $payload['cash_session_id'] : null;
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $timezone = (string) $clinic->timezone;
        $errors = new FieldErrors;

        $reference = null;
        try {
            $reference = Text::optional(is_string($payload['reference'] ?? null) ? $payload['reference'] : null, 180, 'reference');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($reference === null && PaymentRules::referenceRequired($method, self::referenceRequiredMethods())) {
            $errors->add('reference', 'La référence est obligatoire pour ce mode (numéro de transaction, de virement).');
        }
        if ($method === PaymentRules::CASH && $sessionId === null) {
            $errors->add('cash_session_id', 'Un remboursement en espèces exige une session de caisse ouverte.');
        }
        if ($method !== PaymentRules::CASH && $sessionId !== null) {
            $errors->add('cash_session_id', 'Seuls les remboursements en espèces appartiennent à une session de caisse.');
        }
        foreach ([
            ['refund_id', DB::table('refunds')->where('id', $refundId)->exists(), 'remboursement'],
            ['number_usage_id', NumberUsages::isTaken($usageId), 'usage de numéro'],
            ['document_id', PendingDocuments::isTaken($documentId), 'document'],
        ] as [$field, $taken, $label]) {
            if ($taken) {
                $errors->add($field, 'Cet identifiant de '.$label.' est déjà utilisé.');
            }
        }

        $effective = null;
        try {
            $effective = Instant::parse((string) $payload['effective_at'], 'effective_at');
            $now = Instant::fromEpochMilliseconds((int) $at->format('Uv'));
            if ($effective !== null) {
                PaymentRules::assertNotInFuture($effective, $now, (int) config('eeo.offline.max_clock_skew_seconds', 300));
                if (PaymentRules::isRetroactive($effective, $now, $timezone)) {
                    $errors->add('effective_at', 'Un remboursement est constaté le jour où l\'argent sort : la date ne peut pas être d\'un jour antérieur.');
                }
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        $errors->throwIfAny();
        if ($effective === null) {
            throw new LogicException('Date effective absente sans erreur de validation.');
        }

        // 1. Numéro de remboursement (40), visite (46), propriété (50), facture (60).
        $number = BillingNumbers::allocate($locks, $context, BillingNumbers::REFUND, $timezone, $at);
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
            throw BusinessError::stateRefused('Seul le crédit d\'une facture émise se rembourse.', (string) $invoice->status);
        }
        // Les avoirs et remboursements d'une facture ne changent que sous le verrou de cette facture : relecture fiable.
        $creditNote = DB::table('credit_notes')->where('clinic_id', $context->clinicId)->where('id', $creditNoteId)->first();
        if ($creditNote === null || (string) $creditNote->invoice_id !== $invoiceId || (string) $creditNote->status !== 'ISSUED') {
            throw BusinessError::validation(['credit_note_id' => ['Avoir émis introuvable sur cette facture : un remboursement suit toujours un avoir.']]);
        }

        $before = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        if ($command->expectedVersion === null || $command->expectedVersion !== $before->ledgerVersion) {
            throw BillingErrors::versionConflict($before->ledgerVersion, ['refundable_credit' => $before->refundableCredit()], 'La facture a changé depuis votre affichage : actualisez le crédit avant de rembourser.');
        }
        try {
            $before->assertRefund($amount);
        } catch (RuleViolation $violation) {
            throw new BusinessError('VALIDATION_FAILED', $violation->getMessage(), 422, ['amount' => [$violation->getMessage()]], $violation, [], $violation->details + ['current_version' => $before->ledgerVersion]);
        }
        $refundedOnNote = (int) DB::table('refunds')->where('clinic_id', $context->clinicId)->where('credit_note_id', $creditNoteId)->sum('amount');
        $noteRemaining = max(0, (int) $creditNote->amount_gross - $refundedOnNote);
        if ($amount > $noteRemaining) {
            throw new BusinessError(
                'VALIDATION_FAILED',
                'Le remboursement dépasse la part de cet avoir non encore remboursée.',
                422,
                ['amount' => ['Le remboursement dépasse la part de cet avoir non encore remboursée.']],
                null,
                [],
                ['credit_note_refundable' => $noteRemaining, 'current_version' => $before->ledgerVersion],
            );
        }

        // 2. Session de caisse (70) pour les espèces.
        if ($sessionId !== null) {
            $session = $locks->cashSession($sessionId);
            if ($session === null) {
                throw BusinessError::validation(['cash_session_id' => ['Session de caisse introuvable dans ce cabinet.']]);
            }
            if ($session->closed_at !== null) {
                throw BusinessError::stateRefused('Cette session de caisse est clôturée : ouvrez-en une nouvelle pour rembourser en espèces.', 'CLOSED');
            }
            if ((string) $session->cashier_id !== $context->actorId) {
                throw BusinessError::validation(['cash_session_id' => ['Cette session de caisse appartient à un autre compte.']]);
            }
            if ($effective->epochMilliseconds < (int) CarbonImmutable::parse((string) $session->opened_at, 'UTC')->format('Uv')) {
                throw BusinessError::validation(['effective_at' => ['Le remboursement ne peut pas précéder l\'ouverture de sa session de caisse.']]);
            }
        }

        // 3. Approbation exacte (90), consommée, ou exécution directe de la gérante.
        $authorization = $this->approvals->authorize($locks, $context, $approvalId, ApprovalSubject::refund($invoiceId, $creditNoteId, $amount, $method), 'Remboursement de l\'avoir '.(string) $creditNote->number);

        // 4. Faits : usage du numéro, remboursement figé, document en attente. Ni la facture, ni l'avoir, ni les reçus ne
        // changent.
        $nowDb = $at->format('Y-m-d H:i:s.u');
        $effectiveDb = CarbonImmutable::createFromTimestampMsUTC($effective->epochMilliseconds)->format('Y-m-d H:i:s.u');
        NumberUsages::record($context, $usageId, BillingNumbers::REFUND, $number, 'refunds', $refundId, $at);
        $snapshot = [
            'refund_id' => $refundId,
            'number' => $number['rendered'],
            'effective_at' => $effective->toString(),
            'credit_note' => ['credit_note_id' => $creditNoteId, 'number' => (string) $creditNote->number],
            'invoice' => [
                'invoice_id' => $invoiceId,
                'number' => (string) $invoice->number,
                'issued_at' => CarbonImmutable::parse((string) $invoice->issued_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'total_gross' => (int) $invoice->total_gross,
            ],
            // Bénéficiaire : l'identité FIGÉE de la facture, jamais l'identité actuelle du dossier.
            'beneficiary' => json_decode((string) $invoice->patient_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'amount' => $amount,
            'currency' => (string) $invoice->currency,
            'method' => $method,
            'reference' => $reference,
            'created_by' => ['user_id' => $context->actorId, 'display_name' => $context->actorName],
            'clinic' => InvoiceSnapshots::clinic($clinic),
            'template_version' => PendingDocuments::REFUND_TEMPLATE,
        ];
        DB::table('refunds')->insert([
            'id' => $refundId,
            'clinic_id' => $context->clinicId,
            'invoice_id' => $invoiceId,
            'credit_note_id' => $creditNoteId,
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'approval_id' => $approvalId,
            'number' => $number['rendered'],
            'number_usage_id' => $usageId,
            'effective_at' => $effectiveDb,
            'cash_session_id' => $sessionId,
            'idempotency_id' => $idempotencyId,
            'created_by' => $context->actorId,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);
        PendingDocuments::forRefund($context, $documentId, $refundId, $at);

        $after = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        $publicationVersion = InvoiceLedger::publicationVersion((int) $invoice->version, $after->ledgerVersion);
        $changes = [
            new ChangeRecord('invoices', $invoiceId, $publicationVersion, ChangeVisibility::Financial, ChangeKind::Updated),
            new ChangeRecord('refunds', $refundId, 1, ChangeVisibility::Financial, ChangeKind::Created),
        ];
        $visitChange = $this->visits->refresh($context, $visit);
        if ($visitChange !== null) {
            $changes[] = $visitChange;
        }

        return new CommandOutcome(
            result: [
                'refund_id' => $refundId,
                'number' => $number['rendered'],
                'invoice_id' => $invoiceId,
                'credit_note_id' => $creditNoteId,
                'amount' => $amount,
                'method' => $method,
                'effective_at' => $effective->toString(),
                'ledger_version' => $after->ledgerVersion,
                'balance' => Settlement::balanceArray('ISSUED', (int) $invoice->total_gross, $after),
                'document' => ['document_id' => $documentId, 'document_status' => 'PENDING'],
            ],
            resultType: 'refunds',
            resultId: $refundId,
            audit: [new AuditEntry('refund.executed', 'refunds', $refundId, null, [
                'invoice_id' => $invoiceId,
                'credit_note_id' => $creditNoteId,
                'credit_note_number' => (string) $creditNote->number,
                'number' => $number['rendered'],
                'amount' => $amount,
                'method' => $method,
                'cash_session_id' => $sessionId,
                'approval_id' => $approvalId,
                'approval_mode' => $authorization['mode'],
                'ledger_version_before' => $before->ledgerVersion,
                'ledger_version_after' => $after->ledgerVersion,
                'balance_after' => $after->signedBalance(),
            ])],
            changes: $changes,
        );
    }

    /**
     * Modes dont la référence est obligatoire (même paramétrage que l'encaissement : `eeo.billing.payment_reference_required`).
     *
     * @return list<string>
     */
    private static function referenceRequiredMethods(): array
    {
        $configured = config('eeo.billing.payment_reference_required');
        if (! is_array($configured)) {
            return PaymentRules::DEFAULT_REFERENCE_REQUIRED;
        }

        return array_values(array_filter($configured, is_string(...)));
    }
}
