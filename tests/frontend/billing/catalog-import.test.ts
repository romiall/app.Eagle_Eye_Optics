import { describe, expect, it } from 'vitest'

import {
  catalogTemplateCsv,
  headerKey,
  IMPORT_MAX_ROWS,
  parseCsv,
  readCatalogCsv,
} from '@/domain/billing/catalogImport'
import type { TaxRule } from '@/domain/contracts/generated'

/**
 * Lecture du fichier CSV du catalogue sur le poste (X01) : séparateur détecté, guillemets, en-têtes en
 * français sans accents ni casse, oui/non, montants « 50 000 », taxe par son code ; problèmes dits avec le
 * numéro de ligne du fichier, jamais une ligne silencieusement ignorée. Données fictives.
 */
const TAX = { id: '01J9ZXK4M2Q8R7S6T5V4W3TAXE', code: 'TVA-TEST' } as unknown as TaxRule

describe('lecture CSV', () => {
  it('détecte le séparateur et respecte les guillemets', () => {
    expect(parseCsv('a;b\n"x;y";"dit ""oui"""\n')).toEqual([
      ['a', 'b'],
      ['x;y', 'dit "oui"'],
    ])
    expect(parseCsv('\u{FEFF}a,b\r\n1,2')).toEqual([
      ['a', 'b'],
      ['1', '2'],
    ])
    expect(parseCsv('a\tb\n1\t2\n\n')).toEqual([
      ['a', 'b'],
      ['1', '2'],
    ])
  })

  it('compare les en-têtes sans casse ni accents', () => {
    expect(headerKey('Désignation')).toBe('designation')
    expect(headerKey('Prix de vente')).toBe('prix_de_vente')
  })
})

describe('lignes du catalogue', () => {
  it('le modèle se relit sans aucun problème', () => {
    const parsed = readCatalogCsv(catalogTemplateCsv(), [])
    expect(parsed.problems).toEqual([])
    expect(parsed.rows).toHaveLength(2)
    expect(parsed.rows[0]).toMatchObject({
      line: 2,
      category_code: 'FRAME',
      unit_code: 'PAIR',
      quantity_integer_only: true,
      sale_price: 50000,
      stock_mode: 'STOCKED',
      active: true,
      public_visibility: false,
    })
    expect(parsed.rows[1]).toMatchObject({
      category_code: 'SERVICE',
      stock_mode: 'NONE',
      unit_code: 'SERVICE',
    })
  })

  it('une taxe se désigne par son code ; un code inconnu est signalé', () => {
    const csv =
      'reference;designation;categorie;unite;prix_vente;mode_stock;taxe\nR1;Article;Monture;Unité;1 000;stocké;tva-test\nR2;Article;Monture;Unité;1 000;stocké;AUTRE'
    const parsed = readCatalogCsv(csv, [TAX])
    expect(parsed.rows[0]?.tax_rule_id).toBe(TAX.id)
    expect(parsed.problems).toEqual([
      {
        line: 3,
        column: 'taxe',
        message: 'Code de taxe inconnu dans les règles fiscales du cabinet.',
      },
    ])
  })

  it('montants, oui/non et mode de stock illisibles sont dits ligne par ligne', () => {
    const csv =
      'reference;designation;categorie;unite;prix_vente;mode_stock;actif\nR1;A;Monture;Unité;12,5;stocké;peut-être\nR2;B;Monture;Unité;1000;congelé;oui'
    const parsed = readCatalogCsv(csv, [])
    expect(parsed.problems.map((problem) => [problem.line, problem.column])).toEqual([
      [2, 'actif'],
      [2, 'prix_vente'],
      [3, 'mode_stock'],
    ])
  })

  it('une colonne obligatoire absente arrête la lecture', () => {
    const parsed = readCatalogCsv('reference;designation\nR1;A', [])
    expect(parsed.rows).toEqual([])
    expect(parsed.problems.map((problem) => problem.column)).toEqual([
      'categorie',
      'unite',
      'prix_vente',
      'mode_stock',
    ])
  })

  it('les colonnes inconnues sont ignorées et signalées, jamais devinées', () => {
    const parsed = readCatalogCsv(
      `${catalogTemplateCsv().split('\r\n')[0]};couleur\nR1;A;Monture;;;Unité;;1000;;;stocké;;;;;;rouge`,
      [],
    )
    expect(parsed.ignoredColumns).toEqual(['couleur'])
  })

  it(`au plus ${IMPORT_MAX_ROWS} articles par fichier`, () => {
    const header = 'reference;designation;categorie;unite;prix_vente;mode_stock'
    const lines = Array.from(
      { length: IMPORT_MAX_ROWS + 1 },
      (_, index) => `R${index};A;Monture;Unité;1000;stocké`,
    )
    const parsed = readCatalogCsv([header, ...lines].join('\n'), [])
    expect(parsed.rows).toEqual([])
    expect(parsed.problems[0]?.message).toContain('découpez')
  })
})
