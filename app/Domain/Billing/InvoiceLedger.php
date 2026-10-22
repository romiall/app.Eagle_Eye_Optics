<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Rules\LedgerBalance;
use Illuminate\Support\Facades\DB;

/**
 * Registre financier d'une facture émise, relu dans ses FAITS append-only (paiements confirmés, contre-écritures,
 * avoirs émis, remboursements) : le solde et la `ledger_version` sont des PROJECTIONS recalculées, jamais une
 * colonne (contrat 23 : « ne jamais incrémenter invoices.version lors d'un encaissement »). Appelé sous le verrou de
 * la facture par toute commande financière, qui compare alors la version attendue et le reste dû.
 *
 * Toujours limité au cabinet. Les règles de calcul sont `LedgerBalance` (vecteurs communs avec le client).
 */
final class InvoiceLedger
{
    public static function load(string $clinicId, string $invoiceId, int $totalGross): LedgerBalance
    {
        $payments = DB::table('payments')
            ->where('clinic_id', $clinicId)
            ->where('invoice_id', $invoiceId)
            ->where('status', 'CONFIRMED')
            ->orderBy('id')
            ->pluck('amount')
            ->all();

        $reversals = DB::table('payment_reversals')
            ->join('payments', static function ($join): void {
                $join->on('payments.clinic_id', '=', 'payment_reversals.clinic_id')->on('payments.id', '=', 'payment_reversals.payment_id');
            })
            ->where('payments.clinic_id', $clinicId)
            ->where('payments.invoice_id', $invoiceId)
            ->orderBy('payment_reversals.id')
            ->pluck('payment_reversals.amount')
            ->all();

        $credits = DB::table('credit_notes')
            ->where('clinic_id', $clinicId)
            ->where('invoice_id', $invoiceId)
            ->where('status', 'ISSUED')
            ->orderBy('id')
            ->pluck('amount_gross')
            ->all();

        $refunds = DB::table('refunds')
            ->where('clinic_id', $clinicId)
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->pluck('amount')
            ->all();

        return LedgerBalance::of(
            $totalGross,
            self::amounts($payments),
            self::amounts($reversals),
            self::amounts($credits),
            self::amounts($refunds),
        );
    }

    /**
     * Version de publication d'une facture dans le journal des changements (`sync_change_log.entity_version`) :
     * version du brouillon + `ledger_version`. Elle croît à chaque fait (brouillon modifié, émission, paiement,
     * contre-écriture, avoir, remboursement) sans jamais reculer, alors que `invoices.version` est figée à
     * l'émission et que `ledger_version` repart de 1 : aucune des deux ne suffit seule à ordonner la diffusion.
     */
    public static function publicationVersion(int $draftVersion, ?int $ledgerVersion): int
    {
        return $draftVersion + ($ledgerVersion ?? 0);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private static function amounts(array $values): array
    {
        return array_map(static fn (mixed $value): int => (int) $value, array_values($values));
    }
}
