<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Solde d'une facture émise, recalculé à partir de ses faits append-only (fichier 03, vue `vw_invoice_balances`) :
 *
 *   solde_signé        = total - avoirs_émis - paiements_confirmés + contre_écritures + remboursements
 *   reste_à_payer      = max(0, solde_signé)
 *   crédit_à_rembourser = max(0, -solde_signé)
 *   ledger_version     = 1 + nombre de paiements confirmés, contre-écritures, avoirs émis et remboursements
 *
 * Aucun statut « payé » n'est stocké : il se déduit du solde. `ledger_version` est la version attendue des
 * commandes financières (contrat 23), distincte de `invoices.version`, figée à l'émission.
 */
final readonly class LedgerBalance
{
    private function __construct(
        public int $totalGross,
        public int $paymentsConfirmed,
        public int $paymentReversals,
        public int $creditsIssued,
        public int $refundsConfirmed,
        public int $ledgerVersion,
    ) {}

    /**
     * @param  list<int>  $payments  paiements CONFIRMED
     * @param  list<int>  $reversals  contre-écritures
     * @param  list<int>  $credits  avoirs ISSUED
     * @param  list<int>  $refunds  remboursements
     */
    public static function of(int $totalGross, array $payments, array $reversals, array $credits, array $refunds): self
    {
        Money::amount($totalGross, 'total_gross');

        $balance = new self(
            $totalGross,
            self::sum($payments, 'payments'),
            self::sum($reversals, 'reversals'),
            self::sum($credits, 'credits'),
            self::sum($refunds, 'refunds'),
            1 + count($payments) + count($reversals) + count($credits) + count($refunds),
        );
        Money::signed($balance->signedBalance(), 'balance');

        return $balance;
    }

    public function signedBalance(): int
    {
        return $this->totalGross - $this->creditsIssued - $this->paymentsConfirmed + $this->paymentReversals + $this->refundsConfirmed;
    }

    public function amountDue(): int
    {
        return max(0, $this->signedBalance());
    }

    public function refundableCredit(): int
    {
        return max(0, -$this->signedBalance());
    }

    /** Part de la facture qui peut encore faire l'objet d'un avoir (avoir plafonné, AT14). */
    public function creditable(): int
    {
        return max(0, $this->totalGross - $this->creditsIssued);
    }

    /**
     * Encaissement : strictement positif et au plus le reste dû, recalculé sous le verrou de la facture. Le refus
     * indique le reste à proposer (AT12 : deuxième règlement de 40 000 sur 50 000 → proposer 10 000).
     */
    public function assertPayment(int $amount): void
    {
        Money::positive($amount, 'amount');
        if ($amount > $this->amountDue()) {
            throw new RuleViolation(
                RuleViolation::PAYMENT_EXCEEDS_BALANCE,
                'payment.exceeds_balance',
                'amount',
                'Le montant dépasse le reste à payer de cette facture.',
                ['amount_due' => $this->amountDue()],
            );
        }
    }

    public function assertCreditNote(int $amount): void
    {
        Money::positive($amount, 'amount');
        if ($amount > $this->creditable()) {
            throw RuleViolation::invalid('amount', 'credit_note.exceeds_creditable', 'Un avoir ne peut pas dépasser le montant de la facture non encore crédité.', ['creditable' => $this->creditable()]);
        }
    }

    public function assertRefund(int $amount): void
    {
        Money::positive($amount, 'amount');
        if ($amount > $this->refundableCredit()) {
            throw RuleViolation::invalid('amount', 'refund.exceeds_credit', 'Un remboursement est limité au crédit à rembourser.', ['refundable_credit' => $this->refundableCredit()]);
        }
    }

    /**
     * Contre-écriture d'un paiement : liée à ce paiement, au plus son montant non encore contre-passé.
     */
    public static function assertReversal(int $paymentAmount, int $alreadyReversed, int $amount): void
    {
        Money::positive($paymentAmount, 'payment_amount');
        Money::amount($alreadyReversed, 'already_reversed');
        Money::positive($amount, 'amount');
        $reversible = max(0, $paymentAmount - $alreadyReversed);
        if ($amount > $reversible) {
            throw RuleViolation::invalid('amount', 'reversal.exceeds_payment', 'Une contre-écriture ne peut pas dépasser le montant du paiement non encore contre-passé.', ['reversible' => $reversible]);
        }
    }

    /**
     * @param  list<int>  $amounts
     */
    private static function sum(array $amounts, string $field): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += Money::positive($amount, $field);
        }

        return Money::signed($total, $field);
    }
}
