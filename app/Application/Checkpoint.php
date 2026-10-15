<?php

namespace App\Application;

/**
 * Points nommés du chemin central, dans l'ordre où ils sont atteints à l'intérieur de la transaction. Ils ne
 * servent qu'à l'observation : les tests y injectent une erreur pour prouver que CHAQUE effet déjà écrit est
 * annulé (audit, outbox, journal des changements, clé d'idempotence, faits de l'action), ou y suspendent une
 * transaction pour construire un entrelacement exact entre deux connexions MySQL.
 */
enum Checkpoint: string
{
    /** Verrou exclusif du cabinet tenu, rien d'autre n'est encore écrit. */
    case ClinicLocked = 'clinic-locked';

    /** Droits relus sous verrou, clé d'idempotence retrouvée ou réservée. */
    case IdempotencyChecked = 'idempotency-checked';

    /** L'action a écrit ses faits et rendu son résultat ; les effets du chemin central ne sont pas écrits. */
    case HandlerReturned = 'handler-returned';

    case AuditWritten = 'audit-written';

    case OutboxWritten = 'outbox-written';

    case ChangeLogWritten = 'change-log-written';

    /** Résultat durable enregistré : il ne reste que le commit. */
    case IdempotencyCompleted = 'idempotency-completed';
}
