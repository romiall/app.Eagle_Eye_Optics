<?php

namespace App\Domain\Billing\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Effects\OutboxEvent;
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
use App\Domain\Billing\Rules\CreditNoteRules;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Billing\Rules\Settlement;
use App\Domain\Billing\Snapshots\InvoiceSnapshots;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Avoir lié aux lignes d'une facture émise (B07, AT14, AT24, EE017, EE029, EE045 ; fichier 03 : « Une facture émise est
 * corrigée par avoir partiel ou total lié aux lignes d'origine, motif et autorisation gérante. Ne jamais effacer la
 * vente »).
 *
 * Une RÉDUCTION de la créance : ni la facture, ni ses lignes, ni ses reçus ne changent (triggers d'immuabilité) ; le
 * stock non plus (un avoir financier sans retour physique ne modifie pas le stock ; un retour est
 * `RegisterPhysicalReturn`, une erreur d'encaissement est `ReversePayment`). Le solde et la `ledger_version` sont
 * recalculés : un avoir au-delà du reste dû fait apparaître un crédit à rembourser (`IN_CREDIT`), jamais une dette
 * négative cachée. Le CA de la période de l'avoir baisse à la date de l'avoir (vue `vw_revenue_events`), la vente
 * d'origine reste datée de son émission.
 *
 * Contrôles : chaque ligne vise une ligne de CETTE facture, une seule fois par avoir ; cumul des montants (et des
 * quantités indiquées) des avoirs émis ≤ ligne d'origine ; somme des lignes = montant de l'avoir ≤ part non créditée de
 * la facture ; motif obligatoire ; approbation exacte (contenu : facture, montant, lignes) consommée une fois, ou
 * exécution directe de la gérante. Taxe de chaque ligne : part de la taxe figée de la ligne d'origine (aucune taxe
 * inventée).
 *
 * Écritures dans l'ordre imposé par les triggers (« enfants écrits avant l'émission ») : avoir en brouillon, lignes,
 * usage du numéro `TEST-AV-AAAA-NNNNNN` (série CREDIT_NOTE, aucun trou : tout est annulé en cas d'échec), document PDF
 * `PENDING` (`credit_note.v1`), puis UNE mise à jour `DRAFT` → `ISSUED` avec le snapshot. Événement `CreditNoteIssued`.
 *
 * Verrous : cabinet, idempotence, séquence CREDIT_NOTE (40), visite (46) si le contrôle de l'examen est actif, propriété
 * de facture (50), facture (60), approbation (90).
 */
final class IssueCreditNoteHandler implements CommandHandler
{
    public const COMMAND = 'IssueCreditNote';

    public function __construct(private readonly ExactApprovals $approvals, private readonly ExamFeeVisits $visits) {}

    public function permissions(): array
    {
        return ['credit_note.issue'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'L\'émission d\'un avoir');
        if ($idempotencyId === null || $context->actorId === null) {
            throw new LogicException('Un avoir exige une clé d\'idempotence et un auteur identifié.');
        }

        $payload = $command->payload;
        $invoiceId = (string) $payload['invoice_id'];
        $creditNoteId = (string) $payload['credit_note_id'];
        $usageId = (string) $payload['number_usage_id'];
        $documentId = (string) $payload['document_id'];
        $approvalId = (string) $payload['approval_id'];
        $amount = (int) $payload['amount_gross'];
        /** @var list<array<string, mixed>> $payloadLines */
        $payloadLines = array_values(is_array($payload['lines'] ?? null) ? $payload['lines'] : []);
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $errors = new FieldErrors;

        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($reason === null) {
            $errors->add('reason', 'Le motif de l\'avoir est obligatoire.');
        }
        foreach ([
            ['credit_note_id', DB::table('credit_notes')->where('id', $creditNoteId)->exists(), 'avoir'],
            ['number_usage_id', NumberUsages::isTaken($usageId), 'usage de numéro'],
            ['document_id', PendingDocuments::isTaken($documentId), 'document'],
        ] as [$field, $taken, $label]) {
            if ($taken) {
                $errors->add($field, 'Cet identifiant de '.$label.' est déjà utilisé.');
            }
        }

        /** @var array<int, array{id: string, invoice_line_id: string, quantity: ?FixedDecimal, amount: int, reason: ?string}> $lines */
        $lines = [];
        $seen = [];
        foreach ($payloadLines as $index => $line) {
            $lineId = (string) ($line['credit_note_line_id'] ?? '');
            $invoiceLineId = (string) ($line['invoice_line_id'] ?? '');
            if (isset($seen[$invoiceLineId])) {
                $errors->add('lines.'.$index.'.invoice_line_id', 'Une ligne d\'origine figure une seule fois dans un avoir.');
            }
            $seen[$invoiceLineId] = true;
            if (DB::table('credit_note_lines')->where('id', $lineId)->exists()) {
                $errors->add('lines.'.$index.'.credit_note_line_id', 'Cet identifiant de ligne d\'avoir est déjà utilisé.');
            }
            $quantity = null;
            $lineReason = null;
            try {
                $quantity = Quantity::parse(is_string($line['quantity'] ?? null) ? $line['quantity'] : null);
                $lineReason = Text::optional(is_string($line['reason'] ?? null) ? $line['reason'] : null, 4000, 'reason');
            } catch (RuleViolation $violation) {
                $errors->addViolation('lines.'.$index, $violation);
            }
            $lines[$index] = ['id' => $lineId, 'invoice_line_id' => $invoiceLineId, 'quantity' => $quantity, 'amount' => (int) ($line['amount_gross'] ?? 0), 'reason' => $lineReason];
        }
        try {
            CreditNoteRules::assertTotal($amount, array_values(array_map(static fn (array $line): int => $line['amount'], $lines)));
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        $errors->throwIfAny();

        // 1. Numéro d'avoir (40), visite (46), propriété (50), facture (60).
        $number = BillingNumbers::allocate($locks, $context, BillingNumbers::CREDIT_NOTE, (string) $clinic->timezone, $at);
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
            throw BusinessError::stateRefused('Seule une facture émise se corrige par avoir (un brouillon se modifie).', (string) $invoice->status);
        }

        $before = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        if ($command->expectedVersion === null || $command->expectedVersion !== $before->ledgerVersion) {
            throw BillingErrors::versionConflict($before->ledgerVersion, ['amount_due' => $before->amountDue()], 'La facture a changé depuis votre affichage : actualisez avant d\'émettre l\'avoir.');
        }
        try {
            $before->assertCreditNote($amount);
        } catch (RuleViolation $violation) {
            throw new BusinessError('VALIDATION_FAILED', $violation->getMessage(), 422, ['amount_gross' => [$violation->getMessage()]], $violation, [], $violation->details);
        }

        // 2. Plafonds par ligne d'origine, sous le verrou de la facture (les avoirs d'une facture ne changent que sous lui).
        $invoiceLines = DB::table('invoice_lines')->where('clinic_id', $context->clinicId)->where('invoice_id', $invoiceId)->get()->keyBy('id')->all();
        $credited = [];
        foreach (DB::table('credit_note_lines')
            ->join('credit_notes', static function ($join): void {
                $join->on('credit_notes.clinic_id', '=', 'credit_note_lines.clinic_id')->on('credit_notes.id', '=', 'credit_note_lines.credit_note_id');
            })
            ->where('credit_note_lines.clinic_id', $context->clinicId)
            ->where('credit_note_lines.invoice_id', $invoiceId)
            ->where('credit_notes.status', 'ISSUED')
            ->get(['credit_note_lines.invoice_line_id', 'credit_note_lines.amount_gross', 'credit_note_lines.tax_amount', 'credit_note_lines.quantity']) as $row) {
            $key = (string) $row->invoice_line_id;
            $credited[$key] ??= ['amount' => 0, 'tax' => 0, 'quantity' => Quantity::of('0')];
            $credited[$key]['amount'] += (int) $row->amount_gross;
            $credited[$key]['tax'] += (int) ($row->tax_amount ?? 0);
            if ($row->quantity !== null) {
                $credited[$key]['quantity'] = $credited[$key]['quantity']->plus(Quantity::of((string) $row->quantity));
            }
        }
        $lineErrors = new FieldErrors;
        /** @var array<string, int|string|null> $lineDetails valeurs actualisées (reste créditable) du premier refus */
        $lineDetails = [];
        $computed = [];
        foreach ($lines as $index => $line) {
            $origin = $invoiceLines[$line['invoice_line_id']] ?? null;
            if ($origin === null) {
                $lineErrors->add('lines.'.$index.'.invoice_line_id', 'Cette ligne n\'appartient pas à la facture.');

                continue;
            }
            $already = $credited[$line['invoice_line_id']] ?? ['amount' => 0, 'tax' => 0, 'quantity' => Quantity::of('0')];
            try {
                CreditNoteRules::assertLine((int) $origin->line_total_gross, Quantity::of((string) $origin->quantity), $already['amount'], $already['quantity'], $line['amount'], $line['quantity']);
            } catch (RuleViolation $violation) {
                $lineErrors->addViolation('lines.'.$index, $violation);
                $lineDetails += $violation->details;

                continue;
            }
            $computed[$index] = $line + [
                'tax' => CreditNoteRules::includedTax($line['amount'], (int) $origin->line_total_gross, $origin->tax_amount === null ? null : (int) $origin->tax_amount, $already['amount'], $already['tax']),
                'description' => (string) $origin->description_snapshot,
            ];
        }
        if (! $lineErrors->isEmpty()) {
            throw new BusinessError('VALIDATION_FAILED', 'Certaines lignes de l\'avoir dépassent ou ne visent pas leur ligne d\'origine.', 422, $lineErrors->all(), null, [], $lineDetails);
        }

        // 3. Approbation exacte (90), consommée, ou exécution directe de la gérante.
        $subjectLines = array_values(array_map(static fn (array $line): array => [
            'invoice_line_id' => $line['invoice_line_id'],
            'quantity' => $line['quantity']?->canonical(),
            'amount_gross' => $line['amount'],
        ], $lines));
        $authorization = $this->approvals->authorize($locks, $context, $approvalId, ApprovalSubject::creditNote($invoiceId, $amount, $subjectLines), (string) $reason);

        // 4. Faits : avoir en brouillon, lignes, usage du numéro, document en attente, puis émission figée.
        $nowDb = $at->format('Y-m-d H:i:s.u');
        DB::table('credit_notes')->insert([
            'id' => $creditNoteId,
            'clinic_id' => $context->clinicId,
            'invoice_id' => $invoiceId,
            'number' => null,
            'issued_at' => null,
            'issued_by' => null,
            'number_usage_id' => null,
            'status' => 'DRAFT',
            'amount_gross' => $amount,
            'reason' => (string) $reason,
            'approval_id' => $approvalId,
            'snapshot' => null,
            'idempotency_id' => $idempotencyId,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);
        foreach ($computed as $line) {
            DB::table('credit_note_lines')->insert([
                'id' => $line['id'],
                'clinic_id' => $context->clinicId,
                'credit_note_id' => $creditNoteId,
                'invoice_id' => $invoiceId,
                'invoice_line_id' => $line['invoice_line_id'],
                'quantity' => $line['quantity']?->canonical(),
                'amount_gross' => $line['amount'],
                'tax_amount' => $line['tax'],
                'reason' => $line['reason'],
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
            ]);
        }
        NumberUsages::record($context, $usageId, BillingNumbers::CREDIT_NOTE, $number, 'credit_notes', $creditNoteId, $at);
        PendingDocuments::forCreditNote($context, $documentId, $creditNoteId, $at);

        $issuedAt = $at->format('Y-m-d\TH:i:s.v\Z');
        $snapshot = [
            'credit_note_id' => $creditNoteId,
            'number' => $number['rendered'],
            'issued_at' => $issuedAt,
            'invoice' => [
                'invoice_id' => $invoiceId,
                'number' => (string) $invoice->number,
                'issued_at' => CarbonImmutable::parse((string) $invoice->issued_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'total_gross' => (int) $invoice->total_gross,
            ],
            'patient' => json_decode((string) $invoice->patient_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'lines' => array_values(array_map(static fn (array $line): array => [
                'invoice_line_id' => $line['invoice_line_id'],
                'description' => $line['description'],
                'quantity' => $line['quantity']?->canonical(),
                'amount_gross' => $line['amount'],
                'tax_amount' => $line['tax'],
            ], $computed)),
            'amount_gross' => $amount,
            'reason' => (string) $reason,
            'issued_by' => ['user_id' => $context->actorId, 'display_name' => $context->actorName],
            'approval_id' => $approvalId,
            // Régime fiscal FIGÉ de la facture d'origine : l'avoir n'en invente ni n'en change aucun.
            'tax' => json_decode((string) $invoice->tax_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'clinic' => InvoiceSnapshots::clinic($clinic),
            'template_version' => PendingDocuments::CREDIT_NOTE_TEMPLATE,
        ];
        DB::table('credit_notes')->where('clinic_id', $context->clinicId)->where('id', $creditNoteId)->update([
            'number' => $number['rendered'],
            'issued_at' => $nowDb,
            'issued_by' => $context->actorId,
            'number_usage_id' => $usageId,
            'status' => 'ISSUED',
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $nowDb,
        ]);

        $after = InvoiceLedger::load($context->clinicId, $invoiceId, (int) $invoice->total_gross);
        $publicationVersion = InvoiceLedger::publicationVersion((int) $invoice->version, $after->ledgerVersion);
        $changes = [
            new ChangeRecord('invoices', $invoiceId, $publicationVersion, ChangeVisibility::Financial, ChangeKind::Updated),
            new ChangeRecord('credit_notes', $creditNoteId, 1, ChangeVisibility::Financial, ChangeKind::Created),
        ];
        $visitChange = $this->visits->refresh($context, $visit);
        if ($visitChange !== null) {
            $changes[] = $visitChange;
        }

        return new CommandOutcome(
            result: [
                'credit_note_id' => $creditNoteId,
                'number' => $number['rendered'],
                'invoice_id' => $invoiceId,
                'amount_gross' => $amount,
                'issued_at' => $issuedAt,
                'ledger_version' => $after->ledgerVersion,
                'balance' => Settlement::balanceArray('ISSUED', (int) $invoice->total_gross, $after),
                'document' => ['document_id' => $documentId, 'document_status' => 'PENDING'],
            ],
            resultType: 'credit_notes',
            resultId: $creditNoteId,
            audit: [new AuditEntry('credit_note.issued', 'credit_notes', $creditNoteId, $reason, [
                'invoice_id' => $invoiceId,
                'invoice_number' => (string) $invoice->number,
                'number' => $number['rendered'],
                'amount_gross' => $amount,
                'lines' => count($computed),
                'approval_id' => $approvalId,
                'approval_mode' => $authorization['mode'],
                'ledger_version_before' => $before->ledgerVersion,
                'ledger_version_after' => $after->ledgerVersion,
                'balance_after' => $after->signedBalance(),
                'stock_unchanged' => true,
            ])],
            events: [new OutboxEvent('CreditNoteIssued', 1, 'invoices', $invoiceId, $publicationVersion, [
                'credit_note_id' => $creditNoteId,
                'invoice_id' => $invoiceId,
                'number' => $number['rendered'],
                'amount_gross' => $amount,
                'currency' => (string) $invoice->currency,
                'issued_at' => $issuedAt,
            ])],
            changes: $changes,
        );
    }
}
