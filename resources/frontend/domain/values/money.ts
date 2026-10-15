import { RuleViolation } from '../errors'
import { trimBlanks } from './text'

/**
 * Montants XAF : entiers FCFA, jamais de flottant (fichier 03), jumeau de
 * `app/Domain/Shared/ValueObjects/Money.php`. Bornes BIGINT du schéma : 0..10^12, signé ±10^12.
 * Les montants transportés en JSON restent des nombres entiers sûrs ; les sommes et produits se
 * calculent en BigInt.
 */
export const MONEY_MAX = 1_000_000_000_000
const MONEY_MAX_BIG = 1_000_000_000_000n

/** Espace fine insécable entre groupes de chiffres, espace insécable avant l'unité. */
const GROUP_SEPARATOR = '\u{202F}'
const UNIT_SEPARATOR = '\u{A0}'

function outOfRange(field: string): RuleViolation {
  return RuleViolation.invalid(
    field,
    'money.out_of_range',
    'Montant hors des bornes admises (0 à 1 000 milliards de FCFA).',
  )
}

function assertInteger(value: number, field: string): void {
  if (!Number.isSafeInteger(value)) throw outOfRange(field)
}

export function moneyAmount(value: number, field = 'amount'): number {
  assertInteger(value, field)
  if (value < 0 || value > MONEY_MAX) throw outOfRange(field)
  return value
}

export function positiveMoney(value: number, field = 'amount'): number {
  assertInteger(value, field)
  if (value <= 0) {
    throw RuleViolation.invalid(
      field,
      'money.positive_required',
      'Le montant doit être strictement positif.',
    )
  }
  return moneyAmount(value, field)
}

export function signedMoney(value: number | bigint, field = 'amount'): number {
  if (typeof value === 'number') assertInteger(value, field)
  const big = typeof value === 'bigint' ? value : BigInt(value)
  if (big < -MONEY_MAX_BIG || big > MONEY_MAX_BIG) throw outOfRange(field)
  return Number(big)
}

/**
 * Saisie en FCFA : chiffres seuls (« 80000 ») ou groupés par trois avec une espace, une espace
 * insécable ou une espace fine (« 80 000 »). Ni virgule, ni point, ni signe. Vide → null, jamais 0.
 */
export function parseMoney(text: string | null, field = 'amount'): number | null {
  const value = trimBlanks(text ?? '')
  if (value === '') return null
  if (!/^(?:[0-9]+|[0-9]{1,3}(?:[ \u{A0}\u{202F}][0-9]{3})+)$/u.test(value)) {
    throw RuleViolation.invalid(
      field,
      'money.format',
      'Montant invalide : un nombre entier de FCFA, par exemple 80 000.',
    )
  }
  const digits = value.replace(/[^0-9]/g, '').replace(/^0+/, '')
  if (digits.length > 13) throw outOfRange(field)
  return moneyAmount(Number(digits === '' ? '0' : digits), field)
}

/** Affichage français : « 80 000 FCFA », « -50 000 FCFA » (espaces fines insécables). */
export function formatMoney(amount: number): string {
  assertInteger(amount, 'amount')
  const digits = String(Math.abs(amount))
  const groups: string[] = []
  for (let end = digits.length; end > 0; end -= 3) {
    groups.unshift(digits.slice(Math.max(0, end - 3), end))
  }
  return (amount < 0 ? '-' : '') + groups.join(GROUP_SEPARATOR) + UNIT_SEPARATOR + 'FCFA'
}
