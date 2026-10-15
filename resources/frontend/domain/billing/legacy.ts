import { RuleViolation } from '../errors'
import { moneyAmount, positiveMoney } from '../values/money'

/**
 * Reprise d'une créance papier (AT26), jumelle de `app/Domain/Billing/Rules/LegacyBalance.php` : la
 * pièce LEGACY_BALANCE porte le seul reste dû ; ni vente ni encaissement de la période de reprise.
 */
export function legacyOpeningBalance(originalTotal: number, alreadyPaid: number): number {
  positiveMoney(originalTotal, 'original_total')
  moneyAmount(alreadyPaid, 'already_paid')
  if (alreadyPaid > originalTotal) {
    throw RuleViolation.invalid(
      'already_paid',
      'legacy.paid_exceeds_total',
      'Le montant déjà payé dépasse le total de la pièce papier.',
    )
  }
  if (alreadyPaid === originalTotal) {
    throw RuleViolation.invalid(
      'already_paid',
      'legacy.nothing_due',
      'Cette pièce papier est soldée : aucune créance à reprendre.',
    )
  }
  return originalTotal - alreadyPaid
}

/** Chiffre d'affaires d'une facture émise : son total si native, zéro pour une reprise. */
export function invoiceRevenue(origin: string, totalGross: number): number {
  if (origin === 'NATIVE') return moneyAmount(totalGross, 'total_gross')
  if (origin === 'LEGACY_BALANCE') return 0
  throw RuleViolation.invalid('origin', 'invoice.origin_unknown', 'Origine de facture inconnue.')
}
