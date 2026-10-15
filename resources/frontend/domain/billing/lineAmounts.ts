import { RuleViolation } from '../errors'
import { type FixedDecimal, Quantity, Rate } from '../values/decimal'
import { divideRoundHalfUp } from '../values/integer'
import { moneyAmount, MONEY_MAX } from '../values/money'

/**
 * Montants d'une ligne de facture, jumeaux de `app/Domain/Billing/Rules/LineAmounts.php` :
 * brut = arrondi_FCFA(quantité × prix TTC), demi supérieur ; net = brut − remise (0 ≤ remise ≤ brut) ;
 * taxe comprise si un taux validé existe, sinon null. Produit exact en BigInt (millièmes de FCFA).
 */
export interface LineAmounts {
  gross: number
  discount: number
  net: number
  taxAmount: number | null
}

const MAX = BigInt(MONEY_MAX)

/** Taxe comprise dans un net TTC : arrondi(net × t / (100 + t)), demi supérieur (convention à valider, Q03). */
export function includedTax(netGross: number, ratePercent: FixedDecimal): number {
  moneyAmount(netGross, 'line_total_gross')
  const rate = Rate.of(ratePercent.canonical())
  const denominator = 1_000_000n + rate.units
  const numerator = BigInt(netGross) * rate.units
  return Number(divideRoundHalfUp(numerator, denominator))
}

export function computeLineAmounts(
  quantity: FixedDecimal,
  unitPrice: number,
  discount: number,
  taxRatePercent: FixedDecimal | null,
  integerOnly: boolean,
): LineAmounts {
  Quantity.forLine(quantity, integerOnly)
  moneyAmount(unitPrice, 'unit_price_gross')
  moneyAmount(discount, 'discount_gross')

  const thousandths = quantity.units * BigInt(unitPrice)
  const gross = divideRoundHalfUp(thousandths, 1000n)
  if (gross > MAX) {
    throw RuleViolation.invalid(
      'unit_price_gross',
      'line.amount_out_of_range',
      'Le montant de la ligne dépasse 1 000 milliards de FCFA.',
    )
  }
  const grossAmount = Number(gross)
  if (discount > grossAmount) {
    throw RuleViolation.invalid(
      'discount_gross',
      'line.discount_exceeds_gross',
      'La remise ne peut pas dépasser le montant brut de la ligne.',
      { gross: grossAmount },
    )
  }
  const net = grossAmount - discount
  return {
    gross: grossAmount,
    discount,
    net,
    taxAmount: taxRatePercent === null ? null : includedTax(net, taxRatePercent),
  }
}

/** Total d'une facture : somme des nets, bornée comme `invoices.total_gross`. */
export function invoiceTotal(lines: readonly LineAmounts[]): number {
  let total = 0n
  for (const line of lines) {
    total += BigInt(line.net)
    if (total > MAX) {
      throw RuleViolation.invalid(
        'lines',
        'invoice.total_out_of_range',
        'Le total de la facture dépasse 1 000 milliards de FCFA.',
      )
    }
  }
  return Number(total)
}
