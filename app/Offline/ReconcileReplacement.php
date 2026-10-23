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
 * Réconciliation d'un poste REMPLACÉ après la reprise de ses pièces (O07 ; « Mêmes IDs/numéros pour anciens faits »,
 * « Aucun clone actif des droits non consommés »). Gérante réauthentifiée, en ligne, motif obligatoire.
 *
 * L'export chiffré de l'ancien poste a été ouvert sur le poste neuf avec la clé de récupération, et ses commandes
 * signées ont été transmises TELLES QUELLES (`POST /devices/{id}/recovered-push` : mêmes identifiants, mêmes numéros,
 * mêmes signatures, ACK durables). La séquence et l'empreinte annoncées sont celles du manifeste de l'export : elles
 * doivent être EXACTEMENT le préfixe finalisé et vérifié du serveur pour l'ancien poste, et aucune commande de ce poste
 * ne doit rester reçue au-delà (attente, dépendance, conflit). Sinon 409 `SYNC_INCOMPLETE`, rien n'est clôturé.
 *
 * Effets : comme une restitution après reprise complète (`ReleaseResources::releaseAll`) : propriétés de facture
 * CLOSED, reliquats de quota rendus au central, plages CLOSED avec leurs numéros inutilisés inscrits `UNUSED` (jamais
 * recyclés). L'ancien poste reste `REPLACED` : s'il réapparaît, ses nouvelles commandes passent en revue (conflit
 * `DEVICE_REPLACED`), jamais appliquées d'office ; ses numéros pris après l'export tombent dans une plage close.
 */
final class ReconcileReplacement implements CommandHandler
{
    public const COMMAND = 'ReconcileReplacement';

    public function __construct(private readonly ReleaseResources $release) {}

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La réconciliation d\'un poste remplacé se fait en ligne, par la gérante.');
        }
        $deviceId = (string) $command->entityId;
        $declaredSequence = $command->payload['last_device_sequence'] ?? null;
        $declaredHash = $command->payload['last_event_hash'] ?? null;
        $reason = RevokeDevice::reason($command->payload['reason'] ?? null);

        $device = DeviceDirectory::underClinicLock('enrolled_devices', $context->clinicId, $deviceId);
        if ($device === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }
        if ((string) $device->status !== 'REPLACED') {
            throw BusinessError::stateRefused('Seul un poste remplacé se réconcilie ainsi : déclarez d\'abord son remplacement.', (string) $device->status);
        }
        if (! is_string($declaredSequence) || preg_match('/^(0|[1-9][0-9]{0,18})$/', $declaredSequence) !== 1) {
            throw BusinessError::validation(['last_device_sequence' => ['Dernière séquence du journal de l\'ancien poste attendue (chaîne de chiffres).']]);
        }
        $accepted = (string) $device->last_accepted_sequence;
        $acceptedHash = $device->last_accepted_hash === null ? null : (string) $device->last_accepted_hash;
        if ($declaredSequence !== $accepted || $declaredHash !== $acceptedHash) {
            throw BusinessError::stateRefused(
                'Reprise incomplète : le journal exporté (séquence '.$declaredSequence.') n\'est pas entièrement finalisé sur le serveur (séquence '.$accepted.'). Transmettez d\'abord toutes ses commandes.',
                'SYNC_INCOMPLETE',
            );
        }
        $beyond = DB::table('sync_commands')->where('clinic_id', $context->clinicId)->where('device_id', $deviceId)
            ->where('device_sequence', '>', (int) $device->last_accepted_sequence)->exists();
        if ($beyond) {
            throw BusinessError::stateRefused('Des commandes de l\'ancien poste sont reçues mais pas finalisées (attente, dépendance ou revue) : rien n\'est clôturé.', 'SYNC_INCOMPLETE');
        }

        $stamp = $context->now->utc()->format('Y-m-d H:i:s.u');
        $released = $this->release->releaseAll($context, $locks, $deviceId, (int) $device->last_accepted_sequence, $stamp);

        $changes = [];
        foreach ($released['owners'] as $ownerId) {
            $changes[] = new ChangeRecord('invoice_write_owners', $ownerId, 3, ChangeVisibility::Financial, ChangeKind::Updated);
        }
        foreach ($released['returned']['quotas'] as $quotaId => $version) {
            $changes[] = new ChangeRecord('device_stock_allocations', (string) $quotaId, $version, ChangeVisibility::Manager, ChangeKind::Updated);
        }
        foreach ($released['returned']['positions'] as $positionId => $version) {
            $changes[] = new ChangeRecord('stock_positions', (string) $positionId, $version, ChangeVisibility::Administrative, ChangeKind::Updated);
        }
        foreach ($released['ranges'] as $range) {
            $changes[] = new ChangeRecord('document_number_allocations', $range['id'], $range['version'], ChangeVisibility::Manager, ChangeKind::Updated);
        }

        return new CommandOutcome(
            result: [
                'device_id' => $deviceId,
                'closed_number_allocations' => array_map(static fn (array $range): string => $range['id'], $released['ranges']),
                'returned_stock_allocations' => array_map('strval', array_keys($released['returned']['quotas'])),
                'closed_write_owners' => $released['owners'],
                'released_at' => $context->now->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
            resultType: 'enrolled_devices',
            resultId: $deviceId,
            audit: [new AuditEntry('device.replacement_reconciled', 'enrolled_devices', $deviceId, $reason, [
                'replacement_device_id' => $device->replaced_by_device_id,
                'last_accepted_sequence' => $accepted,
                'closed_write_owners' => $released['owners'],
                'returned_stock_allocations' => array_map('strval', array_keys($released['returned']['quotas'])),
                'closed_number_allocations' => array_map(static fn (array $range): array => [
                    'allocation_id' => $range['id'],
                    'unused' => $range['unused'],
                ], $released['ranges']),
            ])],
            changes: $changes,
        );
    }
}
