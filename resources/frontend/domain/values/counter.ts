import { RuleViolation } from '../errors'

/**
 * Compteur `BIGINT UNSIGNED` (séquence d'appareil, borne de plage, curseur) : chaîne de chiffres sans
 * zéro non significatif, calculée en BigInt, jamais en nombre JavaScript hors plage sûre (contrat 23).
 */
export const COUNTER_MAX = 18_446_744_073_709_551_615n

export class Counter {
  readonly value: string

  private constructor(value: string) {
    this.value = value
  }

  static parse(text: string | null, field = 'device_sequence'): Counter {
    if (text === null || !/^(0|[1-9][0-9]{0,19})$/.test(text)) {
      throw RuleViolation.invalid(
        field,
        'counter.format',
        'Compteur invalide : chiffres sans zéro initial.',
      )
    }
    if (BigInt(text) > COUNTER_MAX) {
      throw RuleViolation.invalid(
        field,
        'counter.out_of_range',
        'Compteur au-delà de la borne BIGINT UNSIGNED.',
      )
    }
    return new Counter(text)
  }

  next(field = 'device_sequence'): Counter {
    const next = BigInt(this.value) + 1n
    if (next > COUNTER_MAX) {
      throw RuleViolation.invalid(
        field,
        'counter.out_of_range',
        'Compteur au-delà de la borne BIGINT UNSIGNED.',
      )
    }
    return new Counter(next.toString())
  }

  compare(other: Counter): number {
    const left = BigInt(this.value)
    const right = BigInt(other.value)
    return left === right ? 0 : left < right ? -1 : 1
  }
}
