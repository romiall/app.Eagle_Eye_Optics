<?php

namespace App\Application\Effects;

/**
 * Nature d'un changement publié (`sync_change_log.change_kind`). Jamais de suppression : une entité
 * retirée est archivée, son historique reste lisible.
 */
enum ChangeKind: string
{
    case Created = 'CREATED';
    case Updated = 'UPDATED';
    case Archived = 'ARCHIVED';
}
