<?php

namespace App\Offline;

use Carbon\CarbonImmutable;

/**
 * Commande signée d'un poste dont l'origine est VÉRIFIÉE (O05) : cœur reconstitué et haché en JCS, empreinte égale à
 * `event_hash`, JWS ES256 de la clé enrôlée du poste, identifiants du cabinet, du poste, de l'auteur et du grant
 * cohérents. Seule une commande vérifiée entre dans l'inbox du serveur (`sync_commands`) : une attaque non
 * authentifiée n'avance jamais le journal.
 */
final readonly class VerifiedCommand
{
    /**
     * @param  list<string>  $stockAllocationIds
     */
    public function __construct(
        public string $commandId,
        public string $commandName,
        public string $deviceId,
        public string $actorId,
        public string $grantId,
        public int $deviceSequence,
        public ?string $previousEventHash,
        public string $eventHash,
        public string $signature,
        public string $schemaVersion,
        public string $rulesVersion,
        public CarbonImmutable $occurredAtLocal,
        public CarbonImmutable $lastServerTime,
        public string $entityType,
        public string $entityId,
        public ?int $expectedVersion,
        public ?string $numberAllocationId,
        public array $stockAllocationIds,
        public mixed $payload,
    ) {}
}
