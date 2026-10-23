<?php

namespace Tests\Support;

use App\Domain\Shared\CommandContext;
use App\Domain\Shared\GrantSnapshot;
use App\Domain\Shared\SyncReferences;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Contextes d'origine REFUSÉE pour les actions ouvertes à la synchronisation (O05), appelées sans passer par le bus :
 *  - une tâche serveur, sans auteur, qui n'a aucun droit de créer une pièce ;
 *  - un poste dont le cœur signé ne désigne aucune plage de numéros (poste non préparé, O03).
 *
 * Exige `$this->clinicId` (cabinet fictif du test).
 */
trait RefusedSources
{
    protected function jobContext(): CommandContext
    {
        return CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC'));
    }

    protected function syncContextWithoutRange(User $actor): CommandContext
    {
        $now = CarbonImmutable::now('UTC');
        $grant = new GrantSnapshot(
            Ulid::generate(), Ulid::generate(), $actor->id, ['patient.write', 'invoice.issue', 'payment.register'], 'rules-test', 'catalog-test',
            $now->subHour(), $now->addHours(8),
        );

        return CommandContext::sync(
            $this->clinicId, $grant->deviceId, $actor->id, $actor->display_name, $grant, 'corr-sync-test',
            $now, $now, $now, $now,
            new SyncReferences(Ulid::generate(), 1, str_repeat('a', 64), null, null),
        );
    }
}
