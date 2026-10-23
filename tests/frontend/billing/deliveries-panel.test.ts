import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Invoice, InvoiceDeliveries } from '@/domain/contracts/generated'

import {
  buttonByName,
  callsTo,
  CASHIER,
  click,
  forbiddenWordsIn,
  INVOICE_ID,
  issuedInvoice,
  json,
  openApp,
  openSession,
  resetSales,
  type SalesApi,
  settle,
  stubSalesApi,
  textOf,
} from './support'

// « Remettre au patient » sur la fiche d'une facture émise (I01), EN LIGNE : reste à remettre par ligne, remise
// partielle par défaut au reste, approbation exigée pour une facture avec un reste à encaisser, section absente quand
// la lecture n'existe pas. Jamais « payé » ni « soldé ». Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetSales()
})

const PAGE = `/ventes/factures/${INVOICE_ID}`
const LINE = '01J9ZXK4M2Q8R7S6T5V4W3001Y'
const ITEM = '01J9ZXK4M2Q8R7S6T5V4W3001Z'
const CASHIER_DELIVERY = [...CASHIER, 'delivery.confirm', 'approval.request']

function deliveries(
  remaining: string,
  amountDue: number,
  delivered: InvoiceDeliveries['deliveries'] = [],
): InvoiceDeliveries {
  return {
    invoice_id: INVOICE_ID,
    invoice_number: 'TEST-FAC-2026-000001',
    invoice_status: 'ISSUED',
    amount_due: amountDue,
    lines: [
      {
        invoice_line_id: LINE,
        position: 1,
        item_id: ITEM,
        description: 'Monture fictive',
        stock_mode: 'STOCKED',
        quantity_integer_only: true,
        quantity: '2.000',
        delivered: remaining === '2.000' ? '0.000' : '1.000',
        returned: '0.000',
        remaining,
        reserved_open: remaining,
        deliverable: remaining !== '0.000',
      },
    ],
    deliveries: delivered,
    returns: [],
  }
}

function server(
  invoice: Invoice,
  view: () => InvoiceDeliveries | null,
  permissions = CASHIER_DELIVERY,
): SalesApi {
  const api = stubSalesApi({ permissions })
  api.on('GET', /^\/api\/v1\/invoices\/([^/]+)$/, () => json(200, { data: invoice }))
  api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: openSession() }))
  api.on('GET', /^\/api\/v1\/invoices\/([^/]+)\/deliveries$/, () => {
    const current = view()
    return current === null
      ? json(404, {
          error: { code: 'RESOURCE_NOT_FOUND', message: 'Ressource introuvable.' },
          correlation_id: 'corr-test-0300',
        })
      : json(200, { data: current })
  })
  return api
}

describe('remise au patient', () => {
  it('remet le reste d’une facture réglée et relit la remise', async () => {
    let done = false
    const api = server(issuedInvoice(80000, { ledger_version: 2 }), () =>
      done
        ? deliveries('0.000', 0, [
            {
              id: '01J9ZXK4M2Q8R7S6T5V4W30042',
              status: 'CONFIRMED',
              delivered_at: '2026-10-15T09:00:00.000Z',
              delivered_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
              delivered_by_name: 'Caisse (démonstration)',
              credit_delivery_approval_id: null,
              lines: [
                {
                  id: '01J9ZXK4M2Q8R7S6T5V4W30043',
                  invoice_line_id: LINE,
                  item_id: ITEM,
                  description: 'Monture fictive',
                  quantity: '2.000',
                  returned: '0.000',
                  returnable: '2.000',
                  stock_position_id: '01J9ZXK4M2Q8R7S6T5V4W3002H',
                  stock_lot_id: null,
                  batch_number: null,
                  expires_on: null,
                },
              ],
            },
          ])
        : deliveries('2.000', 0),
    )
    api.on('POST', /^\/api\/v1\/invoices\/([^/]+)\/deliveries$/, (request) => {
      done = true
      return json(201, {
        data: {
          id: request.body?.['delivery_id'],
          invoice_id: INVOICE_ID,
          status: 'CONFIRMED',
          delivered_at: '2026-10-15T09:00:00.000Z',
          delivered_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
          credit_delivery_approval_id: null,
          lines: [],
        },
      })
    })
    const { root, unmount } = await openApp(PAGE)
    await settle()

    expect(textOf(root)).toContain('Remise au patient')
    expect(textOf(root)).toContain('reste à remettre 2')
    await click(buttonByName(root, 'Remettre au patient'))
    await click(buttonByName(root, 'Confirmer la remise'))
    await settle()

    const posts = callsTo(api, 'POST', /\/deliveries$/)
    expect(posts).toHaveLength(1)
    const body = posts[0]?.body ?? {}
    expect(body['expected_version']).toBeNull()
    expect(body['credit_delivery_approval_id']).toBeNull()
    expect(body['lines']).toEqual([
      expect.objectContaining({
        invoice_line_id: LINE,
        item_id: ITEM,
        quantity: '2.000',
        reservation_id: null,
      }),
    ])
    expect(posts[0]?.headers['Idempotency-Key']).toBeTruthy()
    expect(textOf(root)).toContain('Remise enregistrée sur le serveur')
    expect(textOf(root)).toContain('Tous les articles sont remis')
    expect(forbiddenWordsIn(root)).toEqual([])
    unmount()
  })

  it('une facture avec un reste à encaisser se DEMANDE d’abord à la gérante', async () => {
    const api = server(issuedInvoice(0), () => deliveries('2.000', 80000))
    const { root, unmount } = await openApp(PAGE)
    await settle()

    await click(buttonByName(root, 'Remettre au patient'))
    expect(textOf(root)).toContain('Remise avant règlement complet')
    await click(buttonByName(root, 'Demander l’autorisation de remise'))
    await settle()

    expect(callsTo(api, 'POST', /\/deliveries$/)).toHaveLength(0)
    expect(forbiddenWordsIn(root)).toEqual([])
    unmount()
  })

  it('sans lecture des remises côté serveur, la section n’est pas montrée', async () => {
    server(issuedInvoice(80000, { ledger_version: 2 }), () => null)
    const { root, unmount } = await openApp(PAGE)
    await settle()

    expect(root.querySelector('#titre-remises')).toBeNull()
    unmount()
  })
})
