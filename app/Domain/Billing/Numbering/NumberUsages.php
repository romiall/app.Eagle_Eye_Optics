<?php

namespace App\Domain\Billing\Numbering;

use App\Domain\Shared\CommandContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Registre `document_number_usages` (append-only, triggers UPDATE et DELETE) : une ligne par numéro consommé, avec la
 * pièce qui le porte. L'identifiant de l'usage est fourni par la commande (identifiants de tous les effets connus
 * avant le commit) et repris tel quel ; il est écrit dans la même transaction que la pièce.
 */
final class NumberUsages
{
    public static function isTaken(string $usageId): bool
    {
        return DB::table('document_number_usages')->where('id', $usageId)->exists();
    }

    /**
     * `$allocationId` et `$sourceDeviceId` : plage et poste d'un numéro PRÉ-ALLOUÉ consommé à la synchronisation (O05,
     * `DeviceNumbers`) ; null pour un numéro central.
     *
     * @param  array{value: int, rendered: string, year: int}  $number
     */
    public static function record(CommandContext $context, string $usageId, string $type, array $number, string $entityType, string $entityId, CarbonImmutable $at, ?string $allocationId = null, ?string $sourceDeviceId = null): void
    {
        if ($context->actorId === null) {
            throw new LogicException('Un numéro de pièce est toujours consommé par un auteur identifié.');
        }
        $now = $at->utc()->format('Y-m-d H:i:s.u');

        DB::table('document_number_usages')->insert([
            'id' => $usageId,
            'clinic_id' => $context->clinicId,
            'allocation_id' => $allocationId,
            'document_type' => $type,
            'document_year' => $number['year'],
            'number_value' => $number['value'],
            'rendered_number' => $number['rendered'],
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'source_device_id' => $sourceDeviceId,
            'actor_id' => $context->actorId,
            'consumed_at' => $now,
            'status' => 'USED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
