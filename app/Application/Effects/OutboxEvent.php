<?php

namespace App\Application\Effects;

/**
 * Événement interne à publier après le commit (`outbox_events`, fichier 08) : une charge MINIMALE
 * (identifiants et attributs administratifs), validée contre `contracts/events/<type>.v<version>.json`.
 * Aucun appel externe n'a lieu dans la transaction : un worker lit l'outbox après le commit.
 */
final readonly class OutboxEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public int $version,
        public string $sourceType,
        public string $sourceId,
        public int $sourceVersion,
        public array $payload,
    ) {}
}
