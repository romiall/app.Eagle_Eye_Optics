<?php

namespace App\Application\Effects;

use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Écrit les changements à diffuser aux postes dans `sync_change_log` (journal append-only), SOUS le verrou
 * exclusif du cabinet.
 *
 * Le numéro de changement est un AUTO_INCREMENT attribué à l'insertion, non au commit. Tant que le verrou du
 * cabinet est tenu jusqu'au commit, aucune autre transaction du même cabinet ne peut insérer ni committer :
 * pour un cabinet, l'ordre des numéros est l'ordre des commits, et un curseur ne dépasse jamais un changement
 * encore non committé. Toute mutation officielle à diffuser passe donc par ici ; refuser l'écriture hors
 * verrou fait respecter le protocole par construction.
 */
final class ChangeLogWriter
{
    /**
     * @param  list<ChangeRecord>  $changes
     */
    public function write(CommandContext $context, TransactionLocks $locks, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        if (! $locks->holdsClinicExclusively()) {
            throw new LogicException('Le journal des changements ne s\'écrit que sous le verrou exclusif du cabinet (publication ordonnée du pull).');
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');

        foreach ($changes as $change) {
            DB::table('sync_change_log')->insert([
                'id' => Ulid::generate(),
                'clinic_id' => $context->clinicId,
                'entity_type' => $change->entityType,
                'entity_id' => $change->entityId,
                'entity_version' => $change->entityVersion,
                'recorded_at' => $now,
                'visibility' => $change->visibility->value,
                'change_kind' => $change->kind->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
