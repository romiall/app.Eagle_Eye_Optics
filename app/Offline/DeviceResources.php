<?php

namespace App\Offline;

use App\Domain\Billing\Catalog\CatalogSnapshot;
use App\Domain\Shared\RulesVersion;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Préparation d'un poste telle que le poste la reçoit et la conserve (O03 ; contrat `Preparation`) : grants actifs
 * des profils, plages de numéros ACTIVES avec leur format de rendu, quotas ACTIFS, factures dont il est propriétaire,
 * catalogue figé (contenu et empreinte, celle que signe le grant), version des règles, heure du serveur et préfixe
 * finalisé de son journal. Aucune donnée patient : identifiants opaques, numéros, quantités et prix de vente.
 */
final class DeviceResources
{
    /**
     * @param  Closure(stdClass, CarbonImmutable): array<string, mixed>  $presentGrant  présentation d'un grant (contrôleur)
     * @return array<string, mixed>
     */
    public static function preparation(string $clinicId, string $deviceId, CarbonImmutable $now, Closure $presentGrant): array
    {
        $device = DB::table('enrolled_devices')->where('clinic_id', $clinicId)->where('id', $deviceId)->first();
        $stamp = $now->utc()->format('Y-m-d H:i:s.u');

        $grants = DB::table('offline_grants as g')
            ->leftJoin('users as u', static function ($join): void {
                $join->on('u.clinic_id', '=', 'g.clinic_id')->on('u.id', '=', 'g.user_id');
            })
            ->where('g.clinic_id', $clinicId)
            ->where('g.device_id', $deviceId)
            ->whereNull('g.revoked_at')
            ->where('g.valid_until', '>', $stamp)
            ->orderBy('g.user_id')
            ->orderByDesc('g.issued_at')
            ->get(['g.*', 'u.display_name as user_name']);

        $ranges = DB::table('document_number_allocations')
            ->where('clinic_id', $clinicId)
            ->where('device_id', $deviceId)
            ->whereIn('status', ['ACTIVE', 'CLOSING'])
            ->orderBy('document_type')
            ->orderBy('document_year')
            ->orderBy('range_start')
            ->get()
            ->map(static function (object $row): array {
                $format = NumberFormats::of((string) $row->document_type);

                return [
                    'allocation_id' => (string) $row->id,
                    'grant_id' => (string) $row->grant_id,
                    'document_type' => (string) $row->document_type,
                    'document_year' => (int) $row->document_year,
                    'range_start' => (string) $row->range_start,
                    'range_end' => (string) $row->range_end,
                    'last_consumed_value' => $row->last_consumed_value === null ? null : (string) $row->last_consumed_value,
                    'status' => (string) $row->status,
                    'prefix' => $format['prefix'],
                    'width' => $format['width'],
                ];
            })
            ->all();

        $quotas = DB::table('device_stock_allocations as q')
            ->join('stock_positions as p', static function ($join): void {
                $join->on('p.clinic_id', '=', 'q.clinic_id')->on('p.id', '=', 'q.stock_position_id');
            })
            ->where('q.clinic_id', $clinicId)
            ->where('q.device_id', $deviceId)
            ->whereIn('q.status', ['ACTIVE', 'CLOSING'])
            ->orderBy('q.id')
            ->get(['q.*', 'p.item_id'])
            ->map(static fn (object $row): array => [
                'allocation_id' => (string) $row->id,
                'item_id' => (string) $row->item_id,
                'stock_position_id' => (string) $row->stock_position_id,
                'stock_lot_id' => $row->stock_lot_id === null ? null : (string) $row->stock_lot_id,
                'allocated_quantity' => (string) $row->allocated_quantity,
                'consumed_quantity' => (string) $row->consumed_quantity,
                'returned_quantity' => (string) $row->returned_quantity,
                'status' => (string) $row->status,
            ])
            ->all();

        $owners = DB::table('invoice_write_owners')
            ->where('clinic_id', $clinicId)
            ->where('device_id', $deviceId)
            ->whereIn('status', ['ACTIVE', 'CLOSING'])
            ->orderBy('invoice_id')
            ->get()
            ->map(static fn (object $row): array => [
                'owner_id' => (string) $row->id,
                'invoice_id' => (string) $row->invoice_id,
                'epoch' => (int) $row->epoch,
            ])
            ->all();

        $content = CatalogSnapshot::content($clinicId);
        $catalog = CatalogSnapshot::of($clinicId);

        return [
            'device_id' => $deviceId,
            'grants' => array_values($grants->map(static fn (stdClass $row): array => $presentGrant($row, $now))->all()),
            'number_allocations' => array_values($ranges),
            'stock_allocations' => array_values($quotas),
            'write_owners' => array_values($owners),
            'catalog' => [
                'catalog_version' => $catalog['catalog_version'],
                'snapshot_sha256' => $catalog['sha256'],
                'snapshot' => $content,
            ],
            'rules_version' => RulesVersion::CURRENT,
            'server_time' => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'last_accepted_sequence' => $device === null ? '0' : (string) $device->last_accepted_sequence,
            'last_accepted_hash' => $device === null || $device->last_accepted_hash === null ? null : (string) $device->last_accepted_hash,
        ];
    }
}
