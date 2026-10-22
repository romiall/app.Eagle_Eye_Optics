<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation du stock pour l'API (`StockAvailability`, `StockMovement` de `schemas/resources/stock.json`) :
 * quantités en chaînes décimales exactes (`DECIMAL(12,3)`), jamais en nombres. Le disponible central est la colonne
 * générée de la position (physique − réservé − quotas locaux).
 */
final class StockPresenter
{
    /**
     * @param  list<stdClass>  $lots
     * @return array<string, mixed>
     */
    public static function availability(stdClass $position, array $lots): array
    {
        return [
            'item_id' => $position->item_id,
            'location_id' => $position->location_id,
            'stock_position_id' => $position->id,
            'on_hand' => (string) $position->on_hand,
            'reserved' => (string) $position->reserved,
            'offline_allocated' => (string) $position->offline_allocated,
            'central_available' => (string) $position->central_available,
            'lots' => array_map(static fn (stdClass $lot): array => [
                'lot_id' => $lot->id,
                'batch_number' => $lot->batch_number,
                'expires_on' => $lot->expires_on,
                'status' => $lot->status,
                'on_hand' => (string) $lot->on_hand,
                'reserved' => (string) $lot->reserved,
                'offline_allocated' => (string) $lot->offline_allocated,
            ], $lots),
        ];
    }

    /**
     * Emplacement de stock (`StockLocation`).
     *
     * @return array<string, mixed>
     */
    public static function location(stdClass $row): array
    {
        return ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'active' => (bool) $row->active];
    }

    /**
     * @return array<string, mixed>
     */
    public static function movement(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'position_id' => $row->position_id,
            'lot_id' => $row->lot_id,
            'kind' => $row->kind,
            'signed_quantity' => (string) $row->signed_quantity,
            'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'reason' => $row->reason,
            'approval_id' => $row->approval_id,
        ];
    }
}
