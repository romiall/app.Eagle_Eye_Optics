<?php

namespace App\Domain\Billing\Reports;

use App\Domain\Cash\CashSessionDirectory;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * « Caisse du jour » (R01 minimal, EN LIGNE ; fichier 03 « Caisse et indicateurs », fichier 09) : ce qui a été
 * encaissé un jour civil du cabinet, par mode, avec les sessions de caisse du jour, et — pour la gérante seulement —
 * les factures émises du jour et le reste à encaisser des factures ouvertes.
 *
 * Deux portées, décidées par les droits (fichier 09 : la caissière ne lit la finance que de « sa propre session ») :
 *  - `CLINIC` (`finance.global.read`, gérante) : tout le cabinet ;
 *  - `OWN` (`cash.own_session` sans `finance.global.read`) : les encaissements que le compte a lui-même faits ce
 *    jour-là, plus les contre-écritures et remboursements rattachés à SES sessions du jour ; ses seules sessions ; ni
 *    chiffre d'affaires émis, ni créances du cabinet (champs `issued`, `credited`, `outstanding` à null).
 *
 * Source : la vue `vw_collection_events` (encaissements confirmés +, contre-écritures −, remboursements −) et la vue
 * `vw_invoice_balances` (reste dû projeté) ; jamais un statut « payé » stocké, jamais un montant inventé. Les
 * encaissements datés (`effective_at`) d'un jour antérieur comptent pour leur jour, pas pour le jour de saisie.
 * EN LIGNE : rien ne vient d'un poste hors-ligne (aucun n'existe encore), la lecture est complète à son instant.
 */
final class CashDayReport
{
    public const SCOPE_CLINIC = 'CLINIC';

    public const SCOPE_OWN = 'OWN';

    /**
     * @return array{
     *     scope: string,
     *     sessions: list<stdClass>,
     *     collections: list<array{method: string, payments_count: int, payments_amount: int, reversals_amount: int, refunds_amount: int, net_amount: int}>,
     *     net_total: int,
     *     issued: ?array{count: int, total_gross: int},
     *     credited: ?array{count: int, amount_gross: int},
     *     outstanding: ?array{invoices_count: int, amount_due: int, refundable_credit: int}
     * }
     */
    public static function build(string $clinicId, ReportDay $day, string $scope, string $actorId): array
    {
        $own = $scope === self::SCOPE_OWN;
        $sessions = CashSessionDirectory::overlapping($clinicId, $day->start, $day->end, $own ? $actorId : null);
        $sessionIds = array_map(static fn (stdClass $session): string => (string) $session->id, $sessions);

        $events = DB::table('vw_collection_events')
            ->where('clinic_id', $clinicId)
            ->where('occurred_at', '>=', $day->start)
            ->where('occurred_at', '<', $day->end);
        if ($own) {
            $events->where(static function ($inner) use ($actorId, $sessionIds): void {
                $inner->where('actor_id', $actorId);
                if ($sessionIds !== []) {
                    $inner->orWhereIn('related_cash_session_id', $sessionIds);
                }
            });
        }

        /** @var array<string, array{method: string, payments_count: int, payments_amount: int, reversals_amount: int, refunds_amount: int, net_amount: int}> $byMethod */
        $byMethod = [];
        foreach ($events->selectRaw('method, event_type, COUNT(*) AS events, SUM(signed_amount) AS total')
            ->groupBy('method', 'event_type')
            ->get() as $row) {
            $method = (string) $row->method;
            $byMethod[$method] ??= ['method' => $method, 'payments_count' => 0, 'payments_amount' => 0, 'reversals_amount' => 0, 'refunds_amount' => 0, 'net_amount' => 0];
            $total = (int) $row->total;
            $type = (string) $row->event_type;
            if ($type === 'PAYMENT') {
                $byMethod[$method]['payments_count'] = (int) $row->events;
                $byMethod[$method]['payments_amount'] = $total;
            } elseif ($type === 'PAYMENT_REVERSAL') {
                // Contre-écritures et remboursements sont des montants NÉGATIFS dans la vue : rendus en valeur absolue.
                $byMethod[$method]['reversals_amount'] = -$total;
            } elseif ($type === 'REFUND') {
                $byMethod[$method]['refunds_amount'] = -$total;
            }
            $byMethod[$method]['net_amount'] += $total;
        }
        ksort($byMethod);
        $net = 0;
        foreach ($byMethod as $entry) {
            $net += $entry['net_amount'];
        }

        $issued = null;
        $credited = null;
        $outstanding = null;
        if (! $own) {
            $issuedRow = DB::table('invoices')
                ->where('clinic_id', $clinicId)
                ->where('status', 'ISSUED')
                ->where('issued_at', '>=', $day->start)
                ->where('issued_at', '<', $day->end)
                ->selectRaw('COUNT(*) AS invoices, COALESCE(SUM(total_gross), 0) AS total')
                ->first();
            $issued = ['count' => (int) ($issuedRow->invoices ?? 0), 'total_gross' => (int) ($issuedRow->total ?? 0)];

            $creditRow = DB::table('credit_notes')
                ->where('clinic_id', $clinicId)
                ->where('status', 'ISSUED')
                ->where('issued_at', '>=', $day->start)
                ->where('issued_at', '<', $day->end)
                ->selectRaw('COUNT(*) AS notes, COALESCE(SUM(amount_gross), 0) AS total')
                ->first();
            $credited = ['count' => (int) ($creditRow->notes ?? 0), 'amount_gross' => (int) ($creditRow->total ?? 0)];

            // Reste à encaisser des factures ouvertes À L'INSTANT de la lecture (pas au soir du jour demandé).
            $balanceRow = DB::table('vw_invoice_balances')
                ->where('clinic_id', $clinicId)
                ->selectRaw('SUM(CASE WHEN amount_due > 0 THEN 1 ELSE 0 END) AS open_invoices, COALESCE(SUM(amount_due), 0) AS due, COALESCE(SUM(refundable_credit), 0) AS credit')
                ->first();
            $outstanding = [
                'invoices_count' => (int) ($balanceRow->open_invoices ?? 0),
                'amount_due' => (int) ($balanceRow->due ?? 0),
                'refundable_credit' => (int) ($balanceRow->credit ?? 0),
            ];
        }

        return [
            'scope' => $scope,
            'sessions' => $sessions,
            'collections' => array_values($byMethod),
            'net_total' => $net,
            'issued' => $issued,
            'credited' => $credited,
            'outstanding' => $outstanding,
        ];
    }
}
