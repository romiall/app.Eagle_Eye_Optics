import { RuleViolation } from '../errors'

/**
 * Arithmétique entière exacte sur des nombres JavaScript sûrs (|n| ≤ 2^53 − 1), réservée au calcul
 * des dates. C'est la SEULE division du domaine (garde tests/frontend/domain/no-float.test.ts) :
 * montants et quantités se calculent en BigInt.
 */

/** Refuse NaN, l'infini, une valeur décimale ou hors de la plage sûre (1.5 jour n'est pas un délai). */
export function assertSafeInteger(value: number, field: string): number {
  if (!Number.isSafeInteger(value)) {
    throw RuleViolation.invalid(field, 'number.integer_required', 'Un nombre entier est attendu.')
  }
  return value
}

/**
 * Quotient tronqué vers zéro, comme `intdiv` en PHP : `dividende - (dividende % diviseur)` est un
 * multiple exact du diviseur, la division ne perd donc rien.
 */
export function intDiv(dividend: number, divisor: number): number {
  return (dividend - (dividend % divisor)) / divisor
}

/** Reste toujours positif (modulo mathématique). */
export function floorMod(value: number, divisor: number): number {
  return ((value % divisor) + divisor) % divisor
}

/**
 * Quotient arrondi au plus proche, demi supérieur, de deux BigInt positifs (règle de ROUND de MySQL
 * pour une ligne de facture ; jumeau de `quotient(…)` sur `(2n + d) / 2d` côté PHP). Exact : aucun
 * passage par un nombre flottant.
 */
export function divideRoundHalfUp(numerator: bigint, denominator: bigint): bigint {
  if (numerator < 0n || denominator <= 0n) {
    throw new Error(
      'Arrondi défini pour un numérateur positif et un dénominateur strictement positif.',
    )
  }
  return (numerator * 2n + denominator) / (denominator * 2n)
}
