<?php

namespace App\Domain\Billing\Guards;

use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;

/**
 * Droits de l'auteur d'une commande, lus selon l'autorité qui le qualifie : droits effectifs ACTUELS du compte pour
 * la route directe (relus en base sous le verrou du cabinet par le chemin central), permissions du grant signé pour
 * une commande de poste, aucun pour une tâche planifiée. Une action ne compare jamais un nom de profil : seulement
 * des codes de permission du référentiel (fichier 09).
 *
 * Deux notions d'autorité servent partout dans la facturation :
 *  - l'AUTORITÉ DE PRIX (`catalog.manage`) : fixer un prix hors catalogue, une ligne libre, une remise au-delà du
 *    plafond des autres comptes ;
 *  - la GÉRANTE (`approval.decide`) : modifier le brouillon d'un autre compte, clôturer la session d'une autre
 *    caissière, exécuter directement ce que d'autres demandent par approbation.
 */
final class ActorRights
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    public function has(CommandContext $context, string $permission): bool
    {
        return match ($context->source) {
            CommandSource::Direct => $context->user !== null && $this->permissions->has($context->user, $permission),
            CommandSource::Sync => $context->grant?->allows($permission) ?? false,
            CommandSource::Job => false,
        };
    }

    public function priceAuthority(CommandContext $context): bool
    {
        return $this->has($context, 'catalog.manage');
    }

    public function manager(CommandContext $context): bool
    {
        return $this->has($context, 'approval.decide');
    }
}
