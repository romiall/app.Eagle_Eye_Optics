<?php

namespace Tests\Unit\Billing;

use App\Domain\Billing\Rules\CreditNoteRules;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Quantity;
use PHPUnit\Framework\TestCase;

/**
 * Règles pures de l'avoir (B07, AT14 ; fichier 21 : « Cumul valeur/quantité ≤ ligne originale ») et des plafonds de
 * contre-écriture et de remboursement (`LedgerBalance`). Entiers FCFA et décimaux exacts ; données fictives.
 */
final class CreditNoteRulesTest extends TestCase
{
    public function test_le_cumul_d_une_ligne_ne_depasse_ni_son_montant_ni_sa_quantite(): void
    {
        CreditNoteRules::assertLine(60000, Quantity::of('2'), 30000, Quantity::of('1'), 30000, Quantity::of('1'));
        $this->addToAssertionCount(1);

        try {
            CreditNoteRules::assertLine(60000, Quantity::of('2'), 30000, Quantity::of('1'), 30001, null);
            $this->fail('Montant au-delà de la ligne accepté.');
        } catch (RuleViolation $violation) {
            $this->assertSame(['amount_gross', 'credit_note.exceeds_line', 30000], [$violation->field, $violation->rule, $violation->details['creditable']]);
        }
        try {
            CreditNoteRules::assertLine(60000, Quantity::of('2'), 0, Quantity::of('1.5'), 1000, Quantity::of('0.501'));
            $this->fail('Quantité au-delà de la ligne acceptée.');
        } catch (RuleViolation $violation) {
            $this->assertSame(['quantity', '0.500'], [$violation->field, $violation->details['creditable_quantity']]);
        }
    }

    public function test_la_taxe_creditee_est_proportionnelle_et_la_derniere_part_prend_le_reste_exact(): void
    {
        $this->assertNull(CreditNoteRules::includedTax(5000, 11000, null, 0, 0), 'Ligne sans taxe : aucune taxe inventée.');
        $this->assertSame(303, CreditNoteRules::includedTax(3333, 11000, 1000, 0, 0));
        $this->assertSame(697, CreditNoteRules::includedTax(7667, 11000, 1000, 3333, 303));
        $this->assertSame(1000, CreditNoteRules::includedTax(11000, 11000, 1000, 0, 0));
        // Arrondi demi supérieur : 1000 × 5500 / 11000 = 500 exactement ; 1000 × 1 / 11000 → 0.
        $this->assertSame(500, CreditNoteRules::includedTax(5500, 11000, 1000, 0, 0));
        $this->assertSame(0, CreditNoteRules::includedTax(1, 11000, 1000, 0, 0));
        $this->assertSame(0, CreditNoteRules::includedTax(100, 11000, 1000, 10000, 1000), 'Jamais plus que la taxe restante.');
    }

    public function test_le_montant_de_l_avoir_est_la_somme_de_ses_lignes(): void
    {
        CreditNoteRules::assertTotal(15000, [10000, 5000]);
        $this->addToAssertionCount(1);

        $this->expectException(RuleViolation::class);
        CreditNoteRules::assertTotal(15001, [10000, 5000]);
    }

    public function test_plafonds_du_registre_avoir_remboursement_et_contre_ecriture(): void
    {
        // Exemple du fichier 03 : 80 000, acompte 30 000, paiement 20 000, avoir 10 000 puis 70 000 → crédit 50 000.
        $ledger = LedgerBalance::of(80000, [30000, 20000], [], [10000], []);
        $ledger->assertCreditNote(70000);
        $this->assertSame(20000, $ledger->amountDue());

        $credit = LedgerBalance::of(80000, [30000, 20000], [], [10000, 70000], []);
        $this->assertSame(50000, $credit->refundableCredit());
        $credit->assertRefund(50000);
        $this->addToAssertionCount(1);

        try {
            $credit->assertRefund(50001);
            $this->fail('Remboursement au-delà du crédit accepté.');
        } catch (RuleViolation $violation) {
            $this->assertSame(50000, $violation->details['refundable_credit']);
        }
        try {
            LedgerBalance::assertReversal(30000, 20000, 10001);
            $this->fail('Contre-écriture au-delà du paiement acceptée.');
        } catch (RuleViolation $violation) {
            $this->assertSame(10000, $violation->details['reversible']);
        }
        $this->assertSame(0, LedgerBalance::of(80000, [30000, 20000], [], [10000, 70000], [50000])->signedBalance(), 'Après remboursement de 50 000 : solde nul.');
    }
}
