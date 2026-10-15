<?php

namespace App\Domain\Shared;

/**
 * Origine d'une commande (contrat 23, « Chemin transactionnel central »). La route directe, la
 * synchronisation et les tâches planifiées appellent les mêmes actions : seule l'autorité qui les autorise
 * diffère.
 */
enum CommandSource: string
{
    /** Requête d'un compte connecté : droits effectifs ACTUELS, relus sous le verrou du cabinet. */
    case Direct = 'DIRECT';

    /** Commande signée rejouée depuis un poste : contexte d'autorisation HISTORIQUE du grant. */
    case Sync = 'SYNC';

    /** Traitement serveur (cron, worker) : identité de service, périmètre fixé à l'enregistrement. */
    case Job = 'JOB';
}
