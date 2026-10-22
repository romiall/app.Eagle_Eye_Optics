<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Disponibilités de stock (`GET /stock/availability`, I00) : physique, réservé centralement, quotas locaux non
 * consommés et disponible central, par position (article × emplacement) avec ses lots. Lecture seule, toujours limitée
 * au cabinet. Aucun coût d'achat ni valeur de stock n'y figure (permission `stock.valuation.read`, gérante).
 *
 * Disponible central = physique − réservations centrales − quotas locaux (fichier 19) : la colonne générée
 * `central_available` des positions. Le disponible LOCAL d'un poste vient de son quota, jamais d'ici.
 */
final class StockAvailabilityReader
{
    public const PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function page(string $clinicId, mixed $itemId, mixed $locationId, mixed $page = null): LengthAwarePaginator
    {
        $query = DB::table('stock_positions')->where('clinic_id', $clinicId);
        foreach (['item_id' => $itemId, 'location_id' => $locationId] as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! Ulid::isValid($value)) {
                throw BusinessError::validation([$column => ['L\'identifiant attendu est un ULID.']]);
            }
            $query->where($column, $value);
        }

        $pageNumber = 1;
        if ($page !== null) {
            if ((! is_string($page) && ! is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }

        return $query->orderBy('item_id')->orderBy('location_id')->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);
    }

    /**
     * Lots des positions d'une page, par position (expiration la plus proche d'abord, lots sans date ensuite).
     *
     * @param  list<string>  $positionIds
     * @return array<string, list<stdClass>>
     */
    public function lots(string $clinicId, array $positionIds): array
    {
        if ($positionIds === []) {
            return [];
        }

        $byPosition = [];
        $rows = DB::table('stock_lots')
            ->where('clinic_id', $clinicId)
            ->whereIn('stock_position_id', $positionIds)
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $byPosition[(string) $row->stock_position_id][] = $row;
        }

        return $byPosition;
    }
}
