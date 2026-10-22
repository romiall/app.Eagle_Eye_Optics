<?php

namespace App\Application;

use App\Domain\Shared\CommandSource;

/**
 * Enregistrement d'une commande auprès du chemin central.
 *
 * `schema` : chemin relatif à `contracts/` du schéma de la charge (ex. `commands/RegisterPayment.v1.json`) ;
 * null pour une opération en ligne seulement dont la validation reste celle de l'action (comptes, cabinet).
 * `idempotencyRequired` : vrai pour toute commande économique ou clinique (en-tête `Idempotency-Key`
 * obligatoire sur la route directe, `command_id` pour la synchronisation).
 * `directSchema` : schéma du CORPS de la route directe (`…#/$defs/DirectRequest` d'une commande du
 * catalogue), qui diffère de la charge du poste : la version attendue y figure et le numéro de la pièce y
 * est null (le serveur numérote). Sur la route directe, le bus valide ce corps ; la charge d'un poste reste
 * validée par `schema`.
 */
final readonly class CommandDefinition
{
    /**
     * @param  class-string<CommandHandler>  $handler
     * @param  list<CommandSource>  $sources
     */
    public function __construct(
        public string $name,
        public string $handler,
        public array $sources = [CommandSource::Direct, CommandSource::Sync],
        public ?string $schema = null,
        public bool $idempotencyRequired = false,
        public ?string $directSchema = null,
    ) {}
}
