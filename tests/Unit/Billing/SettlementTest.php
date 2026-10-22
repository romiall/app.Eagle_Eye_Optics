<?php

namespace Tests\Unit\Billing;

use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Rules\Settlement;
use PHPUnit\Framework\TestCase;

/**
 * État de règlement DÉRIVÉ du solde projeté (contrat 23) : aucun statut « payé » stocké. Exemples du fichier 03
 * (facture 80 000, acompte 30 000, solde 50 000, avoir 10 000, annulation totale et remboursement). Données fictives.
 */
final class SettlementTest extends TestCase
{
    public function test_un_brouillon_n_a_aucune_creance_ni_etat_de_reglement(): void
    {
        $balance = Settlement::balanceArray('DRAFT', 80000, null);

        $this->assertSame(['total_gross' => 80000, 'paid_net' => 0, 'credited' => 0, 'refunded' => 0, 'balance_due' => 0, 'credit_available' => 0, 'settlement' => 'NOT_ISSUED'], $balance);
        $this->assertSame('NOT_ISSUED', Settlement::of('ABANDONED', LedgerBalance::of(80000, [], [], [], [])));
    }

    public function test_facture_emise_sans_encaissement(): void
    {
        $ledger = LedgerBalance::of(80000, [], [], [], []);

        $this->assertSame('UNPAID', Settlement::of('ISSUED', $ledger));
        $this->assertSame(1, $ledger->ledgerVersion);
        $this->assertSame(80000, Settlement::balanceArray('ISSUED', 80000, $ledger)['balance_due']);
    }

    public function test_acompte_puis_solde_donnent_deux_encaissements_et_une_dette_nulle(): void
    {
        $deposit = LedgerBalance::of(80000, [30000], [], [], []);
        $settled = LedgerBalance::of(80000, [30000, 50000], [], [], []);

        $this->assertSame('PARTIALLY_PAID', Settlement::of('ISSUED', $deposit));
        $this->assertSame(50000, $deposit->amountDue());
        $this->assertSame(2, $deposit->ledgerVersion);
        $this->assertSame('SETTLED', Settlement::of('ISSUED', $settled));
        $this->assertSame(0, $settled->amountDue());
        $this->assertSame(3, $settled->ledgerVersion);
        $this->assertSame(80000, Settlement::balanceArray('ISSUED', 80000, $settled)['paid_net']);
    }

    public function test_un_avoir_ramene_le_solde_a_vingt_mille(): void
    {
        // Paiements 50 000, avoir 10 000 : dette 20 000 (fichier 21).
        $ledger = LedgerBalance::of(80000, [50000], [], [10000], []);

        $this->assertSame(20000, $ledger->amountDue());
        $this->assertSame('PARTIALLY_PAID', Settlement::of('ISSUED', $ledger));
        $this->assertSame(['total_gross' => 80000, 'paid_net' => 50000, 'credited' => 10000, 'refunded' => 0, 'balance_due' => 20000, 'credit_available' => 0, 'settlement' => 'PARTIALLY_PAID'], Settlement::balanceArray('ISSUED', 80000, $ledger));
    }

    public function test_l_annulation_totale_par_avoir_laisse_un_credit_a_rembourser_jamais_une_dette_negative(): void
    {
        $credit = LedgerBalance::of(80000, [50000], [], [80000], []);
        $refunded = LedgerBalance::of(80000, [50000], [], [80000], [50000]);

        $this->assertSame('IN_CREDIT', Settlement::of('ISSUED', $credit));
        $this->assertSame(0, $credit->amountDue());
        $this->assertSame(50000, $credit->refundableCredit());
        $this->assertSame(-50000, Settlement::balanceArray('ISSUED', 80000, $credit)['balance_due'], 'Le signe du solde reste visible.');
        $this->assertSame(50000, Settlement::balanceArray('ISSUED', 80000, $credit)['credit_available']);
        $this->assertSame('SETTLED', Settlement::of('ISSUED', $refunded));
        $this->assertSame(0, $refunded->refundableCredit());
    }

    public function test_une_contre_ecriture_retire_l_encaissement_net_sans_effacer_le_paiement(): void
    {
        $ledger = LedgerBalance::of(80000, [30000], [30000], [], []);

        $this->assertSame(0, Settlement::paidNet($ledger));
        $this->assertSame('UNPAID', Settlement::of('ISSUED', $ledger));
        $this->assertSame(3, $ledger->ledgerVersion, 'Paiement + contre-écriture : deux faits de plus que la version initiale.');
    }

    public function test_la_version_de_publication_ne_recule_jamais(): void
    {
        $versions = [
            InvoiceLedger::publicationVersion(1, null),
            InvoiceLedger::publicationVersion(2, null),
            InvoiceLedger::publicationVersion(2, 1),
            InvoiceLedger::publicationVersion(2, 2),
            InvoiceLedger::publicationVersion(2, 3),
        ];

        $this->assertSame([1, 2, 3, 4, 5], $versions);
    }
}
