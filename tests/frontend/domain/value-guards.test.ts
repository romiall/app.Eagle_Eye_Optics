// @vitest-environment node
// Gardes TypeScript que les vecteurs communs ne peuvent pas exprimer (constats de revue F08) : un
// nombre JavaScript peut valoir NaN, l'infini ou 1,5, ce qu'un entier PHP ne peut pas être. Ces
// valeurs sont refusées, jamais transformées en date ; bornes des quantités ; ordre FEFO identique
// à celui de PHP pour des identifiants hors du plan de base.
import { describe, expect, it } from 'vitest'

import { RuleViolation } from '@/domain/errors'
import { fefoOrder } from '@/domain/inventory/lots'
import { recommendedDate, reminderDate } from '@/domain/scheduling/followUp'
import { CivilDate } from '@/domain/values/civilDate'
import { FixedDecimal, Quantity } from '@/domain/values/decimal'
import { Instant } from '@/domain/values/instant'

function rule(action: () => unknown): string {
  try {
    action()
  } catch (error) {
    if (error instanceof RuleViolation) return `${error.field}:${error.rule}`
    throw error
  }
  throw new Error('Refus attendu.')
}

const anchor = CivilDate.fromString('2026-10-15')

describe('dates : un délai non entier est refusé', () => {
  it.each([Number.NaN, 1.5, Number.POSITIVE_INFINITY, 2 ** 53])('délai de contrôle %s', (value) => {
    expect(rule(() => recommendedDate(anchor, value, 'DAY'))).toBe(
      'interval_value:number.integer_required',
    )
    expect(rule(() => recommendedDate(anchor, value, 'MONTH'))).toBe(
      'interval_value:number.integer_required',
    )
  })

  it.each([Number.NaN, 0.5])('avance de rappel %s', (advance) => {
    expect(rule(() => reminderDate(anchor, advance, 'MONTH'))).toBe(
      'advance_value:number.integer_required',
    )
  })

  it('ajout de jours, de mois ou d’années non entiers', () => {
    expect(rule(() => anchor.addDays(1.5))).toBe('date:number.integer_required')
    expect(rule(() => anchor.addMonths(Number.NaN))).toBe('date:number.integer_required')
    expect(rule(() => anchor.addYears(0.5))).toBe('date:number.integer_required')
  })

  it('composantes non entières d’une date', () => {
    expect(rule(() => CivilDate.of(2026, 10.5, 15))).toBe('date:date.invalid')
    expect(rule(() => CivilDate.of(Number.NaN, 1, 1))).toBe('date:date.invalid')
  })
})

describe('bornes des valeurs de confiance', () => {
  it('quantités de DECIMAL(12,3)', () => {
    expect(Quantity.of('-999999999.999').canonical()).toBe('-999999999.999')
    expect(rule(() => Quantity.of('1000000000.000'))).toBe('quantity:decimal.out_of_range')
    expect(Quantity.bounded(999_999_999_999n, 'consumed_quantity').canonical()).toBe(
      '999999999.999',
    )
    expect(rule(() => Quantity.bounded(1_000_000_000_000n, 'consumed_quantity'))).toBe(
      'consumed_quantity:decimal.out_of_range',
    )
  })

  it('précision au-delà de 18 chiffres et saut de ligne final refusés', () => {
    expect(() => FixedDecimal.fromString('99999999999999999', 3)).toThrow()
    expect(() => Quantity.of('1.500\n')).toThrow()
    expect(rule(() => CivilDate.fromString('2026-10-15\n'))).toBe('date:date.format')
  })

  it('aucun instant avant 1970', () => {
    expect(Instant.fromEpochMilliseconds(0).toString()).toBe('1970-01-01T00:00:00.000Z')
    expect(() => Instant.fromEpochMilliseconds(-1)).toThrow()
  })
})

describe('FEFO : même ordre que strcmp côté PHP', () => {
  it('compare des points de code, pas des unités UTF-16', () => {
    // U+FFFD précède U+1F600 en points de code (et en octets UTF-8) ; « < » en UTF-16 dirait l'inverse.
    const order = fefoOrder([
      { id: 'lot-\u{1F600}', expiresOn: null },
      { id: 'lot-\u{FFFD}', expiresOn: null },
    ])
    expect(order).toEqual(['lot-\u{FFFD}', 'lot-\u{1F600}'])
  })
})
