import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Invoice } from '@/domain/contracts/generated'

import { type } from '../patients/support'
import {
  apiError,
  buttonByName,
  callsTo,
  CASHIER,
  click,
  forbiddenWordsIn,
  INVOICE_ID,
  issuedInvoice,
  json,
  MANAGER,
  openApp,
  openSession,
  PAYMENT_ID,
  RECEIPT_ID,
  resetSales,
  type SalesApi,
  settle,
  stubSalesApi,
  textOf,
  typeInto,
} from './support'

// Corrections d'une facture émise (B07, B08) sur la fiche facture, EN LIGNE : contre-écriture d'un paiement
// (motif obligatoire, réauthentification 423 puis reprise avec la même clé), avoirs et remboursements listés,
// compte sans la permission qui DEMANDE l'autorisation au lieu d'exécuter. Jamais « payé » ni « soldé ».
// Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetSales()
})

const PAGE = `/ventes/factures/${INVOICE_ID}`
const CREDIT_NOTE_ID = '01J9ZXK4M2Q8R7S6T5V4W3003A'
const REFUND_ID = '01J9ZXK4M2Q8R7S6T5V4W3003E'

/** Facture de 80 000 entièrement encaissée en un paiement (reçu TEST-REC-2026-000001). */
function paidInvoice(overrides: Partial<Invoice> = {}): Invoice {
  return issuedInvoice(80000, {
    ledger_version: 2,
    payments: [
      {
        id: PAYMENT_ID,
        amount: 80000,
        method: 'CASH',
        reference: null,
        effective_at: '2026-10-15T08:30:00.000Z',
        confirmed_at: '2026-10-15T08:30:00.000Z',
        status: 'CONFIRMED',
        receipt_id: RECEIPT_ID,
        receipt_number: 'TEST-REC-2026-000001',
        reversed_amount: 0,
        received_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
        received_by_name: 'Caisse (démonstration)',
      },
    ],
    ...overrides,
  })
}

function server(permissions: string[], invoice: () => Invoice): SalesApi {
  const api = stubSalesApi({ permissions })
  api.on('GET', /^\/api\/v1\/invoices\/([^/]+)$/, () => json(200, { data: invoice() }))
  api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: openSession() }))
  return api
}

describe('contre-écriture d’un paiement', () => {
  it('motif obligatoire, réauthentification puis reprise avec la même clé, facture relue', async () => {
    let reversed = 0
    const api = server([...MANAGER, 'payment.reverse'], () =>
      paidInvoice(
        reversed === 0
          ? {}
          : {
              ledger_version: 3,
              payments: paidInvoice().payments.map((payment) => ({
                ...payment,
                reversed_amount: reversed,
              })),
              reversals: [
                {
                  id: '01J9ZXK4M2Q8R7S6T5V4W3003X',
                  payment_id: PAYMENT_ID,
                  amount: reversed,
                  reason: 'Saisi deux fois',
                  recorded_at: '2026-10-15T09:00:00.000Z',
                  recorded_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
                  recorded_by_name: 'Gérante (démonstration)',
                },
              ],
            },
      ),
    )
    let reauthenticated = false
    api.on('POST', /^\/api\/v1\/auth\/reauthenticate$/, () => {
      reauthenticated = true
      return json(200, { data: { reauthenticated_until: '2026-10-15T09:15:00.000Z' } })
    })
    api.on('POST', /^\/api\/v1\/payments\/([^/]+)\/reversals$/, (request) => {
      if (!reauthenticated) {
        return apiError(
          423,
          'REAUTHENTICATION_REQUIRED',
          'Confirmez votre mot de passe pour cette opération sensible.',
        )
      }
      reversed = Number(request.body?.['amount'] ?? 0)
      return json(201, {
        data: {
          reversal_id: request.body?.['reversal_id'],
          payment_id: PAYMENT_ID,
          invoice_id: INVOICE_ID,
          amount: reversed,
          recorded_at: '2026-10-15T09:00:00.000Z',
          ledger_version: 3,
          balance: paidInvoice().balance,
        },
      })
    })
    const { root, unmount } = await openApp(PAGE)
    await settle()

    await click(buttonByName(root, /Contre-passer le paiement TEST-REC-2026-000001/))
    await click(buttonByName(root, 'Enregistrer la contre-écriture'))
    expect(callsTo(api, 'POST', /\/reversals$/)).toHaveLength(0)
    expect(textOf(root)).toContain('Le montant est obligatoire')

    await typeInto(root, `contre-ecriture-montant-${PAYMENT_ID}`, '10 000')
    await typeInto(root, `contre-ecriture-motif-${PAYMENT_ID}`, 'Saisi deux fois')
    await click(buttonByName(root, 'Enregistrer la contre-écriture'))
    await settle()
    expect(callsTo(api, 'POST', /\/reversals$/)).toHaveLength(1)
    expect(textOf(root)).toContain('Confirmer votre mot de passe')

    await type(root, 'Mot de passe', 'MotDePasseFictif2026')
    await click(buttonByName(root, 'Confirmer et reprendre'))
    await settle()

    const calls = callsTo(api, 'POST', /\/reversals$/)
    expect(calls).toHaveLength(2)
    expect(calls[1]?.headers['Idempotency-Key']).toBe(calls[0]?.headers['Idempotency-Key'])
    expect(calls[1]?.body).toMatchObject({
      expected_version: 2,
      invoice_id: INVOICE_ID,
      payment_id: PAYMENT_ID,
      amount: 10000,
      reason: 'Saisi deux fois',
    })
    expect(textOf(root)).toContain('Correction enregistrée')
    expect(textOf(root)).toContain('Contre-écritures')
    expect(forbiddenWordsIn(root)).toEqual([])
    unmount()
  })

  it('sans la permission, le compte demande l’autorisation au lieu d’exécuter', async () => {
    const api = server([...CASHIER, 'approval.request'], () => paidInvoice())
    const { root, unmount } = await openApp(PAGE)
    await settle()

    await click(buttonByName(root, /Contre-passer le paiement/))
    await typeInto(root, `contre-ecriture-montant-${PAYMENT_ID}`, '10 000')
    await typeInto(root, `contre-ecriture-motif-${PAYMENT_ID}`, 'Saisi deux fois')
    await click(buttonByName(root, 'Demander l’autorisation'))
    await settle()

    expect(callsTo(api, 'POST', /\/reversals$/)).toHaveLength(0)
    expect(textOf(root)).toContain('Contre-écriture')
    unmount()
  })
})

describe('avoirs et remboursements listés', () => {
  it('l’avoir et le remboursement apparaissent avec leurs PDF, sans mot interdit', async () => {
    server([...MANAGER, 'credit_note.issue', 'refund.execute'], () =>
      paidInvoice({
        ledger_version: 4,
        balance: {
          total_gross: 80000,
          paid_net: 80000,
          credited: 30000,
          refunded: 5000,
          balance_due: -25000,
          credit_available: 25000,
          settlement: 'IN_CREDIT',
        },
        credit_notes: [
          {
            id: CREDIT_NOTE_ID,
            number: 'TEST-AV-2026-000001',
            status: 'ISSUED',
            amount_gross: 30000,
            issued_at: '2026-10-15T09:00:00.000Z',
            reason: 'Geste commercial',
            refunded_amount: 5000,
          },
        ],
        refunds: [
          {
            id: REFUND_ID,
            number: 'TEST-RMB-2026-000001',
            amount: 5000,
            method: 'CASH',
            effective_at: '2026-10-15T09:00:00.000Z',
            credit_note_id: CREDIT_NOTE_ID,
          },
        ],
      }),
    )
    const { root, unmount } = await openApp(PAGE)
    await settle()

    const text = textOf(root)
    expect(text).toContain('Avoir TEST-AV-2026-000001')
    expect(text).toContain('déjà remboursé')
    expect(text).toContain('Remboursement TEST-RMB-2026-000001')
    expect(buttonByName(root, 'PDF de l’avoir')).not.toBeNull()
    expect(buttonByName(root, 'PDF du remboursement')).not.toBeNull()
    // Texte visible court (« Rembourser »), nom accessible complet.
    expect(buttonByName(root, 'Rembourser')).not.toBeNull()
    expect(
      root.querySelector('button[aria-label^="Rembourser le crédit de l’avoir"]'),
    ).not.toBeNull()
    expect(forbiddenWordsIn(root)).toEqual([])
    unmount()
  })
})
