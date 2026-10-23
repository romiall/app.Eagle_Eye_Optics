<?php

namespace Tests\Unit\Billing;

use App\Domain\Billing\Numbering\BillingNumbers;
use App\Domain\Shared\DocumentNumbers;
use LogicException;
use Tests\TestCase;

/**
 * Format des numéros de pièces financières (fichier 03, format PROPOSÉ `<préfixe>-AAAA-NNNNNN`) : préfixes configurables,
 * TEST tant que les séries réelles ne sont pas choisies (Q03, Q05), jamais tronqués, jamais inventés pour une série
 * inconnue. Fonctions pures, sans base.
 */
final class BillingNumbersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eeo.numbering.width' => 6,
            'eeo.numbering.prefixes' => ['INVOICE' => 'TEST-FAC', 'RECEIPT' => 'TEST-REC', 'CREDIT_NOTE' => 'TEST-AV', 'REFUND' => 'TEST-RMB'],
        ]);
    }

    public function test_le_format_propose_porte_le_prefixe_l_annee_et_six_chiffres(): void
    {
        $this->assertSame('TEST-FAC-2026-000001', BillingNumbers::render(BillingNumbers::INVOICE, 2026, 1));
        $this->assertSame('TEST-REC-2026-000042', BillingNumbers::render(BillingNumbers::RECEIPT, 2026, 42));
        $this->assertSame('TEST-AV-2027-000007', BillingNumbers::render(BillingNumbers::CREDIT_NOTE, 2027, 7));
        $this->assertSame('TEST-RMB-2026-000300', BillingNumbers::render(BillingNumbers::REFUND, 2026, 300));
    }

    public function test_un_numero_n_est_jamais_tronque(): void
    {
        $this->assertSame('TEST-FAC-2026-999999', BillingNumbers::render(BillingNumbers::INVOICE, 2026, 999999));
        $this->assertSame('TEST-FAC-2026-1000000', BillingNumbers::render(BillingNumbers::INVOICE, 2026, 1000000));
    }

    public function test_les_prefixes_reels_se_configurent_sans_changer_le_format(): void
    {
        config(['eeo.numbering.prefixes.INVOICE' => 'FAC', 'eeo.numbering.width' => 4]);

        $this->assertSame('FAC-2026-0001', BillingNumbers::render(BillingNumbers::INVOICE, 2026, 1));
    }

    public function test_une_serie_inconnue_ou_sans_prefixe_est_refusee(): void
    {
        // LEGACY_BALANCE (reprise des créances papier, X01) est une série financière : sans préfixe, elle est refusée.
        foreach (['PATIENT', 'QUOTE', 'WORKSHOP', 'LEGACY_BALANCE'] as $series) {
            try {
                BillingNumbers::render($series, 2026, 1);
                $this->fail('La série '.$series.' n\'a pas de format de pièce financière.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        config(['eeo.numbering.prefixes.RECEIPT' => '  ']);
        $this->expectException(LogicException::class);
        BillingNumbers::render(BillingNumbers::RECEIPT, 2026, 1);
    }

    public function test_les_devis_ont_leur_propre_serie_distincte_des_factures(): void
    {
        config(['eeo.numbering.prefixes.QUOTE' => 'TEST-DEV']);

        $this->assertSame('TEST-DEV-2026-000001', BillingNumbers::render(BillingNumbers::QUOTE, 2026, 1));
        $this->assertNotSame(BillingNumbers::render(BillingNumbers::INVOICE, 2026, 1), BillingNumbers::render(BillingNumbers::QUOTE, 2026, 1));
    }

    public function test_la_reprise_des_creances_papier_a_sa_propre_serie(): void
    {
        config(['eeo.numbering.prefixes.LEGACY_BALANCE' => 'TEST-REP']);

        $this->assertSame('TEST-REP-2026-000001', BillingNumbers::render(BillingNumbers::LEGACY_BALANCE, 2026, 1));
    }

    public function test_un_prefixe_trop_long_est_refuse_plutot_que_de_depasser_la_colonne(): void
    {
        config(['eeo.numbering.prefixes.INVOICE' => str_repeat('X', 60)]);

        $this->expectException(LogicException::class);
        BillingNumbers::render(BillingNumbers::INVOICE, 2026, 1);
    }

    public function test_aucun_format_n_est_invente_par_le_registre_commun_pour_une_serie_non_validee(): void
    {
        $this->expectException(LogicException::class);

        DocumentNumbers::render('INVOICE', 2026, 1);
    }
}
