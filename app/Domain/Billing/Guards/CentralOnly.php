<?php

namespace App\Domain\Billing\Guards;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;

/**
 * Premier jalon EN LIGNE : le serveur est la référence. Les commandes financières venues d'un poste hors-ligne
 * (journal signé, numéros de sa plage, propriété de facture, quota de stock) sont refusées explicitement tant que
 * la synchronisation (O03, O05) ne les juge pas : aucun numéro ni aucun stock n'est attribué hors de toute
 * autorisation. L'ACTION, elle, est la même pour les trois origines : seules les entrées propres à l'origine (source
 * du numéro, réserves choisies par le poste, propriétaire) seront ajoutées quand cette garde sera levée.
 */
final class CentralOnly
{
    public static function assert(CommandContext $context, string $operation): void
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden($operation.' depuis un poste hors-ligne n\'est pas encore ouvert : plages de numéros, propriété de facture et quotas de stock arrivent avec la synchronisation (O03, O05).');
        }
    }
}
