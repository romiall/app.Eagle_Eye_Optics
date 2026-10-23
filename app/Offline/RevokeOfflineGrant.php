<?php

namespace App\Offline;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;

/**
 * Révocation d'UN grant (un profil sur un poste) par la gérante réauthentifiée, avec motif. Le grant n'est jamais
 * réécrit : `revoked_at` s'ajoute (fichier 21, « Grants »). Déjà révoqué : 409 `REVOKED`. La révocation n'atteint le
 * poste qu'à sa prochaine connexion ; le poste la constate en relisant ses grants.
 *
 * Verrou : le poste du grant (rang 20) sérialise toute écriture sur ses grants.
 */
final class RevokeOfflineGrant implements CommandHandler
{
    public const COMMAND = 'RevokeOfflineGrant';

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La révocation d\'un grant se fait en ligne, par la gérante.');
        }
        $grantId = (string) $command->entityId;
        $reason = RevokeDevice::reason($command->payload['reason'] ?? null);

        $grant = DB::table('offline_grants')->where('clinic_id', $context->clinicId)->where('id', $grantId)->first();
        if ($grant === null) {
            throw BusinessError::notFound('Grant introuvable.');
        }
        // Sous le verrou exclusif du cabinet (voir DeviceDirectory::underClinicLock) : aucune autre écriture concurrente.
        $current = DB::table('offline_grants')->where('clinic_id', $context->clinicId)->where('id', $grantId)->first();
        if ($current === null) {
            throw BusinessError::notFound('Grant introuvable.');
        }
        if ($current->revoked_at !== null) {
            throw BusinessError::stateRefused('Ce grant est déjà révoqué.', 'REVOKED');
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('offline_grants')->where('clinic_id', $context->clinicId)->where('id', $grantId)->update(['revoked_at' => $now, 'updated_at' => $now]);

        return new CommandOutcome(
            result: ['grant_id' => $grantId],
            resultType: 'offline_grants',
            resultId: $grantId,
            audit: [new AuditEntry('offline_grant.revoked', 'offline_grants', $grantId, null, [
                'device_id' => (string) $current->device_id,
                'user_id' => (string) $current->user_id,
                'reason' => $reason,
            ])],
            changes: [new ChangeRecord('offline_grants', $grantId, 2, ChangeVisibility::Manager, ChangeKind::Updated)],
        );
    }
}
