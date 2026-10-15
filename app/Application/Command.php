<?php

namespace App\Application;

use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\CommandContext;

/**
 * Intention d'écriture adressée au chemin central : nom de la commande (contrat F06), charge métier,
 * agrégat visé et version attendue. `expectedVersion` est celle du brouillon (UpdateDraft, IssueInvoice),
 * le `ledger_version` pour une mutation financière après émission, ou null (création, transition d'état).
 */
final readonly class Command
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $name,
        public array $payload,
        public ?string $entityId = null,
        public ?int $expectedVersion = null,
    ) {}

    /**
     * Empreinte de l'intention complète : même nom, mêmes agrégat, version et charge donnent le même hash.
     * Une clé d'idempotence rejouée avec une autre empreinte est un conflit (IDEMPOTENCY_PAYLOAD_MISMATCH).
     */
    public function hash(): string
    {
        return CanonicalJson::hash([
            'command' => $this->name,
            'entity_id' => $this->entityId,
            'expected_version' => $this->expectedVersion,
            'payload' => $this->payload,
        ]);
    }

    /**
     * Empreinte comparée au rejeu d'une clé d'idempotence : l'intention PLUS son origine (source, auteur, poste,
     * grant). Le même identifiant de commande présenté par un autre auteur ou sous un autre grant du même poste
     * n'est jamais un rejeu : c'est un conflit (fichier 19 : « même ID, autre hash → conflit d'intégrité » ;
     * le cœur signé contient actor_id et grant_id).
     */
    public function fingerprint(CommandContext $context): string
    {
        return CanonicalJson::hash([
            'intent' => $this->hash(),
            'source' => $context->source->value,
            'actor_id' => $context->actorId,
            'device_id' => $context->deviceId,
            'grant_id' => $context->grant?->grantId,
        ]);
    }
}
