import { describe, expect, it } from 'vitest'

import {
  ageBandLabel,
  MAX_PERIOD_DAYS,
  percentLabel,
  periodError,
  presetPeriod,
  weekdayIndex,
} from '@/domain/reports/statistics'
import { CivilDate } from '@/domain/values/civilDate'

/**
 * Règles de l'écran des statistiques (R02) : périodes civiles du cabinet (semaine du lundi au dimanche),
 * même borne de 366 jours que le serveur, parts en dixièmes de pour cent sans calcul flottant.
 */
const day = (iso: string): CivilDate => CivilDate.fromString(iso)

describe('périodes', () => {
  it('le jour de la semaine part du lundi (le 2026-10-22 est un jeudi)', () => {
    expect(weekdayIndex(day('2026-10-19'))).toBe(0)
    expect(weekdayIndex(day('2026-10-22'))).toBe(3)
    expect(weekdayIndex(day('2026-10-25'))).toBe(6)
  })

  it('aujourd’hui, semaine, mois et année civils', () => {
    const today = day('2026-10-22')
    expect(presetPeriod('day', today)).toEqual({ from: today, to: today })
    expect(presetPeriod('week', today)).toEqual({ from: day('2026-10-19'), to: day('2026-10-25') })
    expect(presetPeriod('month', today)).toEqual({ from: day('2026-10-01'), to: day('2026-10-31') })
    expect(presetPeriod('year', today)).toEqual({ from: day('2026-01-01'), to: day('2026-12-31') })
  })

  it('février d’une année bissextile et semaine à cheval sur deux mois', () => {
    expect(presetPeriod('month', day('2028-02-10')).to).toEqual(day('2028-02-29'))
    expect(presetPeriod('week', day('2026-11-01'))).toEqual({
      from: day('2026-10-26'),
      to: day('2026-11-01'),
    })
  })

  it('un intervalle personnalisé est borné comme côté serveur', () => {
    expect(periodError(null, day('2026-10-01'))).toMatch(/début et la fin/)
    expect(periodError(day('2026-10-31'), day('2026-10-01'))).toMatch(/précède/)
    expect(periodError(day('2028-01-01'), day('2028-12-31'))).toBeNull()
    expect(periodError(day('2025-01-01'), day('2026-01-02'))).toContain(String(MAX_PERIOD_DAYS))
  })
})

describe('parts et libellés', () => {
  it('part à une décimale, arrondie au plus proche, jamais inventée sans dénominateur', () => {
    expect(percentLabel(1, 3)).toBe('33,3 %')
    expect(percentLabel(2, 3)).toBe('66,7 %')
    expect(percentLabel(1, 8)).toBe('12,5 %')
    expect(percentLabel(5, 5)).toBe('100,0 %')
    expect(percentLabel(0, 0)).toBe('—')
  })

  it('tranches d’âge', () => {
    expect(ageBandLabel(0, 17)).toBe('0 à 17 ans')
    expect(ageBandLabel(60, null)).toBe('60 ans et plus')
  })
})
