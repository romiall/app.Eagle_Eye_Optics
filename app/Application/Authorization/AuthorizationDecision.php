<?php

namespace App\Application\Authorization;

/**
 * Issue d'un contrôle d'autorisation historique : accordée, refusée, ou ambiguë. Un cas ambigu (horloge du
 * poste incohérente, action autour d'une révocation) n'est ni accepté ni rejeté en silence : il passe en
 * revue par la gérante (contrat 23).
 */
enum AuthorizationDecision: string
{
    case Allowed = 'ALLOWED';
    case Denied = 'DENIED';
    case NeedsReview = 'NEEDS_REVIEW';
}
