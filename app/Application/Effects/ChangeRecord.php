<?php

namespace App\Application\Effects;

/**
 * Changement d'une entité à diffuser aux postes (`sync_change_log`). Écrit sous le verrou du cabinet, donc
 * dans l'ordre de commit : le curseur du pull ne peut pas dépasser un changement encore non committé.
 */
final readonly class ChangeRecord
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public int $entityVersion,
        public ChangeVisibility $visibility,
        public ChangeKind $kind,
    ) {}
}
