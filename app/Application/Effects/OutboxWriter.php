<?php

namespace App\Application\Effects;

use App\Application\Contracts\ContractValidator;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Écrit les événements d'une commande dans `outbox_events` (statut PENDING) au commit métier. Les effets
 * externes (message, Google, CRM) sont déclenchés plus tard par un worker qui lit l'outbox : une panne de
 * fournisseur n'annule jamais un paiement ni une facture. Seuls les événements du contrat F06 (13 types, charge
 * minimale) sont admis.
 */
final class OutboxWriter
{
    public function __construct(private readonly ContractValidator $contracts) {}

    /**
     * @param  list<OutboxEvent>  $events
     */
    public function write(CommandContext $context, array $events): void
    {
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');

        foreach ($events as $event) {
            $this->contracts->assertEventPayload($event->type, $event->version, $event->payload);

            DB::table('outbox_events')->insert([
                'id' => Ulid::generate(),
                'clinic_id' => $context->clinicId,
                'event_type' => $event->type,
                'event_version' => $event->version,
                'source_type' => $event->sourceType,
                'source_id' => $event->sourceId,
                'source_version' => $event->sourceVersion,
                'payload' => json_encode((object) $event->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'correlation_id' => $context->correlationId,
                'status' => 'PENDING',
                'attempt_count' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
