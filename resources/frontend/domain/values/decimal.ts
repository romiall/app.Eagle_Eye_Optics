import { RuleViolation } from '../errors'
import { trimBlanks } from './text'

/**
 * Décimal exact à échelle fixe (`DECIMAL(p,s)`), porté par un BigInt d'unités : 1.250 en échelle 3
 * vaut 1250n. Jumeau de `app/Domain/Shared/ValueObjects/FixedDecimal.php`. Forme canonique : point,
 * exactement `scale` décimales, « - » seulement pour une valeur négative non nulle (« 1.500 »,
 * « -0.75 », « 0.00 »), comme MySQL la rend.
 */
export class FixedDecimal {
  readonly units: bigint
  readonly scale: number

  private constructor(units: bigint, scale: number) {
    this.units = units
    this.scale = scale
  }

  static fromUnits(units: bigint, scale: number): FixedDecimal {
    if (!Number.isInteger(scale) || scale < 0 || scale > 6) {
      throw new Error('Échelle décimale hors de 0..6.')
    }
    return new FixedDecimal(units, scale)
  }

  /**
   * Saisie : virgule ou point décimal, signe « + », « - » ou « − » facultatif. Trop de décimales ou
   * de chiffres entiers : refus, jamais de troncature. Vide → null, jamais zéro.
   */
  static parse(
    text: string | null,
    scale: number,
    integerDigits: number,
    signed: boolean,
    field: string,
  ): FixedDecimal | null {
    if (integerDigits + scale > 18) throw new Error('Précision décimale supérieure à 18 chiffres.')
    const value = trimBlanks(text ?? '')
    if (value === '') return null
    const match = /^([+\-\u{2212}]?)([0-9]+)(?:[.,]([0-9]+))?$/u.exec(value)
    if (match === null) {
      throw RuleViolation.invalid(
        field,
        'decimal.format',
        'Nombre invalide : chiffres, avec une virgule ou un point décimal.',
      )
    }
    const sign = match[1] ?? ''
    const negative = sign === '-' || sign === '\u{2212}'
    const integer = (match[2] ?? '').replace(/^0+/, '')
    const fraction = match[3] ?? ''
    if (fraction.length > scale) {
      throw RuleViolation.invalid(
        field,
        'decimal.scale',
        `Au plus ${scale} décimale(s) : la valeur n'est ni arrondie ni tronquée.`,
        { scale },
      )
    }
    if (integer.length > integerDigits) {
      throw RuleViolation.invalid(
        field,
        'decimal.out_of_range',
        'Valeur hors des bornes admises.',
        {
          integer_digits: integerDigits,
        },
      )
    }
    const units =
      BigInt(integer === '' ? '0' : integer) * 10n ** BigInt(scale) +
      BigInt(fraction.padEnd(scale, '0') === '' ? '0' : fraction.padEnd(scale, '0'))
    if (negative && units !== 0n && !signed) {
      throw RuleViolation.invalid(
        field,
        'decimal.negative',
        'Une valeur négative n’est pas admise ici.',
      )
    }
    return new FixedDecimal(negative ? -units : units, scale)
  }

  /** Valeur de confiance déjà canonique ou rendue par MySQL (« 1.500 », « 2 »). */
  static fromString(text: string, scale: number): FixedDecimal {
    const match = /^(-?)([0-9]+)(?:\.([0-9]+))?$/.exec(text)
    const fraction = match?.[3] ?? ''
    if (match === null || fraction.length > scale)
      throw new Error(`Décimal non canonique : ${text}`)
    if ((match[2] ?? '').replace(/^0+/, '').length + scale > 18) {
      throw new Error(`Décimal hors de la précision admise : ${text}`)
    }
    const padded = fraction.padEnd(scale, '0')
    const units =
      BigInt(match[2] ?? '0') * 10n ** BigInt(scale) + BigInt(padded === '' ? '0' : padded)
    return new FixedDecimal(match[1] === '-' ? -units : units, scale)
  }

  canonical(): string {
    const negative = this.units < 0n
    const absolute = (negative ? -this.units : this.units).toString()
    if (this.scale === 0) return (negative ? '-' : '') + absolute
    const padded = absolute.padStart(this.scale + 1, '0')
    return (negative ? '-' : '') + padded.slice(0, -this.scale) + '.' + padded.slice(-this.scale)
  }

  isZero(): boolean {
    return this.units === 0n
  }

  isNegative(): boolean {
    return this.units < 0n
  }

  isInteger(): boolean {
    return this.units % 10n ** BigInt(this.scale) === 0n
  }

  compare(other: FixedDecimal): number {
    this.assertSameScale(other)
    return this.units === other.units ? 0 : this.units < other.units ? -1 : 1
  }

  plus(other: FixedDecimal): FixedDecimal {
    this.assertSameScale(other)
    return new FixedDecimal(this.units + other.units, this.scale)
  }

  minus(other: FixedDecimal): FixedDecimal {
    this.assertSameScale(other)
    return new FixedDecimal(this.units - other.units, this.scale)
  }

  private assertSameScale(other: FixedDecimal): void {
    if (other.scale !== this.scale) throw new Error('Décimaux d’échelles différentes.')
  }
}

/** Quantité `DECIMAL(12,3)` : 9 chiffres entiers, jamais négative ici. */
export const Quantity = {
  SCALE: 3,
  INTEGER_DIGITS: 9,
  MAX_UNITS: 999_999_999_999n,

  parse(text: string | null, field = 'quantity'): FixedDecimal | null {
    return FixedDecimal.parse(text, 3, 9, false, field)
  },

  /** Valeur de confiance bornée comme la colonne : au plus 999 999 999,999 en valeur absolue. */
  of(canonical: string, field = 'quantity'): FixedDecimal {
    const quantity = FixedDecimal.fromString(canonical, 3)
    const absolute = quantity.units < 0n ? -quantity.units : quantity.units
    if (absolute > 999_999_999_999n) {
      throw RuleViolation.invalid(
        field,
        'decimal.out_of_range',
        'Valeur hors des bornes admises.',
        {
          integer_digits: 9,
        },
      )
    }
    return quantity
  },

  /** Quantité de ligne : strictement positive, entière si l'article l'exige. */
  forLine(quantity: FixedDecimal, integerOnly: boolean, field = 'quantity'): FixedDecimal {
    if (quantity.units <= 0n) {
      throw RuleViolation.invalid(
        field,
        'quantity.positive_required',
        'La quantité doit être strictement positive.',
      )
    }
    if (quantity.units > 999_999_999_999n) {
      throw RuleViolation.invalid(
        field,
        'decimal.out_of_range',
        'Valeur hors des bornes admises.',
        {
          integer_digits: 9,
        },
      )
    }
    if (integerOnly && !quantity.isInteger()) {
      throw RuleViolation.invalid(
        field,
        'quantity.integer_required',
        'Cet article se vend à l’unité : quantité entière obligatoire.',
      )
    }
    return quantity
  },

  bounded(units: bigint, field: string): FixedDecimal {
    if (units < 0n) {
      throw RuleViolation.invalid(
        field,
        'quantity.negative_result',
        'Le calcul donnerait une quantité négative.',
      )
    }
    if (units > 999_999_999_999n) {
      throw RuleViolation.invalid(
        field,
        'decimal.out_of_range',
        'Valeur hors des bornes admises.',
        {
          integer_digits: 9,
        },
      )
    }
    return FixedDecimal.fromUnits(units, 3)
  },
} as const

/** Taux `DECIMAL(7,4)` de 0 à 100 ; aucun taux fiscal n'est fourni par le code. */
export const Rate = {
  SCALE: 4,

  parse(text: string | null, field = 'rate_percent'): FixedDecimal | null {
    const rate = FixedDecimal.parse(text, 4, 3, false, field)
    return rate === null ? null : Rate.assertBounds(rate, field)
  },

  of(canonical: string, field = 'rate_percent'): FixedDecimal {
    return Rate.assertBounds(FixedDecimal.fromString(canonical, 4), field)
  },

  assertBounds(rate: FixedDecimal, field: string): FixedDecimal {
    if (rate.units < 0n || rate.units > 1_000_000n) {
      throw RuleViolation.invalid(
        field,
        'rate.out_of_range',
        'Un taux est compris entre 0 et 100 %.',
      )
    }
    return rate
  },
} as const

/** Mesure optique `DECIMAL(6,2)`, signée ; null = non mesuré, jamais remplacé par « 0.00 ». */
export const Diopter = {
  parse(text: string | null, field: string): FixedDecimal | null {
    return FixedDecimal.parse(text, 2, 4, true, field)
  },
} as const
