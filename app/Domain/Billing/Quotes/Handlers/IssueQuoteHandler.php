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
use App\Domain\Billing\Documents\PendingDocuments;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\Numbering\BillingNumbers;
use App\Domain\Billing\Numbering\NumberUsages;
use App\Domain\Billing\Quotes;
use App\Domain\Billing\Rules\DraftPolicy;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Émission d'un devis (L1D07) : dans UNE transaction, numéro définitif de la série QUOTE (sans trou, sous verrou),
 * lignes recalculées et figées telles qu'une personne habilitée les a écrites, révision 1 IMMUABLE (snapshot du
 * destinataire, du cabinet, des lignes, de la taxe comprise et de la validité) et document PENDING de cette révision.
 * Toute erreur annule l'ensemble, numéro compris.
 *
 * Un devis émis n'est ni une facture ni une créance : aucun encaissement ne s'y rattache, aucun stock n'est réservé,
 * rien n'entre dans le chiffre d'affaires. `total_gross` du client est une valeur À VÉRIFIER : le serveur recalcule et
 * refuse l'écart. La date de validité est obligatoire et ne précède pas le jour du cabinet.
 *
 * Commande EN LIGNE. Verrous : cabinet, idempotence, séquence QUOTE (40), dossier patient (45), devis (55).
 */
final class IssueQuoteHandler implements CommandHandler
{
    public const COMMAND = 'IssueQuote';

    public function __construct(private readonly Quotes $quotes) {}

    public function permissions(): array
    {
        return ['invoice.create'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'L\'émission d\'un devis');

        $payload = $command->payload;
        $quoteId = (string) $payload['quote_id'];
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $timezone = (string) $clinic->timezone;

        // 1. Numéro (rang 40). Un échec plus loin annule la séquence avec la transaction.
        $number = BillingNumbers::allocate($locks, $context, BillingNumbers::QUOTE, $timezone, $at);

        // 2. Patient (45) puis devis (55). Lecture préalable sans verrou pour connaître le patient : le cabinet est
        //    verrouillé, rien ne peut changer entre les deux lectures.
        $peek = DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->first();
        if ($peek === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }
        $patient = $peek->patient_id === null ? null : $locks->patient((string) $peek->patient_id);
        $quote = $locks->quote($quoteId);
        if ($quote === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }
        Quotes::assertStatus($quote, ['DRAFT'], 'Ce devis est déjà émis : il se corrige par une nouvelle révision.');
        if ($command->expectedVersion === null || (int) $quote->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $quote->version);
        }

        $prospect = null;
        if ($quote->patient_id !== null) {
            if ($patient === null) {
                throw BusinessError::notFound('Patient introuvable dans ce cabinet.');
            }
            if ($patient->archived_at !== null || $patient->merged_into_patient_id !== null) {
                throw BusinessError::validation(['patient_id' => ['Cette fiche est archivée ou fusionnée : le devis ne peut plus être émis sur elle.']]);
            }
        } else {
            $prospect = DB::table('prospect_contacts')->where('clinic_id', $context->clinicId)->where('id', (string) $quote->prospect_contact_id)->first();
            if ($prospect === null) {
                throw BusinessError::notFound('Prospect introuvable dans ce cabinet.');
            }
        }

        $errors = new FieldErrors;
        $validUntil = Quotes::validUntil($quote->valid_until, Quotes::today($clinic, $at), true, $errors);
        $errors->throwIfAny();

        // 3. Lignes : recalcul et figement, sans règle de droit (écrites sous les droits de leur auteur).
        $stored = Quotes::storedLines($context->clinicId, $quoteId);
        if ($stored === []) {
            throw BusinessError::validation(['lines' => ['Un devis sans ligne ne s\'émet pas.']]);
        }
        $lines = $this->quotes->validatedLines($context->clinicId, $quoteId, $stored, DraftPolicy::trusted());
        $total = Quotes::total($lines);
        if ($total !== (int) $payload['total_gross']) {
            throw new BusinessError(
                'VALIDATION_FAILED',
                'Le total transmis diffère du total recalculé par le serveur : actualisez le devis.',
                422,
                ['total_gross' => ['Le total recalculé est '.$total.' FCFA.']],
                null,
                [],
                ['expected_total' => $total],
            );
        }

        // 4. Révision 1 figée, usage du numéro, document en attente, puis le devis passe à ISSUED.
        $recipient = Quotes::recipient($patient, $prospect);
        $authorName = Quotes::actorName($context->clinicId, $context->actorId);
        $snapshot = Quotes::snapshot(
            $quoteId,
            $number['rendered'],
            1,
            $at,
            $at,
            $validUntil,
            $recipient,
            $lines,
            $total,
            $clinic,
            Quotes::usedRules($context->clinicId, $lines),
            (string) $context->actorId,
            $authorName,
            Quotes::ISSUE_REASON,
        );
        $nowDb = $at->format('Y-m-d H:i:s.u');
        $revisionId = Ulid::generate();
        $documentId = Ulid::generate();

        $this->quotes->writeLines($context->clinicId, $quoteId, $lines, $at);
        DB::table('quote_revisions')->insert([
            'id' => $revisionId,
            'clinic_id' => $context->clinicId,
            'quote_id' => $quoteId,
            'revision_no' => 1,
            'snapshot' => Quotes::json($snapshot),
            'authored_by' => $context->actorId,
            'recorded_at' => $nowDb,
            'reason' => Quotes::ISSUE_REASON,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);
        NumberUsages::record($context, Ulid::generate(), BillingNumbers::QUOTE, $number, 'quotes', $quoteId, $at);
        PendingDocuments::forQuoteRevision($context, $documentId, $quoteId, 1, $at);

        $version = (int) $quote->version + 1;
        DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->update([
            'number' => $number['rendered'],
            'status' => 'ISSUED',
            'valid_until' => $validUntil,
            'total_gross' => $total,
            'recipient_snapshot' => Quotes::json($recipient),
            'issued_at' => $nowDb,
            'version' => $version,
            'updated_at' => $nowDb,
        ]);

        return new CommandOutcome(
            result: [
                'quote_id' => $quoteId,
                'number' => $number['rendered'],
                'revision_no' => 1,
                'version' => $version,
                'total_gross' => $total,
                'document_id' => $documentId,
                'document_status' => 'PENDING',
            ],
            resultType: 'quotes',
            resultId: $quoteId,
            audit: [new AuditEntry('quote.issued', 'quotes', $quoteId, null, [
                'number' => $number['rendered'],
                'patient_id' => $quote->patient_id,
                'prospect_contact_id' => $quote->prospect_contact_id,
                'revision_no' => 1,
                'total_gross' => $total,
                'lines' => count($lines),
                'valid_until' => $validUntil,
            ])],
            changes: [new ChangeRecord('quotes', $quoteId, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
