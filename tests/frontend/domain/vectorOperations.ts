// Adaptateur des vecteurs contracts/vectors/ vers les règles TypeScript : entrées JSON → appel de la
// règle → sortie JSON, sans aucun calcul propre. Jumeau de tests/Support/Rules/RuleVectorOperations.php :
// les deux exposent exactement les mêmes opérations.
import {
  includedTax,
  computeLineAmounts,
  invoiceTotal,
  type LineAmounts,
} from '@/domain/billing/lineAmounts'
import { LedgerBalance } from '@/domain/billing/ledger'
import { invoiceRevenue, legacyOpeningBalance } from '@/domain/billing/legacy'
import { assertLotDeliverable, fefoOrder } from '@/domain/inventory/lots'
import {
  applySyncedQuotaDelivery,
  assertCanTake,
  centralAvailable,
  localAvailable,
  remainingQuota,
} from '@/domain/inventory/stock'
import { recommendedDate, reminderDate } from '@/domain/scheduling/followUp'
import { CivilDate } from '@/domain/values/civilDate'
import { Counter } from '@/domain/values/counter'
import { Diopter, type FixedDecimal, Quantity, Rate } from '@/domain/values/decimal'
import { Instant } from '@/domain/values/instant'
import { formatMoney, moneyAmount, parseMoney, positiveMoney } from '@/domain/values/money'
import { normalizePhone, optionalText, searchKey, trimBlanks } from '@/domain/values/text'

type Input = Readonly<Record<string, unknown>>

function string(input: Input, key: string): string {
  const value = input[key]
  if (typeof value !== 'string') throw new Error(`Entrée « ${key} » : chaîne attendue.`)
  return value
}

function nullableString(input: Input, key: string): string | null {
  if (!(key in input)) throw new Error(`Entrée « ${key} » absente.`)
  return input[key] === null ? null : string(input, key)
}

function int(input: Input, key: string): number {
  const value = input[key]
  if (typeof value !== 'number' || !Number.isInteger(value)) {
    throw new Error(`Entrée « ${key} » : entier attendu.`)
  }
  return value
}

function bool(input: Input, key: string): boolean {
  const value = input[key]
  if (typeof value !== 'boolean') throw new Error(`Entrée « ${key} » : booléen attendu.`)
  return value
}

function list(input: Input, key: string): unknown[] {
  const value = input[key] ?? []
  if (!Array.isArray(value)) throw new Error(`Entrée « ${key} » : liste attendue.`)
  return value
}

function intList(input: Input, key: string): number[] {
  return list(input, key).map((value) => {
    if (typeof value !== 'number' || !Number.isInteger(value)) {
      throw new Error(`Entrée « ${key} » : liste d’entiers attendue.`)
    }
    return value
  })
}

function object(value: unknown): Input {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new Error('Objet attendu.')
  }
  return value as Input
}

const date = (input: Input, key: string): CivilDate => CivilDate.fromString(string(input, key))
const quantity = (input: Input, key: string): FixedDecimal => Quantity.of(string(input, key))

function instant(text: string): Instant {
  const parsed = Instant.parse(text)
  if (parsed === null) throw new Error('Instant vide.')
  return parsed
}

function parseDecimal(input: Input): FixedDecimal | null {
  const text = nullableString(input, 'text')
  const field = string(input, 'field')
  switch (string(input, 'kind')) {
    case 'quantity':
      return Quantity.parse(text, field)
    case 'rate':
      return Rate.parse(text, field)
    case 'diopter':
      return Diopter.parse(text, field)
    default:
      throw new Error('Nature décimale inconnue.')
  }
}

function addToDate(start: CivilDate, amount: number, unit: string): CivilDate {
  if (unit === 'DAY') return start.addDays(amount)
  if (unit === 'MONTH') return start.addMonths(amount)
  if (unit === 'YEAR') return start.addYears(amount)
  throw new Error('Unité de date inconnue.')
}

function line(input: Input): LineAmounts {
  const rate = input['tax_rate_percent']
  return computeLineAmounts(
    Quantity.of(string(input, 'quantity')),
    int(input, 'unit_price_gross'),
    int(input, 'discount_gross'),
    typeof rate === 'string' ? Rate.of(rate) : null,
    bool(input, 'integer_only'),
  )
}

function lineOutput(amounts: LineAmounts): Record<string, number | null> {
  return {
    gross: amounts.gross,
    discount: amounts.discount,
    net: amounts.net,
    tax_amount: amounts.taxAmount,
  }
}

function ledger(input: Input, extra: Readonly<Record<string, number[]>> = {}): LedgerBalance {
  const facts = (key: string): number[] => [...intList(input, key), ...(extra[key] ?? [])]
  return LedgerBalance.of({
    totalGross: int(input, 'total_gross'),
    payments: facts('payments'),
    reversals: facts('reversals'),
    credits: facts('credits'),
    refunds: facts('refunds'),
  })
}

function balanceOutput(balance: LedgerBalance): Record<string, number> {
  return {
    signed_balance: balance.signedBalance(),
    amount_due: balance.amountDue(),
    refundable_credit: balance.refundableCredit(),
    creditable: balance.creditable(),
    ledger_version: balance.ledgerVersion,
  }
}

/** La règle accepte ou refuse sur le registre ACTUEL, puis le registre est recalculé avec le fait. */
function ledgerOperation(operation: string, input: Input): Record<string, number> {
  const facts = object(input['ledger'])
  const balance = ledger(facts)
  const amount = int(input, 'amount')
  let key: string
  if (operation === 'ledger.payment') {
    balance.assertPayment(amount)
    key = 'payments'
  } else if (operation === 'ledger.creditNote') {
    balance.assertCreditNote(amount)
    key = 'credits'
  } else if (operation === 'ledger.refund') {
    balance.assertRefund(amount)
    key = 'refunds'
  } else {
    LedgerBalance.assertReversal(
      int(input, 'payment_amount'),
      int(input, 'already_reversed'),
      amount,
    )
    key = 'reversals'
  }
  return balanceOutput(ledger(facts, { [key]: [amount] }))
}

function syncQuotaDelivery(input: Input): Record<string, string> {
  const allocated = quantity(input, 'allocated')
  const returned = quantity(input, 'returned')
  const applied = applySyncedQuotaDelivery(
    quantity(input, 'physical'),
    allocated,
    quantity(input, 'consumed'),
    returned,
    quantity(input, 'quantity'),
  )
  const remaining = remainingQuota(allocated, applied.consumed, returned)
  return {
    physical: applied.physical.canonical(),
    consumed: applied.consumed.canonical(),
    remaining_quota: remaining.canonical(),
    central_available: centralAvailable(
      applied.physical,
      quantity(input, 'central_reserved'),
      remaining,
    ).canonical(),
  }
}

export function runVectorOperation(operation: string, input: Input): unknown {
  switch (operation) {
    case 'text.trim':
      return trimBlanks(string(input, 'value'))
    case 'text.optional':
      return optionalText(
        nullableString(input, 'value'),
        int(input, 'max_length'),
        string(input, 'field'),
      )
    case 'text.searchKey':
      return searchKey(string(input, 'value'))
    case 'text.phone':
      return normalizePhone(nullableString(input, 'value'), string(input, 'field'))
    case 'money.parse':
      return parseMoney(nullableString(input, 'text'), string(input, 'field'))
    case 'money.format':
      return formatMoney(int(input, 'amount'))
    case 'money.positive':
      return positiveMoney(int(input, 'value'), string(input, 'field'))
    case 'money.amount':
      return moneyAmount(int(input, 'value'), string(input, 'field'))
    case 'decimal.parse':
      return parseDecimal(input)?.canonical() ?? null
    case 'quantity.forLine':
      return Quantity.forLine(
        Quantity.of(string(input, 'quantity')),
        bool(input, 'integer_only'),
      ).canonical()
    case 'date.parse':
      return CivilDate.parse(nullableString(input, 'text'))?.toString() ?? null
    case 'date.add':
      return addToDate(date(input, 'date'), int(input, 'amount'), string(input, 'unit')).toString()
    case 'date.fromInstant':
      return instant(string(input, 'instant')).civilDate(string(input, 'timezone')).toString()
    case 'date.age':
      return date(input, 'birth_date').ageOn(date(input, 'reference_date'))
    case 'instant.parse': {
      const parsed = instant(string(input, 'text'))
      return { canonical: parsed.toString(), epoch_ms: parsed.epochMilliseconds }
    }
    case 'counter.parse':
      return Counter.parse(nullableString(input, 'value')).value
    case 'counter.next':
      return Counter.parse(string(input, 'value')).next().value
    case 'counter.compare':
      return Counter.parse(string(input, 'left')).compare(Counter.parse(string(input, 'right')))
    case 'invoice.line':
      return lineOutput(line(input))
    case 'invoice.total': {
      const lines = list(input, 'lines').map((item) => line(object(item)))
      return { lines: lines.map(lineOutput), total: invoiceTotal(lines) }
    }
    case 'invoice.includedTax':
      return includedTax(int(input, 'net'), Rate.of(string(input, 'rate_percent')))
    case 'ledger.balance':
      return balanceOutput(ledger(input))
    case 'ledger.payment':
    case 'ledger.creditNote':
    case 'ledger.refund':
    case 'ledger.reversal':
      return ledgerOperation(operation, input)
    case 'legacy.openingBalance':
      return legacyOpeningBalance(int(input, 'original_total'), int(input, 'already_paid'))
    case 'legacy.revenue':
      return invoiceRevenue(string(input, 'origin'), int(input, 'total_gross'))
    case 'stock.central':
      return centralAvailable(
        quantity(input, 'physical'),
        quantity(input, 'central_reserved'),
        quantity(input, 'offline_allocated'),
      ).canonical()
    case 'stock.local':
      return localAvailable(
        quantity(input, 'allocated'),
        quantity(input, 'consumed'),
        quantity(input, 'returned'),
        quantity(input, 'local_reserved'),
      ).canonical()
    case 'stock.take':
      assertCanTake(quantity(input, 'requested'), quantity(input, 'available'))
      return true
    case 'stock.syncQuotaDelivery':
      return syncQuotaDelivery(input)
    case 'lot.deliverable': {
      const expires = nullableString(input, 'expires_on')
      assertLotDeliverable(
        string(input, 'status'),
        expires === null ? null : CivilDate.fromString(expires),
        date(input, 'local_date'),
      )
      return true
    }
    case 'lot.fefo':
      return fefoOrder(
        list(input, 'lots').map((item) => {
          const lot = object(item)
          const expires = nullableString(lot, 'expires_on')
          return {
            id: string(lot, 'id'),
            expiresOn: expires === null ? null : CivilDate.fromString(expires),
          }
        }),
      )
    case 'followUp.recommended':
      return recommendedDate(
        date(input, 'anchor'),
        int(input, 'value'),
        string(input, 'unit'),
      ).toString()
    case 'followUp.reminder':
      return reminderDate(
        date(input, 'recommended'),
        int(input, 'advance'),
        string(input, 'unit'),
      ).toString()
    default:
      throw new Error(`Opération de vecteur inconnue : ${operation}`)
  }
}
