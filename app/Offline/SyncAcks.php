<?php

namespace App\Offline;

use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * ACK de chaque commande d'un lot (contrat `sync/ack.json`), relus dans l'état DURABLE du serveur après application :
 *
 *  - ACCEPTED : fait appliqué, identifiants d'origine des effets et versions (réponse idempotente de la commande) ;
 *    ALREADY_ACCEPTED quand il l'était déjà avant cet envoi (transport d'un résultat existant, rien de réécrit) ;
 *  - WAITING_DEPENDENCY : pièces liées attendues ;
 *  - CONFLICT_REQUIRES_REVIEW : conflit ouvert, code et motif du refus ;
 *  - REJECTED : décision motivée de la gérante, jamais un fait accepté.
 *
 * Une commande conservée derrière un rang non finalisé (`RECEIVED`) n'a pas encore d'ACK : elle n'est pas dans la
 * réponse ; le poste la garde « envoyée » et la renverra (le serveur répond alors depuis son inbox).
 */
final class SyncAcks
{
    /** @var list<string>|null */
    private static ?array $errorCodes = null;

    /**
     * @param  list<string>  $commandIds  ordre du lot
     * @param  array<string, true>  $alreadyAccepted  commandes déjà ACCEPTED avant cet envoi
     * @return list<array<string, mixed>>
     */
    public static function forCommands(string $clinicId, string $deviceId, array $commandIds, array $alreadyAccepted): array
    {
        $acks = [];
        foreach ($commandIds as $commandId) {
            $command = DB::table('sync_commands')->where('clinic_id', $clinicId)->where('id', $commandId)->first();
            $result = DB::table('sync_command_results')->where('clinic_id', $clinicId)->where('command_id', $commandId)->first();
            if ($command === null || $result === null) {
                continue;
            }
            $ack = self::ack($clinicId, $deviceId, $command, $result, isset($alreadyAccepted[$commandId]));
            if ($ack !== null) {
                $acks[] = $ack;
            }
        }

        return $acks;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function ack(string $clinicId, string $deviceId, stdClass $command, stdClass $result, bool $alreadyAccepted): ?array
    {
        $base = [
            'command_id' => (string) $command->id,
            'device_sequence' => (string) $command->device_sequence,
            'result_references' => new stdClass,
            'versions' => new stdClass,
            'dependencies' => [],
            'conflict_id' => null,
            'error_code' => null,
            'reason' => null,
        ];
        $references = self::decodeObject($result->result_references);

        switch ((string) $result->status) {
            case 'ACCEPTED':
                return ['status' => $alreadyAccepted ? 'ALREADY_ACCEPTED' : 'ACCEPTED'] + [
                    'result_references' => (object) array_filter($references, is_string(...)),
                    'versions' => (object) self::versions($deviceId, (string) $command->command_name, (string) $command->id),
                ] + $base;
            case 'WAITING_DEPENDENCY':
                $dependencies = [];
                foreach ($references as $key => $id) {
                    if (is_string($id)) {
                        $dependencies[] = ['entity_type' => explode(':', (string) $key)[0], 'entity_id' => $id];
                    }
                }
                if ($dependencies === []) {
                    $dependencies[] = ['entity_type' => (string) $command->entity_type, 'entity_id' => (string) $command->entity_id];
                }

                return ['status' => 'WAITING_DEPENDENCY', 'dependencies' => $dependencies] + $base;
            case 'CONFLICT_REQUIRES_REVIEW':
                $conflict = self::lastConflict($clinicId, (string) $command->id, 'OPEN');
                if ($conflict === null) {
                    return null;
                }

                return [
                    'status' => 'CONFLICT_REQUIRES_REVIEW',
                    'conflict_id' => (string) $conflict->id,
                    'error_code' => self::code($result->error_code ?? $conflict->conflict_type),
                    'reason' => self::refusalMessage($conflict),
                ] + $base;
            case 'REJECTED':
                $conflict = self::lastConflict($clinicId, (string) $command->id, 'RESOLVED');
                $reason = $conflict === null ? null : self::resolutionReason($conflict);

                return [
                    'status' => 'REJECTED',
                    'conflict_id' => $conflict === null ? null : (string) $conflict->id,
                    'error_code' => self::code($result->error_code),
                    'reason' => $reason !== null && mb_strlen($reason) >= 3 ? $reason : 'Rejetée après revue de la gérante.',
                ] + $base;
            default:
                return null;
        }
    }

    /**
     * Versions rendues par la commande appliquée (réponse idempotente conservée sous `device:<poste>` et `command_id`).
     *
     * @return array<string, int>
     */
    private static function versions(string $deviceId, string $commandName, string $commandId): array
    {
        $stored = DB::table('idempotency_requests')
            ->where('namespace', 'device:'.$deviceId)
            ->where('command_name', $commandName)
            ->where('request_key', strtoupper($commandId))
            ->value('result_payload');
        $result = is_string($stored) ? json_decode($stored, true) : null;
        if (! is_array($result)) {
            return [];
        }
        $versions = [];
        foreach (['draft_version' => 'draft_version', 'ledger_version' => 'ledger_version', 'version' => 'entity_version'] as $from => $to) {
            if (is_int($result[$from] ?? null) && $result[$from] >= 1) {
                $versions[$to] = $result[$from];
            }
        }

        return $versions;
    }

    private static function lastConflict(string $clinicId, string $commandId, string $status): ?stdClass
    {
        $row = DB::table('sync_conflicts')
            ->where('clinic_id', $clinicId)
            ->where('command_id', $commandId)
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $row instanceof stdClass ? $row : null;
    }

    /** Motif lisible du refus conservé dans l'instantané central chiffré (jamais une donnée de la charge). */
    public static function refusalMessage(stdClass $conflict): ?string
    {
        try {
            $snapshot = SyncPayloads::openSnapshot((string) $conflict->central_snapshot_ciphertext);
        } catch (Throwable) {
            return null;
        }
        $message = $snapshot['error']['message'] ?? null;

        return is_string($message) && $message !== '' ? mb_substr($message, 0, 1000) : null;
    }

    /** Motif de la décision de la gérante (`resolution` JSON : décision, motif, correction). */
    public static function resolutionReason(stdClass $conflict): ?string
    {
        $resolution = is_string($conflict->resolution) ? json_decode($conflict->resolution, true) : null;
        $reason = is_array($resolution) ? ($resolution['reason'] ?? null) : null;

        return is_string($reason) ? mb_substr($reason, 0, 1000) : null;
    }

    /** Décision enregistrée d'un conflit résolu (ACCEPT_LOCAL, KEEP_CENTRAL, REJECT, CORRECT), ou null. */
    public static function decision(stdClass $conflict): ?string
    {
        $resolution = is_string($conflict->resolution) ? json_decode($conflict->resolution, true) : null;
        $decision = is_array($resolution) ? ($resolution['decision'] ?? null) : null;

        return is_string($decision) ? $decision : null;
    }

    /**
     * Code d'erreur du contrat (`schemas/error.json`) : un code d'action hors du catalogue est rendu `HTTP_ERROR` dans
     * l'ACK (le code exact reste dans le conflit, `conflict_type`).
     */
    private static function code(mixed $code): ?string
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return in_array($code, self::errorCodes(), true) ? $code : 'HTTP_ERROR';
    }

    /**
     * @return list<string>
     */
    private static function errorCodes(): array
    {
        if (self::$errorCodes !== null) {
            return self::$errorCodes;
        }
        $path = base_path('contracts/schemas/error.json');
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $codes = is_array($decoded) ? ($decoded['$defs']['ErrorCode']['enum'] ?? []) : [];

        return self::$errorCodes = is_array($codes) ? array_values(array_filter($codes, is_string(...))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
