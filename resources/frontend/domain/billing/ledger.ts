import { RuleViolation } from '../errors'
import { moneyAmount, positiveMoney, signedMoney } from '../values/money'

/**
 * Solde d'une facture émise, jumeau de `app/Domain/Billing/Rules/LedgerBalance.php` et de la vue
 * `vw_invoice_balances` :
 *   solde_signé = total − avoirs − paiements confirmés + contre-écritures + remboursements
 *   reste dû = max(0, solde) ; crédit à rembourser = max(0, −solde)
 *   ledger_version = 1 + nombre de faits financiers liés (contrat 23)
 */
export interface LedgerFacts {
  totalGross: number
  payments: readonly number[]
  reversals: readonly number[]
  credits: readonly number[]
  refunds: readonly number[]
}

export class LedgerBalance {
  readonly totalGross: number
  readonly paymentsConfirmed: number
  readonly paymentReversals: number
  readonly creditsIssued: number
  readonly refundsConfirmed: number
  readonly ledgerVersion: number

  private constructor(facts: LedgerFacts) {
    this.totalGross = moneyAmount(facts.totalGross, 'total_gross')
    this.paymentsConfirmed = sum(facts.payments, 'payments')
    this.paymentReversals = sum(facts.reversals, 'reversals')
    this.creditsIssued = sum(facts.credits, 'credits')
    this.refundsConfirmed = sum(facts.refunds, 'refunds')
    this.ledgerVersion =
      1 +
      facts.payments.length +
      facts.reversals.length +
      facts.credits.length +
      facts.refunds.length
    signedMoney(this.signedBalance(), 'balance')
  }

  static of(facts: LedgerFacts): LedgerBalance {
    return new LedgerBalance(facts)
  }

  signedBalance(): number {
    return (
      this.totalGross -
      this.creditsIssued -
      this.paymentsConfirmed +
      this.paymentReversals +
      this.refundsConfirmed
    )
  }

  amountDue(): number {
    return Math.max(0, this.signedBalance())
  }

  refundableCredit(): number {
    return Math.max(0, -this.signedBalance())
  }

  /** Part de la facture qui peut encore faire l'objet d'un avoir (avoir plafonné). */
  creditable(): number {
    return Math.max(0, this.totalGross - this.creditsIssued)
  }

  assertPayment(amount: number): void {
    positiveMoney(amount, 'amount')
    if (amount > this.amountDue()) {
      throw new RuleViolation(
        'PAYMENT_EXCEEDS_BALANCE',
        'payment.exceeds_balance',
        'amount',
        'Le montant dépasse le reste à payer de cette facture.',
        { amount_due: this.amountDue() },
      )
    }
  }

  assertCreditNote(amount: number): void {
    positiveMoney(amount, 'amount')
    if (amount > this.creditable()) {
      throw RuleViolation.invalid(
        'amount',
        'credit_note.exceeds_creditable',
        'Un avoir ne peut pas dépasser le montant de la facture non encore crédité.',
        { creditable: this.creditable() },
      )
    }
  }

  assertRefund(amount: number): void {
    positiveMoney(amount, 'amount')
    if (amount > this.refundableCredit()) {
      throw RuleViolation.invalid(
        'amount',
        'refund.exceeds_credit',
        'Un remboursement est limité au crédit à rembourser.',
        { refundable_credit: this.refundableCredit() },
      )
    }
  }

  static assertReversal(paymentAmount: number, alreadyReversed: number, amount: number): void {
    positiveMoney(paymentAmount, 'payment_amount')
    moneyAmount(alreadyReversed, 'already_reversed')
    positiveMoney(amount, 'amount')
    const reversible = Math.max(0, paymentAmount - alreadyReversed)
    if (amount > reversible) {
      throw RuleViolation.invalid(
        'amount',
        'reversal.exceeds_payment',
        'Une contre-écriture ne peut pas dépasser le montant du paiement non encore contre-passé.',
        { reversible },
      )
    }
  }
}

function sum(amounts: readonly number[], field: string): number {
  let total = 0n
  for (const amount of amounts) total += BigInt(positiveMoney(amount, field))
  return signedMoney(total, field)
}
