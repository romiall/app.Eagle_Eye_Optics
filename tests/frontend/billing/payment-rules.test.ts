// @vitest-environment node
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  buildCloseSessionPayload,
  buildOpenSessionPayload,
  cashVariance,
  validateClosing,
  varianceDescription,
} from '@/domain/billing/cash'
import {
  CATEGORY_LABELS,
  blankCatalogForm,
  blankTaxRuleForm,
  taxRuleState,
  UNIT_INTEGER_ONLY,
  validateCatalogForm,
  validateTaxRuleForm,
} from '@/domain/billing/catalog'
import {
  balanceLines,
  buildPaymentPayload,
  FORBIDDEN_SETTLEMENT_WORDS,
  receiptKindLabel,
  referenceRequired,
  validatePayment,
} from '@/domain/billing/payment'
import type { InvoiceBalance, TaxRule } from '@/domain/contracts/generated'
import { buildOpeningStockPayload, validateOpeningStock } from '@/domain/inventory/opening'

/**
 * Règles d'écran de l'encaissement (B04), de la caisse (B05), du catalogue (B01) et du stock initial
 * (I00). Le serveur décide ; ces contrôles évitent d'envoyer une saisie manifestement refusée et
 * construisent des charges conformes aux contrats. Données FICTIVES.
 */
const ROOT = join(import.meta.dirname, '..', '..', '..')

describe('encaissement', () => {
  const context = { amountDue: 50000, cashSessionId: '01J9ZXK4M2Q8R7S6T5V4W3002Y' }

  it('accepte un acompte ou le solde, refuse au-delà du reste à encaisser', () => {
    expect(
      validatePayment({ amount: 30000, method: 'CASH', reference: '' }, context),
    ).toMatchObject({
      ok: true,
      amount: 30000,
      reference: null,
    })
    expect(validatePayment({ amount: 50000, method: 'CASH', reference: '' }, context).ok).toBe(true)
    const over = validatePayment({ amount: 50001, method: 'CASH', reference: '' }, context)
    expect(over.ok).toBe(false)
    if (!over.ok) expect(over.errors.amount).toContain('reste à encaisser')
  })

  it('refuse zéro, l’absence de montant ou de mode ; rien à encaisser sur un reste nul', () => {
    const zero = validatePayment({ amount: 0, method: 'CASH', reference: '' }, context)
    expect(zero.ok).toBe(false)
    const empty = validatePayment({ amount: null, method: null, reference: '' }, context)
    expect(empty.ok ? {} : Object.keys(empty.errors).sort()).toEqual(['amount', 'method'])
    const nothing = validatePayment(
      { amount: 1000, method: 'CASH', reference: '' },
      { ...context, amountDue: 0 },
    )
    expect(nothing.ok ? null : nothing.errors.amount).toContain('rien à encaisser')
  })

  it('exige la référence pour mobile money et virement, la session de caisse pour les espèces', () => {
    expect(referenceRequired('MTN_MOMO')).toBe(true)
    expect(referenceRequired('ORANGE_MONEY')).toBe(true)
    expect(referenceRequired('BANK_TRANSFER')).toBe(true)
    expect(referenceRequired('CASH')).toBe(false)
    const noReference = validatePayment(
      { amount: 1000, method: 'MTN_MOMO', reference: '  ' },
      context,
    )
    expect(noReference.ok ? null : noReference.errors.reference).toContain('obligatoire')
    const noSession = validatePayment(
      { amount: 1000, method: 'CASH', reference: '' },
      { ...context, cashSessionId: null },
    )
    expect(noSession.ok ? null : noSession.errors.cashSession).toContain('session de caisse')
    expect(
      validatePayment(
        { amount: 1000, method: 'CHEQUE', reference: '' },
        { ...context, cashSessionId: null },
      ).ok,
    ).toBe(true)
  })

  it('construit la charge sans numéro de reçu, la session seulement pour les espèces', () => {
    const effects = {
      paymentId: '01J9ZXK4M2Q8R7S6T5V4W3002T',
      receiptId: '01J9ZXK4M2Q8R7S6T5V4W3002V',
      receiptNumberUsageId: '01J9ZXK4M2Q8R7S6T5V4W3002W',
      receiptDocumentId: '01J9ZXK4M2Q8R7S6T5V4W3002X',
    }
    const payload = buildPaymentPayload({
      invoiceId: '01J9ZXK4M2Q8R7S6T5V4W30026',
      amount: 30000,
      method: 'MTN_MOMO',
      reference: 'REFERENCE_FICTIVE',
      effectiveAt: '2026-10-14T09:30:00.000Z',
      cashSessionId: '01J9ZXK4M2Q8R7S6T5V4W3002Y',
      effects,
    })
    expect(payload).not.toHaveProperty('receipt_number')
    expect(payload).toMatchObject({
      currency: 'XAF',
      cash_session_id: null,
      retroactive_reason: null,
    })
    expect(
      buildPaymentPayload({
        invoiceId: '01J9ZXK4M2Q8R7S6T5V4W30026',
        amount: 30000,
        method: 'CASH',
        reference: null,
        effectiveAt: '2026-10-14T09:30:00.000Z',
        cashSessionId: '01J9ZXK4M2Q8R7S6T5V4W3002Y',
        effects,
      }).cash_session_id,
    ).toBe('01J9ZXK4M2Q8R7S6T5V4W3002Y')
  })

  it('présente la projection du serveur sans jamais dire payé ni soldé', () => {
    const balance: InvoiceBalance = {
      total_gross: 80000,
      paid_net: 80000,
      credited: 0,
      refunded: 0,
      balance_due: 0,
      credit_available: 0,
      settlement: 'SETTLED',
    }
    const lines = balanceLines(balance)
    expect(lines.map((line) => line.label)).toEqual([
      'Total de la facture',
      'Total encaissé',
      'Reste à encaisser',
    ])
    expect(lines.find((line) => line.key === 'due')?.amount).toBe(0)
    const words = [
      ...lines.map((line) => line.label),
      receiptKindLabel('DEPOSIT'),
      receiptKindLabel('BALANCE_SETTLEMENT'),
    ]
      .join(' ')
      .toLowerCase()
    for (const forbidden of FORBIDDEN_SETTLEMENT_WORDS) expect(words).not.toContain(forbidden)
  })
})

describe('caisse', () => {
  it('écart = compté − attendu, dit sans jugement ; un écart non nul exige un motif', () => {
    expect(cashVariance(105000, 105500)).toBe(-500)
    expect(cashVariance(105500, 105500)).toBe(0)
    expect(varianceDescription(0)).toContain('Aucun écart')
    expect(varianceDescription(-500)).toContain('inférieur')
    expect(varianceDescription(500)).toContain('supérieur')
    expect(validateClosing({ counted: 105000, expected: 105500, reason: '' })).toMatchObject({
      ok: false,
      errors: { reason: expect.stringContaining('motif') },
    })
    expect(
      validateClosing({ counted: 105000, expected: 105500, reason: 'Écart fictif' }),
    ).toMatchObject({ ok: true, variance: -500, reason: 'Écart fictif' })
    expect(validateClosing({ counted: null, expected: 0, reason: '' }).ok).toBe(false)
  })

  it('construit les charges d’ouverture et de clôture du contrat', () => {
    expect(
      buildOpenSessionPayload({
        cashSessionId: '01J9ZXK4M2Q8R7S6T5V4W3002Y',
        cashRegisterId: '01J9ZXK4M2Q8R7S6T5V4W3003R',
        openingAmount: 25000,
        openedAt: '2026-10-14T09:30:00.000Z',
      }),
    ).toStrictEqual({
      cash_session_id: '01J9ZXK4M2Q8R7S6T5V4W3002Y',
      cash_register_id: '01J9ZXK4M2Q8R7S6T5V4W3003R',
      opening_amount: 25000,
      opened_at: '2026-10-14T09:30:00.000Z',
    })
    expect(() =>
      buildOpenSessionPayload({
        cashSessionId: 'x',
        cashRegisterId: 'y',
        openingAmount: -1,
        openedAt: '2026-10-14T09:30:00.000Z',
      }),
    ).toThrow()
    expect(
      buildCloseSessionPayload({
        cashSessionId: '01J9ZXK4M2Q8R7S6T5V4W3002Y',
        closedAt: '2026-10-14T18:00:00.000Z',
        counted: 105000,
        expected: 105500,
        variance: -500,
        reason: 'Écart de monnaie constaté (fictif)',
      }),
    ).toStrictEqual({
      cash_session_id: '01J9ZXK4M2Q8R7S6T5V4W3002Y',
      closed_at: '2026-10-14T18:00:00.000Z',
      counted_amount: 105000,
      expected_amount: 105500,
      variance: -500,
      variance_reason: 'Écart de monnaie constaté (fictif)',
    })
  })
})

describe('catalogue et règles fiscales', () => {
  it('libellés des catégories et unités = référentiel SQL', () => {
    const sql = readFileSync(join(ROOT, 'database', '02_reference_data.sql'), 'utf8')
    const categories = /INSERT INTO `catalog_categories`[^;]*;/.exec(sql)?.[0] ?? ''
    const fromSql = Object.fromEntries(
      [...categories.matchAll(/\('([A-Z_]+)', '([^']+)'\)/g)].map((match) => [match[1], match[2]]),
    )
    expect(CATEGORY_LABELS).toStrictEqual(fromSql)
    const units = /INSERT INTO `units`[^;]*;/.exec(sql)?.[0] ?? ''
    const integerOnly = Object.fromEntries(
      [...units.matchAll(/\('([A-Z_]+)', '[^']+', ([01])\)/g)].map((match) => [
        match[1],
        match[2] === '1',
      ]),
    )
    expect(UNIT_INTEGER_ONLY).toStrictEqual(integerOnly)
  })

  it('applique les règles croisées du serveur (collyre périssable, prestation sans stock, paire entière)', () => {
    const base = {
      ...blankCatalogForm(),
      reference: 'DEMO-COLLYRE',
      name: 'Collyre fictif',
      categoryCode: 'COLLYRE',
      unitCode: 'UNIT',
      salePrice: 5000,
      stockMode: 'STOCKED' as const,
    }
    const collyre = validateCatalogForm(base)
    expect(collyre.ok ? null : collyre.errors.requiresExpiry).toContain('collyre')
    expect(validateCatalogForm({ ...base, requiresExpiry: true }).ok).toBe(true)
    const service = validateCatalogForm({ ...base, categoryCode: 'SERVICE', stockMode: 'STOCKED' })
    expect(service.ok ? null : service.errors.stockMode).toContain('prestation')
    const pair = validateCatalogForm({
      ...base,
      categoryCode: 'LENS',
      unitCode: 'PAIR',
      quantityIntegerOnly: false,
      stockMode: 'MADE_TO_ORDER',
    })
    expect(pair.ok ? null : pair.errors.quantityIntegerOnly).toContain('ne se divise pas')
    const ok = validateCatalogForm({ ...base, requiresExpiry: true, brand: '  ' })
    expect(ok.ok ? ok.payload : null).toMatchObject({
      brand: null,
      purchase_cost: null,
      sale_price: 5000,
    })
  })

  it('une règle active exige un taux validé ; changer le taux retire la validation', () => {
    const form = { ...blankTaxRuleForm(), code: 'TEST_TAXE', label: 'Taxe fictive' }
    expect(validateTaxRuleForm({ ...form, active: true }, null).ok).toBe(false)
    expect(validateTaxRuleForm({ ...form, active: true, ratePercent: '10.0000' }, null).ok).toBe(
      false,
    )
    expect(
      validateTaxRuleForm({ ...form, active: true, ratePercent: '10.0000', validate: true }, null)
        .ok,
    ).toBe(true)
    const validated: TaxRule = {
      id: '01J9ZXK4M2Q8R7S6T5V4W30T01',
      code: 'TEST_TAXE',
      label: 'Taxe fictive',
      rate_percent: '10.0000',
      active: true,
      validated_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
      validated_at: '2026-10-14T09:30:00.000Z',
      legal_note: null,
      version: 1,
    }
    expect(
      validateTaxRuleForm({ ...form, active: true, ratePercent: '10.0000' }, validated).ok,
    ).toBe(true)
    const changed = validateTaxRuleForm(
      { ...form, active: true, ratePercent: '12.5000' },
      validated,
    )
    expect(changed.ok ? null : changed.errors.active).toContain('revalidez')
    expect(validateTaxRuleForm({ ...form, validate: true }, null).ok).toBe(false)
    expect(taxRuleState({ ...validated, validated_at: null })).toContain('non appliquée')
  })
})

describe('stock initial', () => {
  const item = {
    id: '01J9ZXK4M2Q8R7S6T5V4W30C04',
    reference: 'DEMO-COLLYRE',
    category_code: 'COLLYRE',
    name: 'Collyre fictif (démonstration)',
    brand: null,
    model: null,
    unit_code: 'UNIT',
    quantity_integer_only: true,
    sale_price: 5000,
    tax_rule_id: null,
    stock_mode: 'STOCKED' as const,
    requires_expiry: true,
    low_stock_threshold: null,
    expiry_alert_days: 90,
    active: true,
    public_visibility: false,
    version: 1,
  }
  const items = new Map([[item.id, item]])
  const line = {
    movementId: '01J9ZXK4M2Q8R7S6T5V4W30M01',
    lotId: '01J9ZXK4M2Q8R7S6T5V4W30L01',
    itemId: item.id,
    locationId: '01J9ZXK4M2Q8R7S6T5V4W30E01',
    quantity: '12.000',
    batchNumber: 'LOT-FICTIF-1',
    expiresOn: '2027-06-30',
    unitCost: null,
  }

  it('exige un lot daté pour un périssable et un motif', () => {
    expect(
      validateOpeningStock({ reason: 'Comptage fictif', lines: [line], items }).errors,
    ).toEqual([])
    const missing = validateOpeningStock({
      reason: '',
      lines: [{ ...line, batchNumber: '', expiresOn: null, quantity: '1.500' }],
      items,
    })
    expect(missing.general).toContain('motif')
    expect(missing.errors.map((error) => error.field).sort()).toEqual([
      'batchNumber',
      'expiresOn',
      'quantity',
    ])
  })

  it('construit la charge sans version, approbation nulle, coût inconnu nul', () => {
    const payload = buildOpeningStockPayload({
      countedAt: '2026-10-14T08:00:00.000Z',
      reason: 'Comptage fictif',
      lines: [line],
    })
    expect(payload).not.toHaveProperty('expected_version')
    expect(payload.approval_id).toBeNull()
    expect(payload.lines[0]).toMatchObject({
      quantity: '12.000',
      unit_cost: null,
      lot: { lot_id: line.lotId, batch_number: 'LOT-FICTIF-1', expires_on: '2027-06-30' },
    })
  })
})
