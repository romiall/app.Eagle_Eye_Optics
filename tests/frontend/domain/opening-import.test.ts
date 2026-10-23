import { describe, expect, it } from 'vitest'

import type { CatalogItem, StockLocation } from '@/domain/contracts/generated'
import { openingTemplateCsv, readOpeningCsv } from '@/domain/inventory/openingImport'

/**
 * Stock d'ouverture depuis un fichier CSV (X01) : le fichier remplit le formulaire de stock initial ; chaque
 * référence est retrouvée au catalogue (casse et accents ignorés, ponctuation gardée), l'emplacement par son
 * code ou son nom, la date et le coût lus sans invention ; à la moindre erreur, aucune ligne n'est reprise.
 */
let counter = 0
const newId = (): string => `01J9ZXK4M2Q8R7S6T5V4W3${String(++counter).padStart(4, '0')}`

const item = (id: string, reference: string, overrides: Partial<CatalogItem> = {}): CatalogItem =>
  ({ id, reference, stock_mode: 'STOCKED', active: true, ...overrides }) as unknown as CatalogItem
const ITEMS = [
  item('01J9ZXK4M2Q8R7S6T5V4W3IT01', 'EX-MONT-001'),
  item('01J9ZXK4M2Q8R7S6T5V4W3IT02', 'EX-COLL-001'),
  item('01J9ZXK4M2Q8R7S6T5V4W3IT03', 'EX-EXAM-001', { stock_mode: 'NONE' }),
]
const MAIN = {
  id: '01J9ZXK4M2Q8R7S6T5V4W3LOC1',
  code: 'PRINCIPAL',
  name: 'Stock principal',
} as unknown as StockLocation
const SHOP = {
  id: '01J9ZXK4M2Q8R7S6T5V4W3LOC2',
  code: 'VITRINE',
  name: 'Vitrine',
} as unknown as StockLocation

describe('stock initial depuis un fichier', () => {
  it('le modèle remplit deux lignes dans l’emplacement unique', () => {
    const { lines, problems } = readOpeningCsv(openingTemplateCsv(), ITEMS, [MAIN], newId)
    expect(problems).toEqual([])
    expect(lines).toHaveLength(2)
    expect(lines[0]).toMatchObject({
      itemId: ITEMS[0]?.id,
      locationId: MAIN.id,
      quantity: '3',
      batchNumber: '',
      expiresOn: null,
      unitCost: null,
    })
    expect(lines[1]).toMatchObject({ batchNumber: 'LOT-EXEMPLE', expiresOn: '2027-12-31' })
  })

  it('la référence se compare sans casse ni accents, l’emplacement par code ou nom', () => {
    const csv =
      'Référence;Quantité;Emplacement;Coût unitaire\nex-mont-001;2;vitrine;25 000\nEX-COLL-001;5;Stock principal;'
    const { lines, problems } = readOpeningCsv(csv, ITEMS, [MAIN, SHOP], newId)
    expect(problems).toEqual([])
    expect(lines.map((line) => [line.itemId, line.locationId, line.unitCost])).toEqual([
      [ITEMS[0]?.id, SHOP.id, 25000],
      [ITEMS[1]?.id, MAIN.id, null],
    ])
  })

  it('à la moindre erreur, aucune ligne n’est reprise et chaque problème est dit', () => {
    const csv = [
      'reference;quantite;emplacement;peremption',
      'INCONNUE;1;;',
      'EX-EXAM-001;1;PRINCIPAL;',
      'EX-COLL-001;;PRINCIPAL;31/02/2027',
      'EX-MONT-001;1;;',
    ].join('\n')
    const { lines, problems } = readOpeningCsv(csv, ITEMS, [MAIN, SHOP], newId)
    expect(lines).toEqual([])
    expect(problems.map((problem) => [problem.line, problem.column])).toEqual([
      [2, 'reference'],
      [2, 'emplacement'],
      [3, 'reference'],
      [4, 'quantite'],
      [4, 'peremption'],
      [5, 'emplacement'],
    ])
  })

  it('les colonnes obligatoires absentes arrêtent la lecture', () => {
    expect(
      readOpeningCsv('lot;peremption\nA;', ITEMS, [MAIN], newId).problems.map((p) => p.column),
    ).toEqual(['reference', 'quantite'])
  })
})
