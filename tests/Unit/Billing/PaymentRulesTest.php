<?php

namespace Tests\Unit\Billing;

use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Rules\PaymentRules;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use PHPUnit\Framework\TestCase;

/**
 * Règles d'un encaissement sans lecture en base (B04, AT10, AT12) : nature calculée du reçu, référence obligatoire,
 * date effective, rétroactivité au jour civil du cabinet, jamais de sur-encaissement. Données fictives.
 */
final class PaymentRulesTest extends TestCase
{
    public function test_la_nature_du_recu_est_calculee_jamais_saisie(): void
    {
        $this->assertSame('DEPOSIT', PaymentRules::receiptKind(50000));
        $this->assertSame('DEPOSIT', PaymentRules::receiptKind(1));
        $this->assertSame('BALANCE_SETTLEMENT', PaymentRules::receiptKind(0));
    }

    public function test_la_reference_est_obligatoire_pour_les_modes_parametres(): void
    {
        $required = PaymentRules::DEFAULT_REFERENCE_REQUIRED;

        $this->assertTrue(PaymentRules::referenceRequired('MTN_MOMO', $required));
        $this->assertTrue(PaymentRules::referenceRequired('BANK_TRANSFER', $required));
        $this->assertFalse(PaymentRules::referenceRequired('CASH', $required));
        $this->assertFalse(PaymentRules::referenceRequired('CHEQUE', $required));
        $this->assertTrue(PaymentRules::referenceRequired('CHEQUE', [...$required, 'CHEQUE']), 'Le cabinet peut l\'exiger aussi pour les chèques.');
    }

    public function test_une_date_effective_dans_le_futur_au_dela_de_la_tolerance_est_refusee(): void
    {
        $now = Instant::parse('2026-10-22T09:30:00.000Z');
        $this->assertNotNull($now);

        PaymentRules::assertNotInFuture(Instant::parse('2026-10-22T09:35:00.000Z') ?? $now, $now, 300);
        PaymentRules::assertNotInFuture(Instant::parse('2026-10-20T08:00:00.000Z') ?? $now, $now, 300);

        $this->expectException(RuleViolation::class);
        PaymentRules::assertNotInFuture(Instant::parse('2026-10-22T09:35:00.001Z') ?? $now, $now, 300);
    }

    public function test_la_retroactivite_se_mesure_au_jour_civil_du_cabinet_et_non_a_minuit_utc(): void
    {
        $now = Instant::parse('2026-10-22T09:30:00.000Z');
        $this->assertNotNull($now);

        // 10 h 30 à Douala (UTC+1) le 22 octobre : le 21 à 23 h UTC est déjà le 22 à 00 h local.
        $this->assertFalse(PaymentRules::isRetroactive(Instant::parse('2026-10-21T23:00:00.000Z') ?? $now, $now, 'Africa/Douala'));
        $this->assertTrue(PaymentRules::isRetroactive(Instant::parse('2026-10-21T22:59:59.999Z') ?? $now, $now, 'Africa/Douala'));
        $this->assertFalse(PaymentRules::isRetroactive($now, $now, 'Africa/Douala'));
        $this->assertTrue(PaymentRules::isRetroactive(Instant::parse('2026-10-01T08:00:00.000Z') ?? $now, $now, 'Africa/Douala'));
    }

    public function test_deux_encaissements_de_40000_sur_50000_le_second_apprend_qu_il_reste_10000(): void
    {
        $before = LedgerBalance::of(50000, [], [], [], []);
        $before->assertPayment(40000);

        $after = LedgerBalance::of(50000, [40000], [], [], []);
        try {
            $after->assertPayment(40000);
            $this->fail('Le second règlement de 40 000 sur un reste de 10 000 devait être refusé.');
        } catch (RuleViolation $violation) {
            $this->assertSame('PAYMENT_EXCEEDS_BALANCE', $violation->errorCode);
            $this->assertSame(['amount_due' => 10000], $violation->details);
        }
    }

    public function test_un_montant_nul_ou_negatif_n_est_jamais_encaisse(): void
    {
        $ledger = LedgerBalance::of(50000, [], [], [], []);

        foreach ([0, -1] as $amount) {
            try {
                $ledger->assertPayment($amount);
                $this->fail('Un montant non strictement positif devait être refusé.');
            } catch (RuleViolation $violation) {
                $this->assertSame('VALIDATION_FAILED', $violation->errorCode);
            }
        }
    }

    public function test_le_solde_exact_est_encaissable_et_pas_un_franc_de_plus(): void
    {
        $ledger = LedgerBalance::of(80000, [30000], [], [], []);
        $ledger->assertPayment(50000);

        $this->expectException(RuleViolation::class);
        $ledger->assertPayment(50001);
    }
}
