import { CivilDate } from '@/domain/values/civilDate'
import { Diopter, FixedDecimal, Quantity, Rate } from '@/domain/values/decimal'
import { formatMoney } from '@/domain/values/money'

/** Nature d'un champ décimal : mêmes bornes et échelles que les colonnes SQL (F08). */
export type DecimalKind = 'quantity' | 'rate' | 'diopter'

export function parseDecimal(kind: DecimalKind, text: string, field: string): FixedDecimal | null {
  if (kind === 'quantity') return Quantity.parse(text, field)
  if (kind === 'rate') return Rate.parse(text, field)
  return Diopter.parse(text, field)
}

export function decimalFromCanonical(kind: DecimalKind, canonical: string): FixedDecimal {
  return FixedDecimal.fromString(canonical, kind === 'quantity' ? 3 : kind === 'rate' ? 4 : 2)
}

/**
 * Affichage français d'un décimal : virgule décimale ; signe « + » explicite pour une mesure
 * optique positive non nulle (« +1,25 », « -0,75 », « 0,00 »).
 */
export function formatDecimalFr(value: FixedDecimal, explicitSign: boolean): string {
  const text = value.canonical().replace('.', ',')
  return explicitSign && !value.isNegative() && !value.isZero() ? `+${text}` : text
}

/** Montant affiché dans un champ, sans l'unité (le champ la montre à côté) : « 80 000 ». */
export function formatAmountInput(amount: number): string {
  return formatMoney(amount).replace(/\u{A0}FCFA$/u, '')
}

/** Date civile affichée en français : « 15/10/2026 ». */
export function formatDateFr(iso: string): string {
  const date = CivilDate.fromString(iso)
  return `${String(date.day).padStart(2, '0')}/${String(date.month).padStart(2, '0')}/${date.year}`
}
