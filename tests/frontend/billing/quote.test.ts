import { describe, expect, it } from 'vitest'

import { quoteFromRevision } from '@/documents/quotes/fromRevision'
import { buildQuote, QUOTE_NOT_INVOICE } from '@/documents/templates/financial/quote'
import { freeLine, type DraftLineForm } from '@/domain/billing/draft'
import {
  buildConvertPayload,
  buildCreateQuotePayload,
  linesFromQuote,
  priceComparison,
  quoteActions,
  quoteLinesPayload,
  recipientLabel,
  validateQuoteLines,
} from '@/domain/billing/quote'
import type { Quote, QuoteRevision } from '@/domain/contracts/generated'

import { FORBIDDEN_WORDS, layout, printedText } from '../documents/support'

/**
 * Devis côté écran (L1D07) : charges des commandes en ligne, actions selon le statut À L'INSTANT, comparaison des
 * prix avant la conversion, et PDF d'une révision figée (« Devis — ne vaut pas facture », numéro de révision,
 * destinataire prospect). Données fictives.
 */
const QUOTE_ID = '01J9ZXK4M2Q8R7S6T5V4W30040'
const ITEM = '01J9ZXK4M2Q8R7S6T5V4W3001X'

function revision(overrides: Partial<QuoteRevision> = {}, revisionNo = 1): QuoteRevision {
  return {
    id: '01J9ZXK4M2Q8R7S6T5V4W30043',
    revision_no: revisionNo,
    recorded_at: '2026-10-23T10:00:00.000Z',
    authored_by: '01J9ZXK4M2Q8R7S6T5V4W30005',
    authored_by_name: 'Caisse (démonstration)',
    reason: revisionNo === 1 ? 'Émission du devis' : 'Nouvelle monture (fictif)',
    snapshot: {
      quote_id: QUOTE_ID,
      number: 'TEST-DEV-2026-000001',
      revision_no: revisionNo,
      recorded_at: '2026-10-23T10:00:00.000Z',
      issued_at: '2026-10-22T09:30:00.000Z',
      valid_until: '2026-11-21',
      recipient: {
        kind: 'PATIENT',
        patient_id: '01J9ZXK4M2Q8R7S6T5V4W30001',
        prospect_contact_id: null,
        record_number: 'EE-2026-0001',
        last_name: 'Patient Fictif',
        first_names: 'Test',
        phone: null,
      },
      lines: [
        {
          line_id: '01J9ZXK4M2Q8R7S6T5V4W30041',
          position: 1,
          catalog_item_id: ITEM,
          reference: 'FR-001',
          description: 'Monture fictive',
          unit_code: 'UNIT',
          quantity: '1.000',
          unit_price_gross: 40000,
          discount_gross: 0,
          line_total_gross: 40000,
          tax_code: 'NO_TAX_CONFIGURED',
          tax_rate_percent: null,
          tax_amount: null,
        },
      ],
      total_gross: 40000,
      currency: 'XAF',
      tax: { regime: 'NO_TAX_CONFIGURED', rules: [] },
      clinic: {
        clinic_id: '01J9ZXK4M2Q8R7S6T5V4W30000',
        name: 'Cabinet de démonstration',
        address: null,
        phone: null,
        email: null,
        tax_identifier: null,
        professional_mentions: null,
        document_settings: null,
        currency: 'XAF',
        timezone: 'Africa/Douala',
        test_document: true,
      },
      authored_by: {
        user_id: '01J9ZXK4M2Q8R7S6T5V4W30005',
        display_name: 'Caisse (démonstration)',
      },
      reason: revisionNo === 1 ? 'Émission du devis' : 'Nouvelle monture (fictif)',
      template_version: 'quote.v1',
    },
    document: null,
    ...overrides,
  }
}

function quote(overrides: Partial<Quote> = {}): Quote {
  return {
    id: QUOTE_ID,
    number: 'TEST-DEV-2026-000001',
    status: 'ISSUED',
    effective_status: 'ISSUED',
    valid_until: '2026-11-21',
    total_gross: 40000,
    recipient: revision().snapshot.recipient,
    issued_at: '2026-10-22T09:30:00.000Z',
    created_by: '01J9ZXK4M2Q8R7S6T5V4W30005',
    created_by_name: 'Caisse (démonstration)',
    version: 2,
    current_revision: 1,
    lines: [
      {
        id: '01J9ZXK4M2Q8R7S6T5V4W30041',
        position: 1,
        catalog_item_id: ITEM,
        description_snapshot: 'Monture fictive',
        unit_code: 'UNIT',
        quantity: '1.000',
        unit_price_gross: 40000,
        discount_gross: 0,
        line_total_gross: 40000,
      },
    ],
    revisions: [revision()],
    invoices: [],
    created_at: '2026-10-22T09:00:00.000Z',
    updated_at: '2026-10-22T09:30:00.000Z',
    ...overrides,
  }
}

describe('charges des commandes de devis', () => {
  it('les lignes partent sans prescription, positions 1..n, remise vide = 0', () => {
    const lines: DraftLineForm[] = linesFromQuote(quote())
    const payload = quoteLinesPayload(lines)
    expect(payload).toEqual([
      {
        line_id: '01J9ZXK4M2Q8R7S6T5V4W30041',
        position: 1,
        catalog_item_id: ITEM,
        description: 'Monture fictive',
        quantity: '1.000',
        unit_price_gross: 40000,
        discount_gross: 0,
      },
    ])
    expect('prescription_id' in (payload[0] ?? {})).toBe(false)
  })

  it('un prospect part avec son nom et son téléphone, jamais avec un patient', () => {
    const payload = buildCreateQuotePayload({
      quoteId: QUOTE_ID,
      recipient: { kind: 'PROSPECT', name: '  Prospect Fictif ', phone: ' ' },
      validUntil: null,
      lines: [],
    })
    expect(payload.patient_id).toBeNull()
    expect(payload.prospect).toEqual({ name: 'Prospect Fictif', phone: null })
  })

  it('la conversion confirme toujours les prix explicitement', () => {
    const payload = buildConvertPayload({
      quoteId: QUOTE_ID,
      revisionNo: 1,
      invoiceId: '01J9ZXK4M2Q8R7S6T5V4W30045',
      patientId: null,
      priceBasis: 'CATALOG',
    })
    expect(payload.prices_confirmed).toBe(true)
    expect(payload.price_basis).toBe('CATALOG')
  })

  it('un devis sans ligne ne s’émet pas ; les messages parlent de devis', () => {
    expect(validateQuoteLines([], { forIssue: true }).general).toContain('Un devis sans ligne')
    const free = {
      ...freeLine('01J9ZXK4M2Q8R7S6T5V4W30046'),
      description: 'Ajustage',
      unitPriceGross: 2000,
    }
    expect(validateQuoteLines([free]).errors).toEqual([])
  })
})

describe('actions selon le statut à l’instant', () => {
  it('un brouillon se modifie et s’émet, rien d’autre', () => {
    const actions = quoteActions(
      quote({ status: 'DRAFT', effective_status: 'DRAFT', number: null }),
      true,
    )
    expect(actions).toEqual({
      editDraft: true,
      issue: true,
      revise: false,
      accept: false,
      decline: false,
      convert: false,
    })
  })

  it('un devis émis expiré se révise mais ne s’accepte ni ne se convertit', () => {
    const actions = quoteActions(quote({ effective_status: 'EXPIRED' }), true)
    expect(actions.revise).toBe(true)
    expect(actions.accept).toBe(false)
    expect(actions.convert).toBe(false)
  })

  it('un devis converti ne se reconvertit que si son brouillon a été abandonné', () => {
    const converted = quote({ status: 'CONVERTED', effective_status: 'CONVERTED' })
    expect(
      quoteActions(
        {
          ...converted,
          invoices: [{ id: '01J9ZXK4M2Q8R7S6T5V4W30045', number: null, status: 'DRAFT' }],
        },
        true,
      ).convert,
    ).toBe(false)
    expect(
      quoteActions(
        {
          ...converted,
          invoices: [{ id: '01J9ZXK4M2Q8R7S6T5V4W30045', number: null, status: 'ABANDONED' }],
        },
        true,
      ).convert,
    ).toBe(true)
  })

  it('sans droit d’écriture, aucune action', () => {
    expect(Object.values(quoteActions(quote(), false)).some(Boolean)).toBe(false)
  })
})

describe('comparaison des prix avant conversion', () => {
  it('signale un prix du catalogue différent de celui du devis', () => {
    const rows = priceComparison(revision(), new Map([[ITEM, 45000]]))
    expect(rows[0]).toMatchObject({ quotePrice: 40000, catalogPrice: 45000, differs: true })
    expect(priceComparison(revision(), new Map())[0]).toMatchObject({
      catalogPrice: null,
      differs: false,
    })
  })
})

describe('PDF d’une révision figée', () => {
  it('dit « ne vaut pas facture », le numéro de révision, et ne parle d’aucun règlement', () => {
    const text = printedText(layout(buildQuote(quoteFromRevision(revision({}, 2).snapshot))))
    expect(text).toContain(QUOTE_NOT_INVOICE)
    expect(text).toContain('TEST-DEV-2026-000001')
    expect(text).toContain('Révision | n° 2')
    expect(text).toContain('Motif de la révision | Nouvelle monture (fictif)')
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern)
  })

  it('imprime un prospect par son nom et son téléphone', () => {
    const base = revision()
    const prospect = revision({
      snapshot: {
        ...base.snapshot,
        recipient: {
          kind: 'PROSPECT',
          patient_id: null,
          prospect_contact_id: '01J9ZXK4M2Q8R7S6T5V4W30047',
          record_number: null,
          last_name: 'Prospect Fictif',
          first_names: null,
          phone: '600 00 00 00',
        },
      },
    })
    const text = printedText(layout(buildQuote(quoteFromRevision(prospect.snapshot))))
    expect(text).toContain('Prospect Fictif')
    expect(text).toContain('600 00 00 00')
    expect(recipientLabel(prospect.snapshot.recipient)).toBe(
      'Prospect Fictif (prospect, 600 00 00 00)',
    )
  })
})
