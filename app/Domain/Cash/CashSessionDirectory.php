<?php

namespace App\Domain\Cash;

use App\Domain\Shared\BusinessError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Sessions de caisse du cabinet pour la gérante (`cash.manage`, B05) : les sessions ouvertes de tous les postes et
 * caissières, puis les sessions récemment clôturées, avec le nom ACTUEL de la caissière, de qui a clôturé et de la
 * caisse. Les totaux restent des projections (voir `CashSessions::totals`), calculés par le présentateur.
 *
 * `state` : `open` (sessions ouvertes seulement), `closed` (clôturées, les plus récentes d'abord), ou absent (ouvertes
 * d'abord, puis clôturées). Toujours limitée au cabinet ; 25 par page.
 */
final class CashSessionDirectory
{
    public const PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public static function page(string $clinicId, mixed $state, mixed $page = null): LengthAwarePaginator
    {
        $query = self::base($clinicId);
        if ($state !== null && $state !== '') {
            if ($state === 'open') {
                $query->whereNull('cash_sessions.closed_at');
            } elseif ($state === 'closed') {
                $query->whereNotNull('cash_sessions.closed_at');
            } else {
                throw BusinessError::validation(['state' => ['État attendu : open ou closed.']]);
            }
        }

        $pageNumber = 1;
        if ($page !== null) {
            if ((! is_string($page) && ! is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }

        return $query
            // Ouvertes d'abord (closed_at nul), puis les plus récentes.
            ->orderByRaw('cash_sessions.closed_at IS NULL DESC')
            ->orderByDesc('cash_sessions.opened_at')
            ->orderByDesc('cash_sessions.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);
    }

    /**
     * Une session et ses libellés (caissière, caisse, clôture), dans le cabinet.
     */
    public static function find(string $clinicId, string $sessionId): ?stdClass
    {
        $row = self::base($clinicId)->where('cash_sessions.id', $sessionId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Sessions qui recouvrent une fenêtre UTC (ouvertes avant la fin, et non clôturées avant le début), éventuellement
     * limitées à une caissière.
     *
     * @return list<stdClass>
     */
    public static function overlapping(string $clinicId, string $start, string $end, ?string $cashierId = null): array
    {
        $query = self::base($clinicId)
            ->where('cash_sessions.opened_at', '<', $end)
            ->where(static function ($inner) use ($start): void {
                $inner->whereNull('cash_sessions.closed_at')->orWhere('cash_sessions.closed_at', '>=', $start);
            });
        if ($cashierId !== null) {
            $query->where('cash_sessions.cashier_id', $cashierId);
        }

        return array_values($query->orderBy('cash_sessions.opened_at')->orderBy('cash_sessions.id')->get()->all());
    }

    private static function base(string $clinicId): Builder
    {
        return DB::table('cash_sessions')
            ->leftJoin('users as cashiers', static function ($join): void {
                $join->on('cashiers.clinic_id', '=', 'cash_sessions.clinic_id')->on('cashiers.id', '=', 'cash_sessions.cashier_id');
            })
            ->leftJoin('users as closers', static function ($join): void {
                $join->on('closers.clinic_id', '=', 'cash_sessions.clinic_id')->on('closers.id', '=', 'cash_sessions.closed_by');
            })
            ->leftJoin('cash_registers', static function ($join): void {
                $join->on('cash_registers.clinic_id', '=', 'cash_sessions.clinic_id')->on('cash_registers.id', '=', 'cash_sessions.cash_register_id');
            })
            ->where('cash_sessions.clinic_id', $clinicId)
            ->select(
                'cash_sessions.*',
                'cashiers.display_name as cashier_name',
                'closers.display_name as closed_by_name',
                'cash_registers.code as register_code',
                'cash_registers.name as register_name',
            );
    }
}
