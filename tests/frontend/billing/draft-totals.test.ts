// @vitest-environment node
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  buildCreateDraftPayload,
  buildUpdateDraftPayload,
  computeDraftLine,
  discountRule,
  type DraftLineForm,
  draftTotals,
  freeLine,
  lineFromCatalogItem,
  linesFromInvoice,
  sameDraftLines,
  serverLineError,
  validateDraftLines,
} from '@/domain/billing/draft'
import { buildIssuePayload, displayedLinesFingerprint, heldByDevice, issueBlockers } from '@/domain/billing/issueInvoice'
import type { CatalogItem, Invoice } from '@/domain/contracts/generated'

/**
 * Totaux du brouillon (B02) : l'écran calcule avec les MÊMES règles que le serveur. Les vecteurs
 * communs `contracts/vectors/invoice.json` (rejoués aussi par PHPUnit) passent ici par les fonctions de
 * l'écran (`computeDraftLine`, `draftTotals`) : une ligne du brouillon donne le brut, la remise et le
 * net du vecteur ; un vecteur refusé est refusé, sur le même champ. Données FICTIVES.
 */
const ROOT = join(import.meta.dirname, '..', '..', '..')

interface VectorLine {
  quantity: string
  unit_price_gross: number
  discount_gross: number
  tax_rate_percent: string | null
  integer_only: boolean
}

interface VectorCase {
  id: string
  operation: string
  input: Record<string, unknown>
  expected?: unknown
  error?: { code: string; field: string; rule: string }
}

const vectors = JSON.parse(
  readFileSync(join(ROOT, 'contracts', 'vectors', 'invoice.json'), 'utf8'),
) as { cases: VectorCase[] }

function lineOf(input: VectorLine, index = 0): DraftLineForm {
  return {
    lineId: `01J9ZXK4M2Q8R7S6T5V4W3L${String(index).padStart(3, '0')}`,
    catalogItemId: null,
    reference: null,
    description: 'Ligne fictive',
    unitCode: 'UNIT',
    integerOnly: input.integer_only,
    stockMode: null,
    catalogPrice: null,
    quantity: input.quantity,
    unitPriceGross: input.unit_price_gross,
    discountGross: input.discount_gross,
    prescriptionId: null,
  }
}

const SCREEN_FIELD: Record<string, string> = {
  quantity: 'quantity',
  unit_price_gross: 'unitPriceGross',
  discount_gross: 'discountGross',
}

describe('vecteurs communs des lignes de facture, par les fonctions de l’écran', () => {
  const lineCases = vectors.cases.filter((item) => item.operation === 'invoice.line')

  it('couvre les vecteurs de ligne', () => {
    expect(lineCases.length).toBeGreaterThanOrEqual(20)
  })

  it.each(lineCases.map((item) => [item.id, item] as const))('%s', (_id, item) => {
    const result = computeDraftLine(lineOf(item.input as unknown as VectorLine))
    if (item.error !== undefined) {
      expect(result.ok).toBe(false)
      if (!result.ok && SCREEN_FIELD[item.error.field] !== undefined) {
        expect(result.field).toBe(SCREEN_FIELD[item.error.field])
      }
      return
    }
    const expected = item.expected as { gross: number; discount: number; net: number }
    expect(result.ok).toBe(true)
    if (!result.ok) return
    // La taxe comprise est ventilée par le serveur (règle fiscale lue par la gérante seulement) :
    // brut, remise et net sont ceux du vecteur ; le total TTC n'en dépend pas.
    expect({
      gross: result.amounts.gross,
      discount: result.amounts.discount,
      net: result.amounts.net,
    }).toStrictEqual({ gross: expected.gross, discount: expected.discount, net: expected.net })
  })

  it.each(
    vectors.cases
      .filter((item) => item.operation === 'invoice.total')
      .map((item) => [item.id, item] as const),
  )('total %s', (_id, item) => {
    const lines = ((item.input['lines'] ?? []) as VectorLine[]).map(lineOf)
    const totals = draftTotals(lines)
    if (item.error !== undefined) {
      expect(totals.total).toBeNull()
      expect(totals.totalError).not.toBeNull()
      return
    }
    expect(totals.total).toBe((item.expected as { total: number }).total)
  })
})

const ITEM: CatalogItem = {
  id: '01J9ZXK4M2Q8R7S6T5V4W30C01',
  reference: 'DEMO-MONTURE',
  category_code: 'FRAME',
  name: 'Monture fictive (démonstration)',
  brand: 'MARQUE FICTIVE',
  model: null,
  unit_code: 'UNIT',
  quantity_integer_only: true,
  sale_price: 40000,
  tax_rule_id: null,
  stock_mode: 'STOCKED',
  requires_expiry: false,
  low_stock_threshold: null,
  expiry_alert_days: null,
  active: true,
  public_visibility: false,
  version: 1,
}

describe('lignes du brouillon', () => {
  it('une ligne du catalogue reprend désignation, unité et prix ; quantité 1', () => {
    const line = lineFromCatalogItem(ITEM, '01J9ZXK4M2Q8R7S6T5V4W30L01')
    expect(line).toMatchObject({
      catalogItemId: ITEM.id,
      description: 'Monture fictive (démonstration)',
      unitCode: 'UNIT',
      integerOnly: true,
      quantity: '1.000',
      unitPriceGross: 40000,
      discountGross: null,
      stockMode: 'STOCKED',
    })
    expect(draftTotals([line]).total).toBe(40000)
  })

  it('refuse une quantité fractionnaire pour un article à l’unité, une remise au-delà du brut', () => {
    const line = lineFromCatalogItem(ITEM, '01J9ZXK4M2Q8R7S6T5V4W30L01')
    expect(computeDraftLine({ ...line, quantity: '1.500' })).toMatchObject({
      ok: false,
      field: 'quantity',
    })
    expect(computeDraftLine({ ...line, discountGross: 40001 })).toMatchObject({
      ok: false,
      field: 'discountGross',
    })
    expect(draftTotals([line, { ...line, lineId: 'x', quantity: null }]).total).toBeNull()
  })

  it('valide désignation, quantité et nombre de lignes ; une facture vide ne s’émet pas', () => {
    const free = freeLine('01J9ZXK4M2Q8R7S6T5V4W30L02')
    const check = validateDraftLines([free])
    expect(check.errors.map((error) => error.field).sort()).toEqual(['description', 'unitPriceGross'])
    expect(validateDraftLines([{ ...free, description: 'a\nb', unitPriceGross: 1000 }]).errors).toEqual([
      expect.objectContaining({ field: 'description' }),
    ])
    expect(validateDraftLines([]).general).toBeNull()
    expect(validateDraftLines([], { forIssue: true }).general).toContain('sans ligne')
  })

  it('construit les charges avec positions 1..n et remise vide = 0', () => {
    const first = lineFromCatalogItem(ITEM, '01J9ZXK4M2Q8R7S6T5V4W30L01')
    const second = { ...first, lineId: '01J9ZXK4M2Q8R7S6T5V4W30L02', discountGross: 5000 }
    const create = buildCreateDraftPayload({
      invoiceId: '01J9ZXK4M2Q8R7S6T5V4W30I01',
      patientId: '01J9ZXK4M2Q8R7S6T5V4W30P01',
      visitId: null,
      dueDate: null,
      lines: [first, second],
    })
    expect(create.lines.map((line) => [line.position, line.discount_gross])).toEqual([
      [1, 0],
      [2, 5000],
    ])
    expect(create).not.toHaveProperty('number')
    const update = buildUpdateDraftPayload({
      invoiceId: '01J9ZXK4M2Q8R7S6T5V4W30I01',
      visitId: null,
      dueDate: null,
      lines: [second],
    })
    expect(update.lines[0]).toMatchObject({ position: 1, quantity: '1.000', unit_price_gross: 40000 })
  })

  it('rattache une erreur du serveur à sa ligne et à son champ', () => {
    expect(serverLineError('lines.1.discount_gross', 'Remise refusée.')).toEqual({
      index: 1,
      field: 'discountGross',
      message: 'Remise refusée.',
    })
    expect(serverLineError('lines.0.quantity', 'Stock insuffisant.')).toMatchObject({
      field: 'quantity',
    })
    expect(serverLineError('patient_id', 'x')).toBeNull()
  })

  it('compare les lignes saisies aux lignes enregistrées (rien à enregistrer)', () => {
    const line = lineFromCatalogItem(ITEM, '01J9ZXK4M2Q8R7S6T5V4W30L01')
    expect(sameDraftLines([line], [{ ...line, description: ' Monture fictive (démonstration) ' }])).toBe(true)
    expect(sameDraftLines([line], [{ ...line, discountGross: 1 }])).toBe(false)
    expect(sameDraftLines([line], [{ ...line, discountGross: 0 }])).toBe(true)
  })

  it('dit la règle de remise selon l’autorité de prix (le serveur tranche)', () => {
    expect(discountRule(true)).toContain('montant brut')
    expect(discountRule(false)).toContain('0 %')
  })
})

const DRAFT: Invoice = {
  id: '01J9ZXK4M2Q8R7S6T5V4W30026',
  patient_id: '01J9ZXK4M2Q8R7S6T5V4W30001',
  visit_id: null,
  number: null,
  status: 'DRAFT',
  issued_at: null,
  issued_by: null,
  issued_by_name: null,
  due_date: null,
  currency: 'XAF',
  total_gross: 80000,
  origin: 'NATIVE',
  legacy_reference: null,
  draft_version: 2,
  ledger_version: null,
  balance: {
    total_gross: 80000,
    paid_net: 0,
    credited: 0,
    refunded: 0,
    balance_due: 0,
    credit_available: 0,
    settlement: 'NOT_ISSUED',
  },
  lines: [
    {
      id: '01J9ZXK4M2Q8R7S6T5V4W3001W',
      position: 1,
      catalog_item_id: ITEM.id,
      reference_snapshot: 'DEMO-MONTURE',
      description_snapshot: 'Monture fictive (démonstration)',
      category_snapshot: 'FRAME',
      unit_snapshot: 'UNIT',
      quantity: '2',
      unit_price_gross: 40000,
      discount_gross: 0,
      line_total_gross: 80000,
      tax_code_snapshot: 'NO_TAX_CONFIGURED',
      tax_rate_snapshot: null,
      tax_amount: null,
      prescription_id: null,
    },
  ],
  payments: [],
  credit_notes: [],
  refunds: [],
  write_owner: null,
  document: null,
  patient_snapshot: null,
  clinic_snapshot: null,
  tax_snapshot: null,
}

describe('brouillon relu et émission', () => {
  it('relit les lignes du serveur en quantités canoniques, unité entière déduite du référentiel', () => {
    const [line] = linesFromInvoice(DRAFT)
    expect(line).toMatchObject({ quantity: '2.000', integerOnly: true, discountGross: null })
    expect(draftTotals(linesFromInvoice(DRAFT)).total).toBe(80000)
  })

  it('émet sans numéro, sans poste propriétaire ni réserve choisie par l’écran', () => {
    const payload = buildIssuePayload({
      invoiceId: DRAFT.id,
      numberUsageId: '01J9ZXK4M2Q8R7S6T5V4W30027',
      documentId: '01J9ZXK4M2Q8R7S6T5V4W30029',
      issuedAt: '2026-10-14T09:30:00.000Z',
      totalGross: 80000,
      catalogSnapshotSha256: 'a'.repeat(64),
    })
    expect(payload).not.toHaveProperty('number')
    expect(payload).toMatchObject({ write_owner_id: null, reservations: [], total_gross: 80000 })
  })

  it('dit pourquoi une émission n’est pas proposée', () => {
    expect(issueBlockers(DRAFT, 80000)).toEqual([])
    expect(issueBlockers({ ...DRAFT, lines: [] }, 0)).toEqual([
      expect.stringContaining('sans ligne'),
    ])
    const held = { ...DRAFT, write_owner: { device_id: DRAFT.id, epoch: 1, status: 'ACTIVE' as const } }
    expect(heldByDevice(held)).toBe(true)
    expect(issueBlockers(held, 80000)).toEqual([expect.stringContaining('poste préparé')])
    expect(issueBlockers(DRAFT, null)).toEqual([expect.stringContaining('total')])
  })

  it('l’empreinte des lignes affichées change avec la version et le contenu', () => {
    const base = displayedLinesFingerprint(DRAFT)
    expect(displayedLinesFingerprint({ ...DRAFT })).toBe(base)
    expect(displayedLinesFingerprint({ ...DRAFT, draft_version: 3 })).not.toBe(base)
  })
})
