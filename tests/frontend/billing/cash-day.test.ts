import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { CashDayReport } from '@/domain/contracts/generated'

import {
  CASHIER,
  callsTo,
  forbiddenWordsIn,
  json,
  MANAGER,
  openApp,
  openSession,
  resetSales,
  stubSalesApi,
  textOf,
  typeInto,
} from './support'

// Caisse du jour (R01 minimal), EN LIGNE : encaissements nets par mode, sessions du jour avec relevé,
// ventes émises et reste à encaisser pour la gérante seulement ; une caissière ne voit que ses
// encaissements (fichier 09). Jamais « payé » ; une lecture qui échoue est « indisponible ». FICTIF.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetSales()
})

const PAGE = '/ventes/caisse-du-jour'

function report(overrides: Partial<CashDayReport> = {}): CashDayReport {
  return {
    date: '2026-10-15',
    scope: 'CLINIC',
    collections: [
      { method: 'CASH', payments_count: 1, payments_amount: 30000, reversals_amount: 0, refunds_amount: 0, net_amount: 30000 },
      { method: 'MTN_MOMO', payments_count: 1, payments_amount: 20000, reversals_amount: 0, refunds_amount: 0, net_amount: 20000 },
    ],
    net_total: 50000,
    sessions: [
      {
        ...openSession(),
        cashier_name: 'Caisse (démonstration)',
        closed_by_name: null,
        register_code: 'CAISSE-PRINCIPALE',
        register_name: 'Caisse principale',
      },
    ],
    issued: { count: 2, total_gross: 160000 },
    credited: { count: 0, amount_gross: 0 },
    outstanding: { invoices_count: 1, amount_due: 30000, refundable_credit: 0 },
    generated_at: '2026-10-15T09:00:00.000Z',
    ...overrides,
  }
}

describe('caisse du jour', () => {
  it('la gérante lit les encaissements par mode, les ventes émises et le reste à encaisser', async () => {
    const api = stubSalesApi({ permissions: [...MANAGER, 'finance.global.read'] })
    api.on('GET', /^\/api\/v1\/reports\/cash-day$/, () => json(200, { data: report() }))
    const { root, unmount } = await openApp(PAGE)

    const text = textOf(root)
    expect(text).toContain('Caisse du jour')
    expect(text).toContain('Encaissements nets par mode')
    expect(text).toContain('Total net encaissé')
    expect(text).toContain('Factures émises ce jour')
    expect(text).toContain('Reste à encaisser (factures ouvertes)')
    expect(text).toContain('Caisse (démonstration)')
    expect(root.querySelector('[data-montant="total-jour"]')?.textContent?.replace(/\s/g, ' ')).toContain('50 000')
    expect(forbiddenWordsIn(root)).toEqual([])

    unmount()
  })

  it('une caissière ne voit que ses encaissements : ni ventes du cabinet ni créances', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    api.on('GET', /^\/api\/v1\/reports\/cash-day$/, () =>
      json(200, { data: report({ scope: 'OWN', issued: null, credited: null, outstanding: null }) }),
    )
    const { root, unmount } = await openApp(PAGE)

    const text = textOf(root)
    expect(text).toContain('Vos encaissements et vos sessions seulement')
    expect(text).not.toContain('Factures émises ce jour')
    expect(text).not.toContain('Reste à encaisser')

    unmount()
  })

  it('un autre jour est demandé au serveur ; un jour sans encaissement le dit', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    api.on('GET', /^\/api\/v1\/reports\/cash-day$/, (request) =>
      json(200, {
        data:
          request.query.get('date') === '2026-10-14'
            ? report({ date: '2026-10-14', scope: 'OWN', collections: [], net_total: 0, sessions: [], issued: null, credited: null, outstanding: null })
            : report({ scope: 'OWN', issued: null, credited: null, outstanding: null }),
      }),
    )
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'caisse-jour-date', '14/10/2026')
    root.querySelector('form[role="search"]')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await vi.waitFor(() => expect(textOf(root)).toContain('Aucun encaissement ce jour-là'))
    const calls = callsTo(api, 'GET', /^\/api\/v1\/reports\/cash-day$/)
    expect(calls.at(-1)?.query.get('date')).toBe('2026-10-14')

    unmount()
  })

  it('serveur injoignable : « indisponible », jamais « aucun encaissement »', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    api.on('GET', /^\/api\/v1\/reports\/cash-day$/, () => 'network')
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).toContain('Serveur indisponible')
    expect(textOf(root)).not.toContain('Aucun encaissement')

    unmount()
  })

  it('sans droit de caisse ni de finance : accès refusé ; module fermé : introuvable', async () => {
    stubSalesApi({ permissions: ['patient.read', 'visit.register'] })
    const refused = await openApp(PAGE)
    expect(refused.router.currentRoute.value.name).toBe('acces-refuse')
    refused.unmount()
    resetSales()

    const api = stubSalesApi({ permissions: CASHIER, modules: ['patients', 'today'] })
    const closed = await openApp(PAGE)
    expect(closed.router.currentRoute.value.name).toBe('introuvable')
    expect(callsTo(api, 'GET', /cash-day/)).toHaveLength(0)
    closed.unmount()
  })
})
