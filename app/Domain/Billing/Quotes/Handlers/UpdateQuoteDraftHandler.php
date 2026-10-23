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
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\Quotes;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Modification d'un brouillon de devis (L1D07) : lignes (remplacement complet) et date de validité, version attendue
 * (409 `VERSION_CONFLICT` sinon). Le destinataire ne change pas : un autre destinataire est un autre devis. Seuls le
 * créateur et la gérante modifient un brouillon ; un devis émis se corrige par une révision (`ReviseQuote`).
 *
 * Commande EN LIGNE. Verrous : cabinet, idempotence, devis (55).
 */
final class UpdateQuoteDraftHandler implements CommandHandler
{
    public const COMMAND = 'UpdateQuoteDraft';

    public function __construct(private readonly Quotes $quotes, private readonly ActorRights $rights) {}

    public function permissions(): array
    {
        return ['invoice.create'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La modification d\'un devis');

        $payload = $command->payload;
        $quoteId = (string) $payload['quote_id'];
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();

        $quote = $locks->quote($quoteId);
        if ($quote === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }
        Quotes::assertStatus($quote, ['DRAFT'], 'Ce devis est émis : il se corrige par une nouvelle révision, avec un motif.');
        if ($command->expectedVersion === null || (int) $quote->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $quote->version);
        }
        if ((string) $quote->created_by !== (string) $context->actorId && ! $this->rights->manager($context)) {
            throw BusinessError::forbidden('Ce brouillon de devis appartient à un autre compte : seule la gérante le modifie.');
        }

        $errors = new FieldErrors;
        $validUntil = Quotes::validUntil($payload['valid_until'] ?? null, Quotes::today($clinic, $at), false, $errors);
        $errors->throwIfAny();

        /** @var list<array<string, mixed>> $payloadLines */
        $payloadLines = array_values($payload['lines']);
        $lines = $this->quotes->validatedLines($context->clinicId, $quoteId, $payloadLines, $this->quotes->policyFor($context));
        $total = Quotes::total($lines);

        $version = (int) $quote->version + 1;
        $this->quotes->writeLines($context->clinicId, $quoteId, $lines, $at);
        DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->update([
            'valid_until' => $validUntil,
            'total_gross' => $total,
            'version' => $version,
            'updated_at' => $at->format('Y-m-d H:i:s.u'),
        ]);

        return new CommandOutcome(
            result: ['quote_id' => $quoteId, 'version' => $version, 'status' => 'DRAFT', 'total_gross' => $total],
            resultType: 'quotes',
            resultId: $quoteId,
            audit: [new AuditEntry('quote.draft_updated', 'quotes', $quoteId, null, [
                'version' => $version,
                'lines' => count($lines),
                'total_gross' => $total,
            ])],
            changes: [new ChangeRecord('quotes', $quoteId, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
