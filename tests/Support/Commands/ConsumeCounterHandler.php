<?php

namespace Tests\Support\Commands;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Effects\OutboxEvent;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Action de TEST du chemin central : retire un montant d'un compteur (une ligne `document_sequences`, haut-niveau
 * PATIENT 2026). Elle rejoue la forme d'un encaissement — « montant ≤ solde verrouillé », version attendue,
 * audit, événement d'outbox contractuel et changement à diffuser — sans dépendre d'une table métier pas encore
 * livrée. Tout ce qu'elle écrit est annulable, donc visible par les tests de retour arrière.
 */
final class ConsumeCounterHandler implements CommandHandler
{
    public const COUNTER_TYPE = 'PATIENT';

    public const COUNTER_YEAR = 2026;

    public static int $handled = 0;

    public function permissions(): array
    {
        return ['payment.collect'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks): CommandOutcome
    {
        self::$handled++;

        $row = $locks->sequences([[self::COUNTER_TYPE, self::COUNTER_YEAR]])[self::COUNTER_TYPE.':'.self::COUNTER_YEAR]
            ?? throw BusinessError::notFound('Compteur introuvable.');

        if ($command->expectedVersion !== null && (int) $row->version !== $command->expectedVersion) {
            throw BusinessError::versionConflict();
        }

        $amount = (int) ($command->payload['amount'] ?? 0);
        if ($amount < 1 || (int) $row->last_value < $amount) {
            throw new BusinessError('PAYMENT_EXCEEDS_BALANCE', 'Le montant dépasse le solde.', 409);
        }

        $remaining = (int) $row->last_value - $amount;
        $version = (int) $row->version + 1;
        DB::table('document_sequences')->where('clinic_id', $context->clinicId)->where('id', $row->id)->update([
            'last_value' => $remaining,
            'version' => $version,
            'updated_at' => $context->now->utc()->format('Y-m-d H:i:s.u'),
        ]);

        return new CommandOutcome(
            result: ['counter_id' => (string) $row->id, 'amount' => $amount, 'remaining' => $remaining, 'version' => $version],
            resultType: 'document_sequences',
            resultId: (string) $row->id,
            audit: [new AuditEntry('test.counter.consumed', 'document_sequences', (string) $row->id, null, ['amount' => $amount, 'remaining' => $remaining])],
            events: [new OutboxEvent('PaymentConfirmed', 1, 'invoices', Ulid::generate(), $version, [
                'payment_id' => Ulid::generate(),
                'invoice_id' => Ulid::generate(),
                'receipt_id' => Ulid::generate(),
                'receipt_number' => 'TEST-REC-'.$version,
                'amount' => $amount,
                'currency' => 'XAF',
                'method' => 'MTN_MOMO',
                'effective_at' => $context->now->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ])],
            changes: [new ChangeRecord('document_sequences', (string) $row->id, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
