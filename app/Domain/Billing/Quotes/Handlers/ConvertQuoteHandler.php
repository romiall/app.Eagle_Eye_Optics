<?php

namespace App\Domain\Billing\Quotes\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\BillingErrors;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceDrafts;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Quotes;
use App\Domain\Billing\Rules\DraftLines;
use App\Domain\Billing\Rules\DraftPolicy;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Conversion d'une révision CHOISIE d'un devis en BROUILLON de facture (L1D07 ; fichier 06 : « La conversion copie les
 * données validées et conserve le lien d'origine » ; « L'action de conversion contrôle le destinataire, les prix et
 * l'absence de conversion accidentelle répétée »).
 *
 * - Destinataire : le patient du devis ; pour un prospect, un dossier patient choisi explicitement (`patient_id`), que
 *   le prospect rejoint (`prospect_contacts.patient_id`). Une fiche archivée ou fusionnée est refusée.
 * - Prix validés explicitement : `prices_confirmed` vaut vrai (l'écran a montré, ligne par ligne, le prix du devis et
 *   le prix actuel du catalogue) et `price_basis` choisit la base : `QUOTE` reprend prix et remises de la révision,
 *   `CATALOG` prend le prix actuel du catalogue sans remise (une ligne hors catalogue garde le prix du devis). Les
 *   lignes passent par les règles du brouillon (`InvoiceDrafts`, article actif, quantité exacte), recalculées telles
 *   qu'une personne habilitée les a écrites (`DraftPolicy::trusted()`, comme l'émission d'une facture).
 * - Aucune conversion accidentelle répétée : la même clé d'idempotence rejoue la même réponse ; une autre clé sur un
 *   devis déjà converti est refusée (409 `STATE_TRANSITION_REFUSED`, `details.invoice_id` = le brouillon existant).
 *   Seul un brouillon issu du devis puis ABANDONNÉ rouvre la conversion.
 * - Rien d'automatique au-delà : la facture reste un BROUILLON (ni numéro, ni créance, ni réserve de stock, ni
 *   paiement) ; elle s'émet ensuite par le parcours habituel (`IssueInvoice`), avec ses propres contrôles.
 *
 * Commande EN LIGNE. Verrous : cabinet, idempotence, dossier patient (45), devis (55), facture (60).
 */
final class ConvertQuoteHandler implements CommandHandler
{
    public const COMMAND = 'ConvertQuote';

    public function __construct(private readonly InvoiceDrafts $drafts) {}

    public function permissions(): array
    {
        return ['invoice.create'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La conversion d\'un devis');

        $payload = $command->payload;
        $quoteId = (string) $payload['quote_id'];
        $invoiceId = (string) $payload['invoice_id'];
        $revisionNo = (int) $payload['revision_no'];
        $basis = (string) $payload['price_basis'];
        $chosenPatient = is_string($payload['patient_id'] ?? null) ? $payload['patient_id'] : null;
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $errors = new FieldErrors;

        $peek = DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->first();
        if ($peek === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }

        // 1. Destinataire de la facture : le patient du devis, ou le dossier choisi pour un prospect.
        $patientId = $peek->patient_id === null ? $chosenPatient : (string) $peek->patient_id;
        if ($peek->patient_id !== null && $chosenPatient !== null && $chosenPatient !== (string) $peek->patient_id) {
            $errors->add('patient_id', 'Ce devis vise déjà un patient : la facture est établie pour lui.');
        }
        if ($patientId === null) {
            $errors->add('patient_id', 'Ce devis vise un prospect : choisissez le dossier patient qui recevra la facture.');
        }
        $errors->throwIfAny();
        $patientId = (string) $patientId;

        $patient = $locks->patient($patientId);
        if ($patient === null) {
            $errors->add('patient_id', 'Patient introuvable dans ce cabinet.');
        } elseif ($patient->archived_at !== null || $patient->merged_into_patient_id !== null) {
            $errors->add('patient_id', 'Cette fiche est archivée ou fusionnée : ouvrez la fiche patient actuelle.');
        }

        // 2. Devis (55) : état, validité, version.
        $quote = $locks->quote($quoteId);
        if ($quote === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }
        $status = (string) $quote->status;
        if ($status === 'DRAFT') {
            throw BusinessError::stateRefused('Ce devis est un brouillon : émettez-le avant de le convertir.', 'DRAFT');
        }
        if ($status === 'DECLINED') {
            throw BusinessError::stateRefused('Ce devis a été refusé : révisez-le avant de le convertir.', 'DECLINED');
        }
        if ($status === 'CONVERTED') {
            $existing = Quotes::activeInvoice($context->clinicId, $quoteId);
            if ($existing !== null) {
                throw new BusinessError(
                    'STATE_TRANSITION_REFUSED',
                    'Ce devis est déjà converti en facture : ouvrez la facture existante plutôt que d\'en créer une seconde.',
                    409,
                    [],
                    null,
                    [],
                    ['current_status' => 'CONVERTED', 'invoice_id' => (string) $existing->id],
                );
            }
        }
        if (Quotes::isExpired($quote, Quotes::today($clinic, $at))) {
            throw BusinessError::stateRefused('Ce devis a expiré : révisez-le avec une nouvelle date de validité avant de le convertir.', 'EXPIRED');
        }
        if ($command->expectedVersion === null || (int) $quote->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $quote->version);
        }

        if (($payload['prices_confirmed'] ?? false) !== true) {
            $errors->add('prices_confirmed', 'Confirmez les prix de chaque ligne avant de créer la facture.');
        }
        $revision = DB::table('quote_revisions')->where('clinic_id', $context->clinicId)->where('quote_id', $quoteId)->where('revision_no', $revisionNo)->first();
        if ($revision === null) {
            $errors->add('revision_no', 'Cette révision du devis n\'existe pas.');
        }

        $prospect = null;
        if ($quote->patient_id === null) {
            $prospect = DB::table('prospect_contacts')->where('clinic_id', $context->clinicId)->where('id', (string) $quote->prospect_contact_id)->first();
            if ($prospect !== null && $prospect->patient_id !== null && (string) $prospect->patient_id !== $patientId) {
                $errors->add('patient_id', 'Ce prospect est déjà rattaché à un autre dossier patient.');
            }
        }

        // 3. Facture (60) : identifiant libre.
        $locks->invoice($invoiceId);
        if (DB::table('invoices')->where('id', $invoiceId)->exists()) {
            $errors->add('invoice_id', 'Cet identifiant de facture est déjà utilisé.');
        }
        $errors->throwIfAny();
        if ($revision === null) {
            throw BusinessError::validation(['revision_no' => ['Cette révision du devis n\'existe pas.']]);
        }

        // 4. Lignes de la révision choisie, sur la base de prix confirmée.
        /** @var array{lines: list<array<string, mixed>>} $snapshot */
        $snapshot = json_decode((string) $revision->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn (array $line): ?string => is_string($line['catalog_item_id'] ?? null) ? $line['catalog_item_id'] : null,
            $snapshot['lines'],
        ))));
        $prices = $itemIds === [] ? [] : DB::table('catalog_items')->where('clinic_id', $context->clinicId)->whereIn('id', $itemIds)->pluck('sale_price', 'id')->all();

        $payloadLines = [];
        $priceChanges = 0;
        foreach ($snapshot['lines'] as $line) {
            $itemId = is_string($line['catalog_item_id'] ?? null) ? $line['catalog_item_id'] : null;
            $current = $itemId !== null && isset($prices[$itemId]) ? (int) $prices[$itemId] : null;
            if ($current !== null && $current !== (int) $line['unit_price_gross']) {
                $priceChanges++;
            }
            $fromCatalog = $basis === 'CATALOG' && $current !== null;
            $payloadLines[] = [
                'line_id' => Ulid::generate(),
                'position' => (int) $line['position'],
                'catalog_item_id' => $itemId,
                'description' => (string) $line['description'],
                'quantity' => (string) $line['quantity'],
                'unit_price_gross' => $fromCatalog ? $current : (int) $line['unit_price_gross'],
                'discount_gross' => $fromCatalog ? 0 : (int) $line['discount_gross'],
                'prescription_id' => null,
            ];
        }
        if ($payloadLines === []) {
            throw BusinessError::validation(['revision_no' => ['Cette révision ne compte aucune ligne.']]);
        }

        $lines = $this->drafts->validated($context->clinicId, $invoiceId, $patientId, $payloadLines, DraftPolicy::trusted(), true);
        try {
            $total = DraftLines::total($lines);
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['lines' => [$violation->getMessage()]]);
        }

        // 5. Brouillon de facture lié au devis, sans aucun autre effet.
        $now = $at->format('Y-m-d H:i:s.u');
        DB::table('invoices')->insert([
            'id' => $invoiceId,
            'clinic_id' => $context->clinicId,
            'patient_id' => $patientId,
            'visit_id' => null,
            'quote_id' => $quoteId,
            'number' => null,
            'issued_at' => null,
            'issued_by' => null,
            'number_usage_id' => null,
            'status' => 'DRAFT',
            'due_date' => null,
            'currency' => (string) $clinic->currency,
            'total_gross' => $total,
            'patient_snapshot' => null,
            'clinic_snapshot' => null,
            'tax_snapshot' => null,
            'origin' => 'NATIVE',
            'legacy_reference' => null,
            'legacy_opening_balance' => null,
            'source_invoice_id' => null,
            'created_by' => $context->actorId,
            'source_device_id' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->drafts->write($context->clinicId, $invoiceId, $patientId, $lines, $at, false);

        if ($prospect !== null && $prospect->patient_id === null) {
            DB::table('prospect_contacts')->where('clinic_id', $context->clinicId)->where('id', (string) $prospect->id)->update([
                'patient_id' => $patientId,
                'version' => (int) $prospect->version + 1,
                'updated_at' => $now,
            ]);
        }

        $version = (int) $quote->version + 1;
        DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->update([
            'status' => 'CONVERTED',
            'version' => $version,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: [
                'invoice_id' => $invoiceId,
                'quote_id' => $quoteId,
                'revision_no' => $revisionNo,
                'price_basis' => $basis,
                'total_gross' => $total,
                'status' => 'DRAFT',
            ],
            resultType: 'invoices',
            resultId: $invoiceId,
            audit: [
                new AuditEntry('quote.converted', 'quotes', $quoteId, null, [
                    'number' => $quote->number,
                    'revision_no' => $revisionNo,
                    'invoice_id' => $invoiceId,
                    'patient_id' => $patientId,
                    'prospect_contact_id' => $quote->prospect_contact_id,
                    'price_basis' => $basis,
                    'catalog_price_changes' => $priceChanges,
                    'total_gross' => $total,
                ]),
                new AuditEntry('invoice.draft_created', 'invoices', $invoiceId, null, [
                    'patient_id' => $patientId,
                    'visit_id' => null,
                    'quote_id' => $quoteId,
                    'lines' => count($lines),
                    'total_gross' => $total,
                ]),
            ],
            changes: [
                new ChangeRecord('quotes', $quoteId, $version, ChangeVisibility::Financial, ChangeKind::Updated),
                new ChangeRecord('invoices', $invoiceId, InvoiceLedger::publicationVersion(1, null), ChangeVisibility::Financial, ChangeKind::Created),
            ],
        );
    }
}
