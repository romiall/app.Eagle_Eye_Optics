<?php

namespace App\Domain\Cash;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Relevé d'une session de caisse (B05, fichier 03 « Caisse et indicateurs », fichier 11 « relevé de caisse ») : les
 * faits qui composent le tiroir espèces et la liste des encaissements de la session, pour l'écran et pour le PDF du
 * relevé (modèle `cashSession` du moteur de documents, produit sur le poste).
 *
 *  - Tiroir espèces (projection, jamais une colonne) : encaissements espèces CONFIRMÉS de la session, contre-écritures
 *    de ces encaissements, remboursements espèces de la session, mouvements autorisés (entrées positives, sorties
 *    négatives). Attendu = fond + encaissés − contre-écritures − remboursés + mouvements (`CashSessions::theoretical`).
 *  - Autres modes (mobile money, virement, chèque, autre) : encaissements de LA MÊME caissière pendant la session
 *    (aucune session pour ces modes : ils sont rapprochés à part et n'entrent pas dans le tiroir).
 *  - Une session ouverte donne un relevé PROVISOIRE : ni comptage ni écart.
 *
 * Aucun montant n'est inventé : tout vient des faits enregistrés, en entiers FCFA.
 */
final class CashSessionReport
{
    /**
     * @return array{
     *     session: stdClass,
     *     cash_received: int,
     *     cash_reversed: int,
     *     cash_refunded: int,
     *     movements_in: int,
     *     movements_out: int,
     *     movements: list<stdClass>,
     *     payments: list<stdClass>,
     *     other_methods: list<array{method: string, count: int, amount: int}>
     * }
     */
    public static function build(stdClass $session, CarbonImmutable $now): array
    {
        $clinicId = (string) $session->clinic_id;
        $sessionId = (string) $session->id;
        $totals = CashSessions::totals($clinicId, $sessionId);

        $movements = array_values(DB::table('cash_movements')
            ->where('clinic_id', $clinicId)
            ->where('cash_session_id', $sessionId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'kind', 'signed_amount', 'reason', 'occurred_at'])
            ->all());
        $in = 0;
        $out = 0;
        foreach ($movements as $movement) {
            $amount = (int) $movement->signed_amount;
            if ($amount > 0) {
                $in += $amount;
            } else {
                $out -= $amount;
            }
        }

        // Fenêtre de la session : de l'ouverture à la clôture (ou à l'instant de la lecture si elle est ouverte).
        $until = $session->closed_at === null ? $now->format('Y-m-d H:i:s.u') : (string) $session->closed_at;

        $payments = array_values(DB::table('payments')
            ->leftJoin('receipts', static function ($join): void {
                $join->on('receipts.clinic_id', '=', 'payments.clinic_id')->on('receipts.payment_id', '=', 'payments.id');
            })
            ->leftJoin('invoices', static function ($join): void {
                $join->on('invoices.clinic_id', '=', 'payments.clinic_id')->on('invoices.id', '=', 'payments.invoice_id');
            })
            ->where('payments.clinic_id', $clinicId)
            ->where('payments.status', 'CONFIRMED')
            ->where(static function ($inner) use ($sessionId, $session, $until): void {
                // Espèces : celles de la session. Autres modes : celles de la caissière pendant la session.
                $inner->where('payments.cash_session_id', $sessionId)
                    ->orWhere(static function ($other) use ($session, $until): void {
                        $other->where('payments.method', '<>', 'CASH')
                            ->where('payments.received_by', (string) $session->cashier_id)
                            ->where('payments.effective_at', '>=', (string) $session->opened_at)
                            ->where('payments.effective_at', '<=', $until);
                    });
            })
            ->orderBy('payments.effective_at')
            ->orderBy('payments.id')
            ->get([
                'payments.id as payment_id',
                'payments.invoice_id',
                'payments.method',
                'payments.amount',
                'payments.effective_at',
                'receipts.id as receipt_id',
                'receipts.number as receipt_number',
                'invoices.number as invoice_number',
            ])
            ->all());

        $reversed = [];
        if ($payments !== []) {
            foreach (DB::table('payment_reversals')
                ->where('clinic_id', $clinicId)
                ->whereIn('payment_id', array_map(static fn (stdClass $payment): string => (string) $payment->payment_id, $payments))
                ->selectRaw('payment_id, SUM(amount) AS reversed')
                ->groupBy('payment_id')
                ->get() as $row) {
                $reversed[(string) $row->payment_id] = (int) $row->reversed;
            }
        }

        /** @var array<string, array{method: string, count: int, amount: int}> $others */
        $others = [];
        foreach ($payments as $payment) {
            $payment->reversed_amount = $reversed[(string) $payment->payment_id] ?? 0;
            $method = (string) $payment->method;
            if ($method === 'CASH') {
                continue;
            }
            $others[$method] ??= ['method' => $method, 'count' => 0, 'amount' => 0];
            $others[$method]['count']++;
            $others[$method]['amount'] += (int) $payment->amount - (int) $payment->reversed_amount;
        }
        ksort($others);

        return [
            'session' => $session,
            'cash_received' => $totals['received'],
            'cash_reversed' => $totals['reversed'],
            'cash_refunded' => $totals['refunded'],
            'movements_in' => $in,
            'movements_out' => $out,
            'movements' => $movements,
            'payments' => $payments,
            'other_methods' => array_values($others),
        ];
    }
}
