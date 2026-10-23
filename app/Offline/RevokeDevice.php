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
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Illuminate\Support\Facades\DB;

/**
 * Révocation d'un poste (O02 : « révocation différée ») par la gérante réauthentifiée : statut `REVOKED` (retrait
 * décidé) ou `LOST` (poste perdu ou volé), motif obligatoire, et révocation de TOUS ses grants encore actifs dans la
 * même transaction. Le poste déconnecté ne l'apprend qu'à sa prochaine connexion (fichier 19) : les commandes qu'il
 * aurait déjà signées seront examinées dans leur contexte d'autorisation à la synchronisation (O05), jamais
 * supprimées. Un poste déjà révoqué, perdu ou remplacé : 409 avec son statut. Rien n'est effacé : la clé publique
 * reste pour vérifier les commandes déjà émises.
 */
final class RevokeDevice implements CommandHandler
{
    public const COMMAND = 'RevokeDevice';

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La révocation d\'un poste se fait en ligne, par la gérante.');
        }
        $deviceId = (string) $command->entityId;
        $status = (string) ($command->payload['status'] ?? '');
        if (! in_array($status, ['REVOKED', 'LOST'], true)) {
            throw BusinessError::validation(['status' => ['Statut attendu : REVOKED (retiré) ou LOST (perdu ou volé).']]);
        }
        $reason = self::reason($command->payload['reason'] ?? null);

        $device = DeviceDirectory::underClinicLock('enrolled_devices', $context->clinicId, $deviceId);
        if ($device === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }
        if ((string) $device->status !== 'ACTIVE') {
            throw BusinessError::stateRefused('Ce poste n\'est plus actif.', (string) $device->status);
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $deviceId)->update(['status' => $status, 'updated_at' => $now]);
        $grants = DB::table('offline_grants')->where('clinic_id', $context->clinicId)->where('device_id', $deviceId)->whereNull('revoked_at')->orderBy('id')->pluck('id')->all();
        if ($grants !== []) {
            DB::table('offline_grants')->where('clinic_id', $context->clinicId)->whereIn('id', $grants)->update(['revoked_at' => $now, 'updated_at' => $now]);
        }

        $changes = [new ChangeRecord('enrolled_devices', $deviceId, 2, ChangeVisibility::Manager, ChangeKind::Updated)];
        foreach ($grants as $grant) {
            $changes[] = new ChangeRecord('offline_grants', (string) $grant, 2, ChangeVisibility::Manager, ChangeKind::Updated);
        }

        return new CommandOutcome(
            result: ['device_id' => $deviceId, 'status' => $status, 'revoked_grants' => count($grants)],
            resultType: 'enrolled_devices',
            resultId: $deviceId,
            audit: [new AuditEntry('device.revoked', 'enrolled_devices', $deviceId, $reason, [
                'avant' => ['status' => 'ACTIVE'],
                'apres' => ['status' => $status],
                'revoked_grants' => $grants,
            ])],
            changes: $changes,
        );
    }

    /** Motif obligatoire d'une révocation (3 à 1000 caractères, blancs de bord retirés). */
    public static function reason(mixed $value): string
    {
        try {
            $reason = Text::optional(is_string($value) ? $value : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['reason' => [$violation->getMessage()]]);
        }
        if ($reason === null || mb_strlen($reason, 'UTF-8') < 3) {
            throw BusinessError::validation(['reason' => ['Indiquez le motif (au moins 3 caractères).']]);
        }

        return $reason;
    }
}
