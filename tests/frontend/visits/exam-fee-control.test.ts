import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { MeResponse } from '@/domain/contracts/generated'

import {
  buttonByName,
  click,
  FRONT_DESK,
  json,
  openApp,
  type Recorded,
  resetEverything,
  stubVisitsApi,
  textOf,
  type,
  VISIT_A,
  VISIT_B,
  VISIT_C,
  visit,
  visitPage,
} from './support'

// Contrôle RÉEL du paiement de l'examen dans la file du jour (P03 avec B04 et B06) : le marqueur reprend le
// statut du serveur (« à encaisser », « dispense demandée », « encaissés : reçu n° … », « dispense
// approuvée »), jamais « payé » ni « gratuit » ; l'accueil peut demander une dispense à la gérante pour une
// visite dont les frais sont à encaisser. Contrôle inactif : rien de nouveau n'apparaît. Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

const FORBIDDEN = /pay[ée]|sold[ée]|gratuit/i
const RECEPTION_WITH_REQUEST = [...FRONT_DESK, 'approval.request']

function billed(me: MeResponse | 'anonymous' | 'unreachable'): void {
  if (typeof me === 'object') me.data.clinic.exam_fee_control = 'BILLED'
}

function day() {
  return visitPage([
    visit({ id: VISIT_A, status: 'AWAITING_EXAM_FEE', exam_fee_control: 'UNPAID' }),
    visit({
      id: VISIT_B,
      occurred_at: '2026-10-15T08:30:00.000Z',
      status: 'READY_FOR_EXAM',
      exam_fee_control: 'PAID',
      exam_fee_receipt_number: 'TEST-REC-2026-000004',
    }),
    visit({
      id: VISIT_C,
      occurred_at: '2026-10-15T08:45:00.000Z',
      status: 'READY_FOR_EXAM',
      exam_fee_control: 'WAIVED',
    }),
  ])
}

function cards(root: HTMLElement): HTMLElement[] {
  return [...(root.querySelector('#file-cartes')?.querySelectorAll('li') ?? [])] as HTMLElement[]
}

describe('file du jour, contrôle du paiement de l’examen actif', () => {
  it('dit à encaisser, encaissés avec le numéro du reçu réel, ou dispense approuvée', async () => {
    const api = stubVisitsApi({
      permissions: FRONT_DESK,
      handlers: { list: () => json(200, day()) },
    })
    billed(api.me)
    const { root, unmount } = await openApp('/aujourdhui')

    const [unpaid, paid, waived] = cards(root).map(textOf)
    expect(unpaid).toContain('Frais d’examen à encaisser')
    expect(unpaid).toContain('En attente du paiement de l’examen')
    expect(paid).toContain('Frais d’examen encaissés : reçu n° TEST-REC-2026-000004')
    expect(waived).toContain('Dispense approuvée par la gérante')
    for (const text of [unpaid, paid, waived]) {
      expect(text).not.toMatch(FORBIDDEN)
      expect(text).not.toContain('non contrôlé')
    }
    expect(textOf(root)).toContain('Les frais d’examen sont contrôlés')
    expect(textOf(root)).not.toContain('n’est pas encore actif')
    unmount()
  })

  it('sans le droit de demander une approbation, aucune dispense n’est proposée', async () => {
    const api = stubVisitsApi({
      permissions: FRONT_DESK,
      handlers: { list: () => json(200, day()) },
    })
    billed(api.me)
    const { root, unmount } = await openApp('/aujourdhui')

    expect(buttonByName(root, /Demander une dispense/)).toBeNull()
    unmount()
  })

  it('l’accueil demande une dispense exacte pour la visite à encaisser, puis la file est relue', async () => {
    const posted: Recorded[] = []
    const api = stubVisitsApi({
      permissions: RECEPTION_WITH_REQUEST,
      handlers: {
        // Après la demande, le serveur dit « dispense demandée » pour cette visite.
        list: () =>
          json(
            200,
            posted.length === 0
              ? day()
              : visitPage([
                  visit({
                    id: VISIT_A,
                    status: 'AWAITING_EXAM_FEE',
                    exam_fee_control: 'WAIVER_REQUESTED',
                  }),
                ]),
          ),
      },
    })
    billed(api.me)
    const base = api.fetchMock
    const wrapped = vi.fn(async (input: unknown, init?: RequestInit): Promise<Response> => {
      const url = new URL(String(input), 'https://cabinet.test')
      if (url.pathname === '/api/v1/approvals' && init?.method === 'POST') {
        const body = JSON.parse(String(init.body)) as Record<string, unknown>
        posted.push({
          method: 'POST',
          path: url.pathname,
          query: url.searchParams,
          body,
          headers: (init.headers ?? {}) as Record<string, string>,
        })
        return json(201, {
          data: {
            id: body['approval_id'],
            kind: 'EXAM_FEE_WAIVER',
            action_code: 'exam_fee.collect',
            target_type: 'visits',
            target_id: VISIT_A,
            payload_hash: 'a'.repeat(64),
            subject: body['subject'],
            reason: body['reason'],
            status: 'REQUESTED',
            effective_status: 'REQUESTED',
            requested_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
            requested_by_name: 'Réception Fictive',
            requested_at: '2026-10-15T09:00:00.000Z',
            decided_by: null,
            decided_by_name: null,
            decided_at: null,
            decision_reason: null,
            expires_at: null,
            consumed_at: null,
            target: { patient: null, invoice_number: null, visit_occurred_at: null, payment: null },
          },
        })
      }
      return base(input, init)
    })
    vi.stubGlobal('fetch', wrapped)
    const { root, unmount } = await openApp('/aujourdhui')
    const lists = () => api.requests.filter((request) => request.path === '/api/v1/visits').length
    const before = lists()

    const buttons = [...root.querySelectorAll('button')].filter((button) =>
      /Demander une dispense/.test(button.textContent ?? ''),
    )
    // Une seule visite à encaisser : une action en carte et une dans le tableau.
    expect(buttons).toHaveLength(2)
    await click(buttons[0] ?? null)
    await type(root, 'Motif de la demande', 'Contrôle pris en charge par la gérante (fictif)')
    await click(buttonByName(root, 'Envoyer la demande à la gérante'))
    await flushPromises()

    expect(posted).toHaveLength(1)
    const request = posted[0]
    expect(request?.body).toMatchObject({
      expected_version: null,
      subject: { kind: 'EXAM_FEE_WAIVER', visit_id: VISIT_A, patient_id: visit().patient_id },
      reason: 'Contrôle pris en charge par la gérante (fictif)',
      expires_at: null,
    })
    expect(request?.headers['Idempotency-Key']).toMatch(/^[0-9A-Z]{26}$/)
    expect(textOf(root)).toContain('Demande envoyée à la gérante')
    expect(textOf(root)).toContain('elle n’est pas encore accordée')
    expect(lists()).toBeGreaterThan(before)
    expect(textOf(root)).toContain(
      'Dispense des frais d’examen demandée : en attente de la gérante',
    )
    expect(buttonByName(root, /Demander une dispense/)).toBeNull()
    unmount()
  })

  it('un motif vide n’envoie rien', async () => {
    const api = stubVisitsApi({
      permissions: RECEPTION_WITH_REQUEST,
      handlers: { list: () => json(200, day()) },
    })
    billed(api.me)
    const { root, unmount } = await openApp('/aujourdhui')

    await click(buttonByName(root, 'Demander une dispense des frais d’examen'))
    await click(buttonByName(root, 'Envoyer la demande à la gérante'))

    expect(api.requests.filter((request) => request.path === '/api/v1/approvals')).toHaveLength(0)
    expect(textOf(root)).toContain('Indiquez le motif de la demande.')
    unmount()
  })
})

describe('file du jour, contrôle inactif', () => {
  it('rien de nouveau : non contrôlé, aucune demande de dispense, même avec le droit de demander', async () => {
    stubVisitsApi({
      permissions: RECEPTION_WITH_REQUEST,
      handlers: { list: () => json(200, visitPage([visit()])) },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).toContain('Paiement de l’examen non contrôlé')
    expect(buttonByName(root, /Demander une dispense/)).toBeNull()
    unmount()
  })
})
