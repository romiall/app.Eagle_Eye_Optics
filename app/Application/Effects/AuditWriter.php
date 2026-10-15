<?php

namespace App\Application\Effects;

use App\Domain\Access\AuditRecorder;
use App\Domain\Shared\CommandContext;

/**
 * Écrit les entrées d'audit d'une commande (journal append-only `audit_events`) dans la transaction du chemin
 * central. L'auteur et la corrélation viennent du contexte : une charge ne peut pas signer à la place d'un autre.
 */
final class AuditWriter
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  list<AuditEntry>  $entries
     */
    public function write(CommandContext $context, array $entries): void
    {
        foreach ($entries as $entry) {
            $this->recorder->recordFor(
                $context->clinicId,
                $context->actorId,
                $context->actorName,
                $entry->action,
                $entry->targetType,
                $entry->targetId,
                $entry->reason,
                $entry->sanitizedDiff,
                $context->correlationId,
            );
        }
    }
}
