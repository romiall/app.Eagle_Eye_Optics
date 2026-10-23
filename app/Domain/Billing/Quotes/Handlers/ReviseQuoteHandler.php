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
use App\Domain\Billing\Quotes;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Révision d'un devis émis (L1D07 : « Une version remise/corrigée est figée ») : nouvelles lignes, nouvelle validité
 * et motif obligatoire produisent la révision N+1, IMMUABLE, avec son propre document. Les révisions antérieures et
 * leurs documents restent intacts et réimprimables ; le numéro du devis ne change pas. Le destinataire figé est relu
 * (même patient ou même prospect), jamais remplacé.
 *
 * Admis pour un devis émis, accepté (le client renégocie : il redevient « émis ») ou refusé (nouvelle proposition), y
 * compris expiré à la lecture. Refusé pour un brouillon (il se modifie) et pour un devis converti (sa facture existe :
 * un nouveau devis s'établit). Les lignes passent par les droits de l'auteur, comme sur un brouillon.
 *
 * Commande EN LIGNE. Verrous : cabinet, idempotence, dossier patient (45), devis (55).
 */
final class ReviseQuoteHandler implements CommandHandler
{
    public const COMMAND = 'ReviseQuote';

    public function __construct(private readonly Quotes $quotes) {}

    public function permissions(): array
    {
        return ['invoice.create'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La révision d\'un devis');

        $payload = $command->payload;
        $quoteId = (string) $payload['quote_id'];
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();

        $peek = DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->first();
        if ($peek === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }
        $patient = $peek->patient_id === null ? null : $locks->patient((string) $peek->patient_id);
        $quote = $locks->quote($quoteId);
        if ($quote === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }
        if ((string) $quote->status === 'DRAFT') {
            throw BusinessError::stateRefused('Ce devis est un brouillon : modifiez-le directement, puis émettez-le.', 'DRAFT');
        }
        Quotes::assertStatus($quote, ['ISSUED', 'ACCEPTED', 'DECLINED'], 'Ce devis est converti en facture : établissez un nouveau devis.');
        if ($command->expectedVersion === null || (int) $quote->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $quote->version);
        }

        $errors = new FieldErrors;
        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        if ($reason === null || mb_strlen($reason, 'UTF-8') < 3) {
            $errors->add('reason', 'Le motif de la révision est obligatoire (3 caractères au moins).');
        }
        $validUntil = Quotes::validUntil($payload['valid_until'] ?? null, Quotes::today($clinic, $at), true, $errors);
        $errors->throwIfAny();
        $reason = (string) $reason;

        $prospect = null;
        if ($quote->patient_id === null) {
            $prospect = DB::table('prospect_contacts')->where('clinic_id', $context->clinicId)->where('id', (string) $quote->prospect_contact_id)->first();
        }
        if ($patient === null && $prospect === null) {
            throw BusinessError::notFound('Destinataire du devis introuvable dans ce cabinet.');
        }
        if ($patient !== null && ($patient->archived_at !== null || $patient->merged_into_patient_id !== null)) {
            throw BusinessError::validation(['patient_id' => ['Cette fiche est archivée ou fusionnée : établissez un nouveau devis sur la fiche actuelle.']]);
        }

        /** @var list<array<string, mixed>> $payloadLines */
        $payloadLines = array_values($payload['lines']);
        if ($payloadLines === []) {
            throw BusinessError::validation(['lines' => ['Un devis remis compte au moins une ligne.']]);
        }
        $lines = $this->quotes->validatedLines($context->clinicId, $quoteId, $payloadLines, $this->quotes->policyFor($context));
        $total = Quotes::total($lines);
        if ($total !== (int) $payload['total_gross']) {
            throw new BusinessError(
                'VALIDATION_FAILED',
                'Le total transmis diffère du total recalculé par le serveur : vérifiez les lignes.',
                422,
                ['total_gross' => ['Le total recalculé est '.$total.' FCFA.']],
                null,
                [],
                ['expected_total' => $total],
            );
        }

        $revisionNo = ((int) DB::table('quote_revisions')->where('clinic_id', $context->clinicId)->where('quote_id', $quoteId)->max('revision_no')) + 1;
        $recipient = Quotes::recipient($patient, $prospect);
        $issuedAt = CarbonImmutable::parse((string) $quote->issued_at, 'UTC');
        $snapshot = Quotes::snapshot(
            $quoteId,
            (string) $quote->number,
            $revisionNo,
            $at,
            $issuedAt,
            $validUntil,
            $recipient,
            $lines,
            $total,
            $clinic,
            Quotes::usedRules($context->clinicId, $lines),
            (string) $context->actorId,
            Quotes::actorName($context->clinicId, $context->actorId),
            $reason,
        );
        $nowDb = $at->format('Y-m-d H:i:s.u');
        $documentId = Ulid::generate();

        $this->quotes->writeLines($context->clinicId, $quoteId, $lines, $at);
        DB::table('quote_revisions')->insert([
            'id' => Ulid::generate(),
            'clinic_id' => $context->clinicId,
            'quote_id' => $quoteId,
            'revision_no' => $revisionNo,
            'snapshot' => Quotes::json($snapshot),
            'authored_by' => $context->actorId,
            'recorded_at' => $nowDb,
            'reason' => $reason,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);
        PendingDocuments::forQuoteRevision($context, $documentId, $quoteId, $revisionNo, $at);

        $version = (int) $quote->version + 1;
        DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->update([
            'status' => 'ISSUED',
            'valid_until' => $validUntil,
            'total_gross' => $total,
            'recipient_snapshot' => Quotes::json($recipient),
            'version' => $version,
            'updated_at' => $nowDb,
        ]);

        return new CommandOutcome(
            result: [
                'quote_id' => $quoteId,
                'number' => (string) $quote->number,
                'revision_no' => $revisionNo,
                'version' => $version,
                'total_gross' => $total,
                'document_id' => $documentId,
                'document_status' => 'PENDING',
            ],
            resultType: 'quotes',
            resultId: $quoteId,
            audit: [new AuditEntry('quote.revised', 'quotes', $quoteId, $reason, [
                'number' => (string) $quote->number,
                'revision_no' => $revisionNo,
                'previous_status' => (string) $quote->status,
                'previous_total_gross' => (int) $quote->total_gross,
                'total_gross' => $total,
                'lines' => count($lines),
                'valid_until' => $validUntil,
            ])],
            changes: [new ChangeRecord('quotes', $quoteId, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
