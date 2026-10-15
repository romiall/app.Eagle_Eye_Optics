<?php

namespace Tests\Support\Commands;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\OutboxEvent;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Action de TEST qui enfreint volontairement une règle du chemin central, selon `payload.mode`, après avoir
 * écrit un fait : le test vérifie que l'infraction est refusée ET que le fait déjà écrit est annulé.
 *
 *  - `lock-order`   : demande un verrou de facture puis un verrou d'appareil (rang inférieur) ;
 *  - `unknown-event`: rend un événement qui n'existe pas dans contracts/events/ ;
 *  - `bad-event`    : rend un événement connu dont la charge viole le contrat (donnée en trop) ;
 *  - `crash`        : lève une exception inattendue ;
 *  - `noop`         : ne fait rien d'autre que le fait écrit (témoin).
 */
final class MisbehavingHandler implements CommandHandler
{
    public function permissions(): array
    {
        return ['payment.collect'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        // Fait écrit AVANT l'infraction : il doit disparaître avec la transaction.
        DB::table('document_sequences')->insert([
            'id' => Ulid::generate(),
            'clinic_id' => $context->clinicId,
            'document_type' => 'RECEIPT',
            'document_year' => 2026,
            'last_value' => 1,
        ]);

        $mode = (string) ($command->payload['mode'] ?? 'noop');

        if ($mode === 'lock-order') {
            $locks->invoice(Ulid::generate());
            $locks->device(Ulid::generate());
        }
        if ($mode === 'crash') {
            throw new \DomainException('Exception inattendue de l\'action.');
        }

        $events = match ($mode) {
            'unknown-event' => [new OutboxEvent('InventedEvent', 1, 'invoices', Ulid::generate(), 1, ['x' => 1])],
            'bad-event' => [new OutboxEvent('PaymentConfirmed', 1, 'invoices', Ulid::generate(), 1, [
                'payment_id' => Ulid::generate(), 'invoice_id' => Ulid::generate(), 'receipt_id' => Ulid::generate(),
                'receipt_number' => 'TEST-1', 'amount' => 100, 'currency' => 'XAF', 'method' => 'MTN_MOMO',
                'effective_at' => '2026-10-14T09:30:00.000Z', 'diagnosis' => 'donnée clinique interdite',
            ])],
            default => [],
        };

        return new CommandOutcome(
            result: ['mode' => $mode],
            audit: [new AuditEntry('test.misbehaving', 'document_sequences')],
            events: $events,
        );
    }
}
