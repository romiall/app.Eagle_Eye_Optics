<?php

namespace App\Application\Locking;

use LogicException;

/**
 * Verrou demandé hors de l'ordre du contrat 23. C'est un défaut de programmation, jamais une réponse
 * d'API : la transaction est annulée et l'erreur remonte en erreur interne, afin qu'un test ou une revue
 * l'attrape avant qu'un entrelacement réel ne provoque un interblocage.
 */
final class LockOrderViolation extends LogicException {}
