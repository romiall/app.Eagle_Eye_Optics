<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Emplacements de stock du cabinet (`stock_locations` : rayon, atelier, réserve), lus pour choisir où saisir un stock
 * initial ou filtrer les disponibilités (I00). Les emplacements actifs seulement, par défaut ; l'emplacement
 * `PRINCIPAL` est créé par `eeo:sales:bootstrap`. Aucune route n'en crée : leur gestion viendra avec L1 (réceptions,
 * inventaires). Toujours limité au cabinet.
 */
final class StockLocations
{
    /**
     * @return list<stdClass>
     */
    public static function list(string $clinicId, bool $includeInactive = false): array
    {
        $query = DB::table('stock_locations')->where('clinic_id', $clinicId);
        if (! $includeInactive) {
            $query->where('active', 1);
        }

        return array_values($query->orderBy('code')->orderBy('id')->get(['id', 'code', 'name', 'active'])->all());
    }
}
