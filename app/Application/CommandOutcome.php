<?php

namespace App\Application;

use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\OutboxEvent;

/**
 * Ce qu'une action rend au chemin central : la réponse (stockée pour rejouer une réponse perdue) et les
 * effets d'écriture à enregistrer au commit — audit, événements d'outbox et changements à diffuser. Le chemin
 * central les écrit dans la même transaction que les faits de l'action : tout ou rien.
 */
final readonly class CommandOutcome
{
    /**
     * @param  array<string, mixed>  $result  objet JSON de la réponse (références, versions, soldes)
     * @param  list<AuditEntry>  $audit
     * @param  list<OutboxEvent>  $events
     * @param  list<ChangeRecord>  $changes
     */
    public function __construct(
        public array $result,
        public ?string $resultType = null,
        public ?string $resultId = null,
        public array $audit = [],
        public array $events = [],
        public array $changes = [],
    ) {}
}
