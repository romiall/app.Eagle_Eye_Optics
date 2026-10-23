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
use App\Domain\Billing\Quotes;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Réponse du client à un devis émis (L1D07) : ACCEPTÉ (devis émis et encore valable) ou REFUSÉ (devis émis ou
 * accepté, motif facultatif conservé dans l'audit). Une acceptation n'est PAS une vente : elle ne crée ni facture, ni
 * paiement, ni réserve ; la facture naît seulement d'une conversion explicite. Version attendue (409 sinon).
 *
 * Commande EN LIGNE. Verrous : cabinet, idempotence, devis (55).
 */
final class RecordQuoteDecisionHandler implements CommandHandler
{
    public const COMMAND = 'RecordQuoteDecision';

    public function permissions(): array
    {
        return ['invoice.create'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La réponse à un devis');

        $payload = $command->payload;
        $quoteId = (string) $payload['quote_id'];
        $decision = (string) $payload['decision'];
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();

        $quote = $locks->quote($quoteId);
        if ($quote === null) {
            throw BusinessError::notFound('Devis introuvable dans ce cabinet.');
        }
        if ($decision === 'ACCEPTED') {
            Quotes::assertStatus($quote, ['ISSUED'], 'Seul un devis émis, ni refusé ni converti, peut être accepté.');
            if (Quotes::isExpired($quote, Quotes::today($clinic, $at))) {
                throw BusinessError::stateRefused('Ce devis a expiré : révisez-le avec une nouvelle date de validité avant de l\'accepter.', 'EXPIRED');
            }
        } else {
            Quotes::assertStatus($quote, ['ISSUED', 'ACCEPTED'], 'Seul un devis émis ou accepté, non converti, peut être refusé.');
        }
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
        $errors->throwIfAny();

        $version = (int) $quote->version + 1;
        DB::table('quotes')->where('clinic_id', $context->clinicId)->where('id', $quoteId)->update([
            'status' => $decision,
            'version' => $version,
            'updated_at' => $at->format('Y-m-d H:i:s.u'),
        ]);

        return new CommandOutcome(
            result: ['quote_id' => $quoteId, 'version' => $version, 'status' => $decision],
            resultType: 'quotes',
            resultId: $quoteId,
            audit: [new AuditEntry($decision === 'ACCEPTED' ? 'quote.accepted' : 'quote.declined', 'quotes', $quoteId, $reason, [
                'number' => $quote->number,
                'previous_status' => (string) $quote->status,
                'version' => $version,
            ])],
            changes: [new ChangeRecord('quotes', $quoteId, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
