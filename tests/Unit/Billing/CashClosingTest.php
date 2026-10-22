<?php

namespace Tests\Unit\Billing;

use App\Domain\Cash\Rules\CashClosing;
use App\Domain\Shared\RuleViolation;
use PHPUnit\Framework\TestCase;

/**
 * Espèces théoriques et écart de clôture d'une session de caisse (B05, fichier 03) : formule de
 * `vw_cash_session_totals`, entiers exacts. Données fictives.
 */
final class CashClosingTest extends TestCase
{
    public function test_les_especes_theoriques_suivent_la_formule_du_fichier_03(): void
    {
        // ouverture 25 000 + encaissé 80 000 − contre-écriture 5 000 − remboursé 10 000 + mouvements autorisés 2 000.
        $this->assertSame(92000, CashClosing::theoretical(25000, 80000, 5000, 10000, 2000));
        $this->assertSame(25000, CashClosing::theoretical(25000, 0, 0, 0, 0));
    }

    public function test_une_sortie_d_especes_peut_rendre_le_theorique_negatif_sans_etre_masquee(): void
    {
        $this->assertSame(-5000, CashClosing::theoretical(0, 0, 0, 10000, 5000));
    }

    public function test_l_ecart_est_le_compte_moins_l_attendu(): void
    {
        $this->assertSame(0, CashClosing::variance(105000, 105000));
        $this->assertSame(-500, CashClosing::variance(104500, 105000));
        $this->assertSame(1500, CashClosing::variance(106500, 105000));
    }

    public function test_un_compte_negatif_ou_hors_bornes_est_refuse(): void
    {
        $this->expectException(RuleViolation::class);

        CashClosing::variance(-1, 0);
    }

    public function test_un_total_hors_bornes_est_refuse_jamais_tronque(): void
    {
        $this->expectException(RuleViolation::class);

        CashClosing::theoretical(1_000_000_000_000, 1, 0, 0, 0);
    }
}
