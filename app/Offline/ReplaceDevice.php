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
 * Remplacement d'un poste par un poste NEUF déjà enrôlé (O07 ; fichier 19 « Transfert » et poste perdu ; fichier 09 :
 * aucun clone des droits). Gérante réauthentifiée, en ligne, motif obligatoire.
 *
 * Effets, sous le verrou du cabinet :
 *  - l'ancien poste passe `REPLACED`, `replaced_by_device_id` = le nouveau ; sa clé publique reste pour vérifier les
 *    commandes qu'il a déjà signées ;
 *  - TOUS ses grants encore actifs sont révoqués : aucun droit de l'ancien poste n'est transféré ni cloné ; le nouveau
 *    poste reçoit ses propres grants (`IssueOfflineGrant`) et ses propres plages (`PrepareDevice`) ;
 *  - ses plages de numéros, quotas de stock et propriétés de facture encore ACTIFS passent `CLOSING` : plus aucune
 *    ressource nouvelle n'est accordée à l'ancien poste ; les pièces qu'il a déjà émises (dans l'export chiffré) se
 *    reprennent avec LEURS numéros (`POST /devices/{id}/recovered-push`), puis `ReconcileReplacement` clôture.
 *
 * Refus : ancien poste inconnu (404), déjà remplacé (409 `REPLACED`) ; poste de remplacement inconnu, identique, non
 * actif ou lui-même remplacé (422).
 */
final class ReplaceDevice implements CommandHandler
{
    public const COMMAND = 'ReplaceDevice';

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Le remplacement d\'un poste se fait en ligne, par la gérante.');
        }
        $oldId = (string) $command->entityId;
        $newId = (string) ($command->payload['replacement_device_id'] ?? '');
        $reason = RevokeDevice::reason($command->payload['reason'] ?? null);

        $old = DeviceDirectory::underClinicLock('enrolled_devices', $context->clinicId, $oldId);
        if ($old === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }
        if ((string) $old->status === 'REPLACED') {
            throw BusinessError::stateRefused('Ce poste est déjà remplacé.', 'REPLACED');
        }
        if ($newId === $oldId) {
            throw BusinessError::validation(['replacement_device_id' => ['Le poste de remplacement doit être un autre poste.']]);
        }
        $new = DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $newId)->first();
        if ($new === null || (string) $new->status !== 'ACTIVE' || $new->replaced_by_device_id !== null) {
            throw BusinessError::validation(['replacement_device_id' => ['Le poste de remplacement doit être enrôlé et actif dans ce cabinet.']]);
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $oldId)
            ->update(['status' => 'REPLACED', 'replaced_by_device_id' => $newId, 'updated_at' => $now]);

        $grants = array_map('strval', DB::table('offline_grants')->where('clinic_id', $context->clinicId)->where('device_id', $oldId)
            ->whereNull('revoked_at')->orderBy('id')->pluck('id')->all());
        if ($grants !== []) {
            DB::table('offline_grants')->where('clinic_id', $context->clinicId)->whereIn('id', $grants)->update(['revoked_at' => $now, 'updated_at' => $now]);
        }

        $changes = [new ChangeRecord('enrolled_devices', $oldId, 3, ChangeVisibility::Manager, ChangeKind::Updated)];
        foreach ($grants as $grant) {
            $changes[] = new ChangeRecord('offline_grants', $grant, 2, ChangeVisibility::Manager, ChangeKind::Updated);
        }
        $ranges = $this->closing('document_number_allocations', $context->clinicId, $oldId, $now, $changes, ChangeVisibility::Manager);
        $quotas = $this->closing('device_stock_allocations', $context->clinicId, $oldId, $now, $changes, ChangeVisibility::Manager);
        $owners = $this->closingOwners($context->clinicId, $oldId, $now, $changes);

        return new CommandOutcome(
            result: [
                'device_id' => $oldId,
                'replacement_device_id' => $newId,
                'status' => 'REPLACED',
                'revoked_grants' => count($grants),
                'closing_number_allocations' => $ranges,
                'closing_stock_allocations' => $quotas,
                'closing_write_owners' => $owners,
                'replaced_at' => $context->now->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
            resultType: 'enrolled_devices',
            resultId: $oldId,
            audit: [new AuditEntry('device.replaced', 'enrolled_devices', $oldId, $reason, [
                'previous_status' => (string) $old->status,
                'replacement_device_id' => $newId,
                'revoked_grants' => $grants,
                'closing_number_allocations' => $ranges,
                'closing_stock_allocations' => $quotas,
                'closing_write_owners' => $owners,
            ])],
            changes: $changes,
        );
    }

    /**
     * Ressources ACTIVES d'une table d'allocations passées CLOSING (version + 1).
     *
     * @param  list<ChangeRecord>  $changes
     * @return list<string>
     */
    private function closing(string $table, string $clinicId, string $deviceId, string $now, array &$changes, ChangeVisibility $visibility): array
    {
        $rows = DB::table($table)->where('clinic_id', $clinicId)->where('device_id', $deviceId)->where('status', 'ACTIVE')
            ->orderBy('id')->lockForUpdate()->get(['id', 'version']);
        $ids = [];
        foreach ($rows as $row) {
            $version = (int) $row->version + 1;
            DB::table($table)->where('clinic_id', $clinicId)->where('id', $row->id)->update(['status' => 'CLOSING', 'version' => $version, 'updated_at' => $now]);
            $ids[] = (string) $row->id;
            $changes[] = new ChangeRecord($table, (string) $row->id, $version, $visibility, ChangeKind::Updated);
        }

        return $ids;
    }

    /**
     * Propriétés de facture ACTIVES de l'ancien poste passées CLOSING : la facture reste réservée à la reprise de ses
     * pièces (aucune écriture centrale concurrente) jusqu'à `ReconcileReplacement`.
     *
     * @param  list<ChangeRecord>  $changes
     * @return list<string>
     */
    private function closingOwners(string $clinicId, string $deviceId, string $now, array &$changes): array
    {
        $rows = DB::table('invoice_write_owners')->where('clinic_id', $clinicId)->where('device_id', $deviceId)->where('status', 'ACTIVE')
            ->orderBy('id')->lockForUpdate()->pluck('id');
        $ids = [];
        foreach ($rows as $id) {
            DB::table('invoice_write_owners')->where('clinic_id', $clinicId)->where('id', $id)->update(['status' => 'CLOSING', 'updated_at' => $now]);
            $ids[] = (string) $id;
            $changes[] = new ChangeRecord('invoice_write_owners', (string) $id, 2, ChangeVisibility::Financial, ChangeKind::Updated);
        }

        return $ids;
    }
}
