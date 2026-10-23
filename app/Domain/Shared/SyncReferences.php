<?php

namespace App\Domain\Shared;

/**
 * Références d'une commande de poste tirées de son cœur signé (contrat 23, « Signature et chiffrement »), remises par
 * la synchronisation (O05) à l'action centrale en plus de la charge : place dans le journal du poste (rang, empreinte,
 * empreinte précédente), plage de numéros consommée et quotas de stock utilisés.
 *
 * L'action s'en sert pour consommer le numéro PRÉ-ALLOUÉ du poste (jamais un numéro central) et contrôler ses réserves
 * sous quota ; le chemin central s'en sert pour enregistrer l'ACK et avancer le préfixe finalisé du poste DANS la
 * transaction de l'effet métier (tout ou rien).
 *
 * `reviewApproved` : la gérante a décidé, après revue motivée (ACCEPT_LOCAL), d'appliquer la commande telle quelle. Seul
 * un verdict d'autorisation historique « en revue » est alors levé ; un refus reste un refus.
 */
final readonly class SyncReferences
{
    /**
     * @param  list<string>  $stockAllocationIds  quotas `device_stock_allocations` déclarés par le cœur, triés
     */
    public function __construct(
        public string $commandId,
        public int $deviceSequence,
        public string $eventHash,
        public ?string $previousEventHash,
        public ?string $numberAllocationId,
        public array $stockAllocationIds = [],
        public bool $reviewApproved = false,
    ) {}
}
