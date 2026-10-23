<?php

namespace App\Application\Effects;

use App\Application\CommandOutcome;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * ACK durable d'une commande de poste APPLIQUÉE (O05 ; contrat 23, « Push, ACK et résolution »), écrit par le chemin
 * central DANS la transaction de l'effet métier : le fait, son audit, son outbox, son journal des changements, le
 * résultat `ACCEPTED` de `sync_command_results` et le préfixe finalisé du poste (`enrolled_devices.last_accepted_*`)
 * sont committés ensemble ou pas du tout. Un crash après le commit et avant la réponse ne perd donc rien : le renvoi
 * de la même commande rend l'ACK existant (`ALREADY_ACCEPTED`), jamais un second fait.
 *
 * Ordre : la commande doit être EXACTEMENT la suivante du préfixe finalisé (rang + 1, empreinte précédente égale à la
 * dernière empreinte finalisée), et ne pas être déjà finalisée. Sinon `SyncOrderViolation` annule tout : un autre
 * envoi du même poste l'a précédée, la synchronisation reprendra dans l'ordre. Le verrou du poste est déjà tenu par le
 * chemin central (rang 20) : le relire ne change pas l'ordre d'acquisition.
 */
final class SyncAcknowledgements
{
    public function accepted(CommandContext $context, TransactionLocks $locks, CommandOutcome $outcome): void
    {
        $references = $context->syncReferences;
        $deviceId = $context->deviceId;
        if ($references === null || $deviceId === null) {
            return;
        }

        $device = $locks->device($deviceId);
        if ($device === null) {
            throw new SyncOrderViolation('Poste inconnu de ce cabinet.');
        }
        $lastHash = $device->last_accepted_hash === null ? null : (string) $device->last_accepted_hash;
        if ($references->deviceSequence !== ((int) $device->last_accepted_sequence) + 1 || $lastHash !== $references->previousEventHash) {
            throw new SyncOrderViolation('La commande n\'est plus la suivante du journal finalisé de ce poste.');
        }

        $result = DB::table('sync_command_results')->where('clinic_id', $context->clinicId)->where('command_id', $references->commandId)->first();
        if ($result === null || in_array((string) $result->status, ['ACCEPTED', 'REJECTED'], true)) {
            throw new SyncOrderViolation('Commande absente de l\'inbox ou déjà finalisée.');
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('sync_command_results')->where('clinic_id', $context->clinicId)->where('id', $result->id)->update([
            'status' => 'ACCEPTED',
            'result_references' => json_encode((object) self::references($outcome->result), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'error_code' => null,
            'processed_at' => $now,
            'attempt_count' => ((int) $result->attempt_count) + 1,
            'updated_at' => $now,
        ]);
        DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $deviceId)->update([
            'last_accepted_sequence' => $references->deviceSequence,
            'last_accepted_hash' => $references->eventHash,
            'last_sync_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Identifiants d'origine des effets (`invoice_id`, `payment_id`, `receipt_id`, `document_id`…) : seuls les ULID de la
     * réponse, au premier niveau ou dans un objet de premier niveau. Jamais une donnée d'identité ni un montant.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    public static function references(array $result): array
    {
        $found = [];
        foreach ($result as $key => $value) {
            if (is_string($value) && str_ends_with($key, '_id') && Ulid::isValid($value)) {
                $found[$key] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    if (is_string($childKey) && is_string($child) && str_ends_with($childKey, '_id') && Ulid::isValid($child)) {
                        $found[isset($found[$childKey]) ? $key.'_'.$childKey : $childKey] ??= $child;
                    }
                }
            }
        }
        ksort($found, SORT_STRING);

        return $found;
    }
}
