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
use App\Domain\Billing\BillingErrors;
use App\Domain\Billing\Catalog\CatalogSnapshot;
use App\Domain\Billing\Catalog\TaxRegime;
use App\Domain\Billing\Documents\PendingDocuments;
use App\Domain\Billing\ExamFeeScope;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceDrafts;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Numbering\BillingNumbers;
use App\Domain\Billing\Numbering\NumberUsages;
use App\Domain\Billing\Rules\DraftLine;
use App\Domain\Billing\Rules\DraftLines;
use App\Domain\Billing\Rules\DraftPolicy;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Snapshots\InvoiceSnapshots;
use App\Domain\Inventory\ReservationRefused;
use App\Domain\Inventory\ReservationRequest;
use App\Domain\Inventory\ReservationService;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Émission d'une facture IMMUABLE (B03, EE021, EE023, EE045, AT08, AT09, AT37). Dans UNE transaction, sous le verrou du
 * cabinet, ce que le fichier 03 demande : validation des lignes, du patient et des taxes ; numéro définitif ;
 * snapshot de l'identité facturée, du cabinet, du catalogue (prix, désignation) et de la taxe ; réserve du stock des
 * articles stockés ; création du document PENDING ; audit, événement et journal des changements (chemin central).
 * Toute erreur annule l'ensemble, numéro compris : aucun trou, aucune réserve partielle.
 *
 * Ordre des écritures (triggers du schéma) : les ENFANTS d'abord, avec leurs snapshots (lignes recalculées et
 * figées, réserves, usage du numéro, document), puis le parent passe de `DRAFT` à `ISSUED` en UNE mise à jour. Dès
 * lors, plus aucune mise à jour de la facture ni de ses lignes n'est possible (guard `Issued or finalized parent`).
 * `invoices.version` reste la version DU BROUILLON, figée ; le solde, `ledger_version` et l'état de règlement sont
 * des projections des faits financiers qui suivront, jamais des colonnes de cette ligne.
 *
 * Route directe : le serveur attribue le numéro central, choisit positions et lots de la réserve, et n'attribue aucun
 * propriétaire de facture (aucun poste n'écrit cette facture). `total_gross` du client est une valeur À VÉRIFIER :
 * le serveur recalcule et refuse l'écart. `issued_at` du client doit être plausible (pas dans le futur au-delà de la
 * tolérance d'horloge) ; la date de la pièce est celle du serveur, relue APRÈS le verrou du cabinet (l'année du
 * numéro est celle de l'émission, au fuseau du cabinet).
 *
 * Document de production : refusé tant que le régime de taxe d'une ligne n'est pas validé (`test_document` faux
 * exige une règle fiscale applicable sur chaque ligne). Un document TEST peut porter `NO_TAX_CONFIGURED`.
 *
 * Verrous : cabinet, idempotence, séquence INVOICE (40), dossier patient (45), propriété de facture (50), facture
 * (60), positions (80), lots (81).
 */
final class IssueInvoiceHandler implements CommandHandler
{
    public const COMMAND = 'IssueInvoice';

    public function __construct(
        private readonly InvoiceDrafts $drafts,
        private readonly ReservationService $reservations,
        private readonly ExamFeeScope $examFee,
    ) {}

    public function permissions(): array
    {
        return ['invoice.issue', 'exam_fee.collect'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'L\'émission d\'une facture');

        $payload = $command->payload;
        $invoiceId = (string) $payload['invoice_id'];
        $usageId = (string) $payload['number_usage_id'];
        $documentId = (string) $payload['document_id'];
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $timezone = (string) $clinic->timezone;

        $errors = new FieldErrors;
        if (($payload['write_owner_id'] ?? null) !== null) {
            $errors->add('write_owner_id', 'Par la route directe, aucun poste ne détient la facture : laissez ce champ vide.');
        }
        if (($payload['reservations'] ?? []) !== []) {
            $errors->add('reservations', 'Par la route directe, le serveur choisit les positions et les lots : laissez cette liste vide.');
        }
        if (NumberUsages::isTaken($usageId)) {
            $errors->add('number_usage_id', 'Cet identifiant d\'usage de numéro est déjà utilisé.');
        }
        if (PendingDocuments::isTaken($documentId)) {
            $errors->add('document_id', 'Cet identifiant de document est déjà utilisé.');
        }
        try {
            $claimed = Instant::parse((string) $payload['issued_at'], 'issued_at');
            $skew = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
            if ($claimed !== null && $claimed->epochMilliseconds > ((int) $at->format('Uv')) + $skew) {
                $errors->add('issued_at', 'L\'horloge du poste est en avance sur celle du serveur : vérifiez l\'heure de l\'appareil.');
            }
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        $errors->throwIfAny();

        // 1. Numéro définitif : séquence (rang 40). Un échec plus loin annule la séquence avec la transaction.
        $number = BillingNumbers::allocate($locks, $context, BillingNumbers::INVOICE, $timezone, $at);

        // 2. Patient (45), propriété (50) et facture (60), dans l'ordre du contrat. Lecture préalable sans verrou
        //    pour connaître le patient : le cabinet est verrouillé, rien ne peut changer entre les deux lectures.
        $peek = DB::table('invoices')->where('clinic_id', $context->clinicId)->where('id', $invoiceId)->first();
        if ($peek === null) {
            throw BusinessError::notFound('Facture introuvable dans ce cabinet.');
        }
        $patientId = (string) $peek->patient_id;
        $patient = $locks->patient($patientId);
        if ($patient === null) {
            throw BusinessError::notFound('Patient introuvable dans ce cabinet.');
        }
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
        InvoiceDrafts::assertDraft($invoice);
        if ($command->expectedVersion === null || (int) $invoice->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $invoice->version);
        }
        if ($this->examFee->restricted($context, 'invoice.issue')) {
            $this->examFee->assertExamFeeInvoice($context->clinicId, $invoiceId);
        }
        if ($patient->merged_into_patient_id !== null) {
            throw BusinessError::validation(['patient_id' => ['Ce dossier a été fusionné : la facture ne peut plus être émise sur cette fiche.']]);
        }

        // 3. Lignes : recalcul et figement (mêmes règles que le brouillon, sans règle de droit).
        $rows = DB::table('invoice_lines')->where('clinic_id', $context->clinicId)->where('invoice_id', $invoiceId)->orderBy('position')->get()->all();
        if ($rows === []) {
            throw BusinessError::validation(['lines' => ['Une facture sans ligne ne s\'émet pas.']]);
        }
        $lines = $this->drafts->validated($context->clinicId, $invoiceId, $patientId, DraftLines::fromStored($rows), DraftPolicy::trusted(), false);
        try {
            $total = DraftLines::total($lines);
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['lines' => [$violation->getMessage()]]);
        }
        if ($total !== (int) $payload['total_gross']) {
            throw new BusinessError(
                'VALIDATION_FAILED',
                'Le total transmis diffère du total recalculé par le serveur : actualisez la facture.',
                422,
                ['total_gross' => ['Le total recalculé est '.$total.' FCFA.']],
                null,
                [],
                ['expected_total' => $total],
            );
        }

        $testDocument = InvoiceSnapshots::isTestDocument($clinic);
        if (! $testDocument) {
            $untaxed = new FieldErrors;
            foreach ($lines as $index => $line) {
                if ($line->taxRuleId === null) {
                    $untaxed->add('lines.'.$index.'.catalog_item_id', 'Document de production : cette ligne n\'a aucune règle fiscale validée.');
                }
            }
            $untaxed->throwIfAny();
        }
        $ruleIds = array_values(array_filter(array_map(static fn (DraftLine $line): ?string => $line->taxRuleId, $lines)));
        $usedRules = TaxRegime::applicable($context->clinicId, $ruleIds);

        $this->drafts->write($context->clinicId, $invoiceId, $patientId, $lines, $at, true);

        // 4. Réserve du stock (positions 80, lots 81), atomique pour toutes les lignes.
        $requests = [];
        $lineIndex = [];
        foreach ($lines as $index => $line) {
            $lineIndex[$line->id] = $index;
            if ($line->stocked && $line->catalogItemId !== null) {
                $requests[] = new ReservationRequest($line->id, $line->catalogItemId, $line->quantity, $line->requiresExpiry);
            }
        }
        $localDate = Instant::fromEpochMilliseconds((int) $at->format('Uv'))->civilDate($timezone);
        try {
            $reserved = $this->reservations->reserveCentral($context->clinicId, $locks, $localDate, $requests, $at);
        } catch (ReservationRefused $refusal) {
            throw $refusal->toBusinessError('lines.'.($lineIndex[$refusal->invoiceLineId] ?? 0).'.quantity');
        }

        // 5. Pièces enfants, puis le parent passe à ISSUED.
        NumberUsages::record($context, $usageId, BillingNumbers::INVOICE, $number, 'invoices', $invoiceId, $at);
        $nowDb = $at->format('Y-m-d H:i:s.u');
        $catalog = CatalogSnapshot::of($context->clinicId);

        DB::table('invoices')->where('clinic_id', $context->clinicId)->where('id', $invoiceId)->update([
            'number' => $number['rendered'],
            'issued_at' => $nowDb,
            'issued_by' => $context->actorId,
            'number_usage_id' => $usageId,
            'status' => 'ISSUED',
            'total_gross' => $total,
            'patient_snapshot' => self::json(InvoiceSnapshots::patient($patient)),
            'clinic_snapshot' => self::json(InvoiceSnapshots::clinic($clinic)),
            'tax_snapshot' => self::json(InvoiceSnapshots::tax($usedRules)),
            'updated_at' => $nowDb,
        ]);
        PendingDocuments::forInvoice($context, $documentId, $invoiceId, $at);

        $ledger = LedgerBalance::of($total, [], [], [], []);
        $draftVersion = (int) $invoice->version;
        $publicationVersion = InvoiceLedger::publicationVersion($draftVersion, $ledger->ledgerVersion);
        $issuedAt = $at->format('Y-m-d\TH:i:s.v\Z');

        return new CommandOutcome(
            result: [
                'invoice_id' => $invoiceId,
                'number' => $number['rendered'],
                'draft_version' => $draftVersion,
                'ledger_version' => $ledger->ledgerVersion,
                'total_gross' => $total,
                'document_id' => $documentId,
                'document_status' => 'PENDING',
            ],
            resultType: 'invoices',
            resultId: $invoiceId,
            audit: [new AuditEntry('invoice.issued', 'invoices', $invoiceId, null, [
                'number' => $number['rendered'],
                'patient_id' => $patientId,
                'total_gross' => $total,
                'lines' => count($lines),
                'reservations' => count($reserved),
                'test_document' => $testDocument,
                'tax_regime' => InvoiceSnapshots::tax($usedRules)['regime'],
                'catalog_snapshot_sha256' => $catalog['sha256'],
                'client_catalog_snapshot_sha256' => (string) $payload['catalog_snapshot_sha256'],
                'client_issued_at' => (string) $payload['issued_at'],
            ])],
            events: [new OutboxEvent('InvoiceIssued', 1, 'invoices', $invoiceId, $publicationVersion, [
                'invoice_id' => $invoiceId,
                'patient_id' => $patientId,
                'number' => $number['rendered'],
                'issued_at' => $issuedAt,
                'total_gross' => $total,
                'currency' => (string) $invoice->currency,
                'source_device_id' => null,
            ])],
            changes: [new ChangeRecord('invoices', $invoiceId, $publicationVersion, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function json(array $snapshot): string
    {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
