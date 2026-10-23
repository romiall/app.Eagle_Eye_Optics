<?php

namespace App\Offline;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * `ResolveSyncConflict` (O05 ; contrat 23 : « Résolution finale sans application : REJECTED motivé et autorisé, journal
 * conservé » ; « aucune résolution anonyme ») : décision MOTIVÉE de la gérante (`approval.decide`, réauthentification
 * récente), EN LIGNE seulement, sur un conflit ouvert d'une commande de poste.
 *
 *  - ACCEPT_LOCAL : la commande est appliquée TELLE QU'ELLE après revue. Elle repasse « reçue » et le prochain passage
 *    de l'inbox la rejoue par le chemin central, en levant le seul verdict « en revue » de l'autorisation historique
 *    (horloge, révocation) ; tout autre refus métier rouvre un nouveau conflit, jamais un succès fictif ;
 *  - KEEP_CENTRAL, REJECT : la commande est finalisée REJECTED (aucun fait central ne la reflète) ;
 *  - CORRECT : finalisée REJECTED, avec la référence de la commande correctrice (nouvel identifiant, émise à part).
 *
 * Une résolution ne réécrit jamais le cœur, l'empreinte ni la signature : la commande reste dans l'inbox et ses
 * instantanés restent chiffrés. Un rejet finalise le rang : le préfixe du poste avance et N+1 reprend (contrat 23).
 * Lectures et écritures sous le verrou exclusif du cabinet, que tient toute écriture de l'inbox et du préfixe : le poste
 * n'est pas reverrouillé après l'idempotence (ordre du contrat 23, voir `DeviceDirectory::underClinicLock`).
 */
final class ResolveConflict implements CommandHandler
{
    public const COMMAND = 'ResolveSyncConflict';

    public const DECISIONS = ['ACCEPT_LOCAL', 'KEEP_CENTRAL', 'REJECT', 'CORRECT'];

    public function permissions(): array
    {
        return ['approval.decide'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct || $context->actorId === null) {
            throw BusinessError::forbidden('La résolution d\'un conflit de synchronisation se fait en ligne, par la gérante.');
        }
        $conflictId = (string) $command->entityId;
        $decision = (string) ($command->payload['decision'] ?? '');
        $reason = trim((string) ($command->payload['reason'] ?? ''));
        $correction = $command->payload['correction_command_id'] ?? null;

        $errors = [];
        if (! in_array($decision, self::DECISIONS, true)) {
            $errors['decision'] = ['Décision attendue : ACCEPT_LOCAL, KEEP_CENTRAL, REJECT ou CORRECT.'];
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            $errors['reason'] = ['Un motif de 3 à 1000 caractères est obligatoire : il est conservé avec la décision.'];
        }
        if ($decision === 'CORRECT' && (! is_string($correction) || ! Ulid::isValid($correction))) {
            $errors['correction_command_id'] = ['Une correction désigne la commande correctrice (nouvel identifiant).'];
        } elseif ($decision !== 'CORRECT' && $correction !== null) {
            $errors['correction_command_id'] = ['Seule une correction désigne une commande correctrice.'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $conflict = DeviceDirectory::underClinicLock('sync_conflicts', $context->clinicId, $conflictId);
        if ($conflict === null) {
            throw BusinessError::notFound('Conflit de synchronisation introuvable.');
        }
        if ((string) $conflict->status !== 'OPEN') {
            throw BusinessError::stateRefused('Ce conflit est déjà résolu.', (string) $conflict->status);
        }
        $syncCommand = DeviceDirectory::underClinicLock('sync_commands', $context->clinicId, (string) $conflict->command_id);
        $result = DB::table('sync_command_results')->where('clinic_id', $context->clinicId)->where('command_id', $conflict->command_id)->first();
        if ($syncCommand === null || $result === null || (string) $result->status !== 'CONFLICT_REQUIRES_REVIEW') {
            throw BusinessError::stateRefused('La commande de ce conflit n\'attend plus de décision.', $result === null ? 'UNKNOWN' : (string) $result->status);
        }
        if ($correction !== null && $correction === (string) $syncCommand->id) {
            throw BusinessError::validation(['correction_command_id' => ['La correction a son propre identifiant, distinct de la commande d\'origine.']]);
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('sync_conflicts')->where('clinic_id', $context->clinicId)->where('id', $conflictId)->update([
            'status' => 'RESOLVED',
            'resolution' => json_encode(['decision' => $decision, 'reason' => $reason, 'correction_command_id' => $correction], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'resolved_by' => $context->actorId,
            'resolved_at' => $now,
            'updated_at' => $now,
        ]);

        $finalStatus = $decision === 'ACCEPT_LOCAL' ? 'RECEIVED' : 'REJECTED';
        DB::table('sync_command_results')->where('clinic_id', $context->clinicId)->where('id', $result->id)->update([
            'status' => $finalStatus,
            'processed_at' => $now,
            'updated_at' => $now,
        ]);

        $deviceId = (string) $syncCommand->device_id;
        $advanced = false;
        if ($finalStatus === 'REJECTED') {
            $device = DeviceDirectory::underClinicLock('enrolled_devices', $context->clinicId, $deviceId);
            $advanced = $device !== null && SyncDrain::advanceUnderLock($context->clinicId, $deviceId, $device, $context->now);
        }

        return new CommandOutcome(
            result: [
                'conflict_id' => $conflictId,
                'command_id' => (string) $syncCommand->id,
                'device_id' => $deviceId,
                'decision' => $decision,
                'command_status' => $finalStatus,
                'prefix_advanced' => $advanced,
            ],
            resultType: 'sync_conflicts',
            resultId: $conflictId,
            audit: [new AuditEntry('sync.conflict_resolved', 'sync_conflicts', $conflictId, $reason, [
                'decision' => $decision,
                'command_id' => (string) $syncCommand->id,
                'command_name' => (string) $syncCommand->command_name,
                'device_id' => $deviceId,
                'device_sequence' => (string) $syncCommand->device_sequence,
                'conflict_type' => (string) $conflict->conflict_type,
                'correction_command_id' => $correction,
            ])],
        );
    }
}
