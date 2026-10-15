<?php

namespace App\Application;

use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\CommandContext;

/**
 * Action de domaine exécutée par le chemin central. La route directe, la synchronisation et les tâches
 * appellent la MÊME action : elle ne connaît ni HTTP, ni session, ni journal du poste.
 *
 * Le chemin central a déjà : validé la charge contre le contrat, contrôlé les droits, ouvert la transaction,
 * pris le verrou exclusif du cabinet, retrouvé ou réservé la clé d'idempotence. L'action prend ses autres
 * verrous par `$locks`, dans l'ordre du contrat 23, recalcule, écrit les faits et rend sa réponse avec les
 * effets (audit, événements, changements) que le chemin central écrira dans la même transaction. Elle ne fait
 * AUCUN appel externe (messagerie, Google, CRM) : ces effets passent par l'outbox, après le commit.
 */
interface CommandHandler
{
    /**
     * Codes du référentiel `permissions` : l'un d'eux suffit (contrat de la commande, fichier 09).
     *
     * @return list<string>
     */
    public function permissions(): array;

    /**
     * @param  string|null  $idempotencyId  identifiant de la ligne `idempotency_requests` réservée pour cette
     *                                      commande : les faits qui l'exigent (`payments`, `refunds`, `deliveries`,
     *                                      `stock_movements`… colonne `idempotency_id` NOT NULL) le reçoivent ici.
     *                                      Jamais null pour une commande dont la clé est obligatoire.
     */
    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome;
}
