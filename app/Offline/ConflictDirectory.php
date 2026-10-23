<?php

namespace App\Offline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lectures des conflits de synchronisation pour la gérante (`approval.decide`, O05), toujours limitées au cabinet : la
 * commande d'origine (nom, poste, rang, auteur), le code et le motif du refus, la décision et son motif. Jamais la
 * charge de la commande : les instantanés restent chiffrés côté serveur.
 */
final class ConflictDirectory
{
    public const PER_PAGE = 25;

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    public static function page(string $clinicId, mixed $status, mixed $page): array
    {
        $current = is_numeric($page) ? max(1, (int) $page) : 1;
        $query = self::query($clinicId);
        if (is_string($status) && in_array($status, ['OPEN', 'RESOLVED'], true)) {
            $query->where('k.status', $status);
        }
        $total = (clone $query)->count();
        $rows = $query
            ->orderByRaw("CASE WHEN k.status = 'OPEN' THEN 0 ELSE 1 END")
            ->orderByDesc('k.created_at')
            ->orderByDesc('k.id')
            ->forPage($current, self::PER_PAGE)
            ->get()
            ->all();

        return [
            'data' => array_map(self::present(...), array_values($rows)),
            'meta' => ['current_page' => $current, 'per_page' => self::PER_PAGE, 'total' => $total, 'last_page' => max(1, (int) ceil($total / self::PER_PAGE))],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $clinicId, string $conflictId): ?array
    {
        $row = self::query($clinicId)->where('k.id', $conflictId)->first();

        return $row instanceof stdClass ? self::present($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'command_id' => (string) $row->command_id,
            'conflict_type' => (string) $row->conflict_type,
            'status' => (string) $row->status,
            'device_id' => (string) $row->device_id,
            'created_at' => self::instant($row->created_at),
            'resolution' => SyncAcks::resolutionReason($row),
            'resolved_by' => $row->resolved_by === null ? null : (string) $row->resolved_by,
            'resolved_at' => $row->resolved_at === null ? null : self::instant($row->resolved_at),
            'command_name' => (string) $row->command_name,
            'device_sequence' => (string) $row->device_sequence,
            'device_name' => (string) $row->device_name,
            'actor_name' => (string) $row->actor_name,
            'reason' => SyncAcks::refusalMessage($row),
            'decision' => SyncAcks::decision($row),
            'resolved_by_name' => $row->resolved_by_name === null ? null : (string) $row->resolved_by_name,
        ];
    }

    private static function query(string $clinicId): Builder
    {
        return DB::table('sync_conflicts as k')
            ->join('sync_commands as c', static function ($join): void {
                $join->on('c.clinic_id', '=', 'k.clinic_id')->on('c.id', '=', 'k.command_id');
            })
            ->join('enrolled_devices as d', static function ($join): void {
                $join->on('d.clinic_id', '=', 'c.clinic_id')->on('d.id', '=', 'c.device_id');
            })
            ->join('users as a', static function ($join): void {
                $join->on('a.clinic_id', '=', 'c.clinic_id')->on('a.id', '=', 'c.actor_id');
            })
            ->leftJoin('users as r', static function ($join): void {
                $join->on('r.clinic_id', '=', 'k.clinic_id')->on('r.id', '=', 'k.resolved_by');
            })
            ->where('k.clinic_id', $clinicId)
            ->select([
                'k.id', 'k.command_id', 'k.conflict_type', 'k.status', 'k.created_at', 'k.resolution', 'k.resolved_by', 'k.resolved_at',
                'k.central_snapshot_ciphertext', 'c.device_id', 'c.command_name', 'c.device_sequence',
                'd.name as device_name', 'a.display_name as actor_name', 'r.display_name as resolved_by_name',
            ]);
    }

    private static function instant(mixed $stored): string
    {
        return CarbonImmutable::parse((string) $stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
