// @vitest-environment node
import { describe, expect, it } from 'vitest'

import { NBSP } from '@/documents/engine/charset'
import { DocumentDataError } from '@/documents/engine/errors'
import {
  civilDateOf,
  formatAxis,
  formatCivilDateFr,
  formatDateFr,
  formatDateTimeFr,
  formatDiopter,
  formatMillimeters,
  formatNumberFr,
  formatPersonName,
  formatQuantity,
  formatRate,
  formatXaf,
  MISSING,
  shortReference,
  sumMoney,
} from '@/documents/engine/format'

describe('montants FCFA : entiers, espaces insécables, aucun flottant', () => {
  it('groupe par trois avec U+00A0 et colle l’unité', () => {
    expect(formatNumberFr(0)).toBe('0')
    expect(formatNumberFr(999)).toBe('999')
    expect(formatNumberFr(1000)).toBe(`1${NBSP}000`)
    expect(formatNumberFr(80000)).toBe(`80${NBSP}000`)
    expect(formatXaf(80000)).toBe(`80${NBSP}000${NBSP}FCFA`)
    expect(formatXaf(1_000_000_000_000)).toBe(`1${NBSP}000${NBSP}000${NBSP}000${NBSP}000${NBSP}FCFA`)
  })

  it('n’emploie jamais l’espace fine U+202F (absente du jeu de caractères des PDF)', () => {
    expect(formatXaf(123456789)).not.toContain('\u{202F}')
  })

  it('écrit un montant négatif avec le tiret ASCII', () => {
    expect(formatXaf(-50000)).toBe(`-50${NBSP}000${NBSP}FCFA`)
    expect(formatNumberFr(-0)).toBe('0')
  })

  it('refuse un montant décimal, non fini ou hors bornes plutôt que de l’arrondir', () => {
    for (const bad of [10.5, Number.NaN, Number.POSITIVE_INFINITY, 1_000_000_000_001]) {
      expect(() => formatXaf(bad), String(bad)).toThrow(DocumentDataError)
    }
  })

  it('additionne exactement et refuse un total hors bornes', () => {
    expect(sumMoney([30000, 50000])).toBe(80000)
    expect(sumMoney([])).toBe(0)
    expect(sumMoney([100, -30])).toBe(70)
    expect(() => sumMoney([1_000_000_000_000, 1])).toThrow(DocumentDataError)
    expect(() => sumMoney([1.5])).toThrow(DocumentDataError)
  })
})

describe('quantités, taux, mesures optiques', () => {
  it('quantité décimale canonique : sans zéros inutiles, virgule française, jamais arrondie', () => {
    expect(formatQuantity('2.000')).toBe('2')
    expect(formatQuantity('1.500')).toBe('1,5')
    expect(formatQuantity('0.250')).toBe('0,25')
    expect(formatQuantity('1000.000')).toBe(`1${NBSP}000`)
    expect(() => formatQuantity('1,5')).toThrow(DocumentDataError)
    expect(() => formatQuantity('abc')).toThrow(DocumentDataError)
  })

  it('taux en pourcentage : zéro est une valeur', () => {
    expect(formatRate('19.2500')).toBe(`19,25${NBSP}%`)
    expect(formatRate('0.0000')).toBe(`0${NBSP}%`)
    expect(() => formatRate('x')).toThrow(DocumentDataError)
  })

  it('valeur optique : signe explicite hors zéro, zéro visible, absent = tiret jamais zéro', () => {
    expect(formatDiopter('-1.25')).toBe('-1,25')
    expect(formatDiopter('1.25')).toBe('+1,25')
    expect(formatDiopter('+2.00')).toBe('+2,00')
    expect(formatDiopter('0.00')).toBe('0,00')
    expect(formatDiopter('-0.00')).toBe('0,00')
    expect(formatDiopter(null)).toBe(MISSING)
    expect(MISSING).not.toBe('0')
    expect(() => formatDiopter('1,25')).toThrow(DocumentDataError)
  })

  it('mesure en millimètres : sans signe, avec unité visible', () => {
    expect(formatMillimeters('63.00')).toBe(`63,00${NBSP}mm`)
    expect(formatMillimeters('+22.5')).toBe(`22,5${NBSP}mm`)
    expect(formatMillimeters(null)).toBe(MISSING)
  })

  it('axe : entier de 0 à 180 degrés, le zéro est une valeur', () => {
    expect(formatAxis(90)).toBe('90\u{00B0}')
    expect(formatAxis(0)).toBe('0\u{00B0}')
    expect(formatAxis(180)).toBe('180\u{00B0}')
    expect(formatAxis(null)).toBe(MISSING)
    expect(() => formatAxis(181)).toThrow(DocumentDataError)
    expect(() => formatAxis(45.5)).toThrow(DocumentDataError)
  })
})

describe('dates et heures au fuseau du cabinet', () => {
  it('formate un instant UTC à l’heure de Douala (UTC+1, sans heure d’été)', () => {
    expect(formatDateTimeFr('2026-10-14T09:30:00.000Z', 'Africa/Douala')).toBe('14/10/2026 à 10h30')
    expect(formatDateFr('2026-10-14T09:30:00.000Z', 'Africa/Douala')).toBe('14/10/2026')
  })

  it('le jour civil suit le fuseau : 23h30 UTC est déjà le lendemain à Douala', () => {
    expect(civilDateOf('2026-10-14T23:30:00.000Z', 'Africa/Douala')).toBe('2026-10-15')
    expect(formatDateFr('2026-10-14T23:30:00.000Z', 'Africa/Douala')).toBe('15/10/2026')
    expect(formatDateFr('2026-10-14T23:30:00.000Z', 'UTC')).toBe('14/10/2026')
  })

  it('un fuseau inconnu retombe sur celui du cabinet par défaut sans bloquer l’impression', () => {
    expect(formatDateTimeFr('2026-10-14T09:30:00.000Z', 'Mars/Phobos')).toBe('14/10/2026 à 10h30')
  })

  it('refuse un instant mal formé', () => {
    for (const bad of ['2026-10-14', '2026-10-14T09:30:00Z', 'hier', '2026-13-40T00:00:00.000Z']) {
      expect(() => formatDateFr(bad, 'Africa/Douala'), bad).toThrow(DocumentDataError)
    }
  })

  it('date civile : sans calcul de fuseau', () => {
    expect(formatCivilDateFr('2026-10-15')).toBe('15/10/2026')
    expect(() => formatCivilDateFr('15/10/2026')).toThrow(DocumentDataError)
  })
})

describe('identité imprimée', () => {
  it('imprime le nom tel qu’enregistré, sans inventer un prénom absent', () => {
    expect(formatPersonName('PATIENT FICTIF', 'Élodie Marie')).toBe('PATIENT FICTIF Élodie Marie')
    expect(formatPersonName('PATIENT FICTIF', null)).toBe('PATIENT FICTIF')
    expect(formatPersonName('PATIENT FICTIF', '  ')).toBe('PATIENT FICTIF')
  })

  it('réduit un identifiant à sa fin', () => {
    expect(shortReference('01J9ZXK4M2Q8R7S6T5V4W3002Z')).toBe('V4W3002Z')
  })
})
