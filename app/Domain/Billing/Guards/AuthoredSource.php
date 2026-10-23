<?php

namespace App\Domain\Billing\Guards;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;

/**
 * Garde des actions ouvertes à la synchronisation (O05) : la route directe d'un compte et la commande d'un poste
 * enrôlé portent toutes deux un AUTEUR identifié (compte connecté ou titulaire du grant). Une tâche serveur n'en a
 * pas : elle ne crée ni patient, ni facture, ni encaissement, ni session de caisse.
 *
 * Le catalogue des commandes l'exclut déjà avant le chemin transactionnel (`CommandDefinition::sources`) ; cette
 * garde protège l'action elle-même, appelée directement, avant toute lecture, tout verrou et toute écriture.
 */
final class AuthoredSource
{
    public static function assert(CommandContext $context, string $operation): void
    {
        if ($context->source === CommandSource::Job || $context->actorId === null) {
            throw BusinessError::forbidden($operation.' exige un auteur identifié (compte connecté ou poste enrôlé) : une tâche du serveur ne peut pas l\'accomplir.');
        }
    }
}
