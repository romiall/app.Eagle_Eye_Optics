import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { Approval, ApprovalPage } from '@/domain/contracts/generated'

import {
  apiError,
  buttonByName,
  click,
  json,
  openApp,
  type Recorded,
  resetEverything,
  textOf,
  type,
} from '../patients/support'
import { ALL_TEST_MODULES, meResponse } from '../shell/server'

// Écran « Approbations » de la gérante (B06) : demandes en attente avec leur contenu exact vérifié, accord
// et refus motivé ; la décision exige la confirmation du mot de passe (423) puis reprend la MÊME commande
// avec la même clé d'idempotence ; la demandeuse ne décide jamais de sa propre demande. Données FICTIVES.
afterEach(() => {
  resetEverything()
})

const MANAGER = ['approval.decide', 'approval.request', 'users.manage', 'payment.reverse']
const ME_ID = '01J9ZXK4M2Q8R7S6T5V4W30002'
const APPROVAL_ID = '01J9ZXK4M2Q8R7S6T5V4W30038'

function approval(overrides: Partial<Approval> = {}): Approval {
  return {
    id: APPROVAL_ID,
    kind: 'PAYMENT_REVERSAL',
    action_code: 'payment.reverse',
    target_type: 'payments',
    target_id: '01J9ZXK4M2Q8R7S6T5V4W3002T',
    payload_hash: 'b'.repeat(64),
    subject: {
      kind: 'PAYMENT_REVERSAL',
      invoice_id: '01J9ZXK4M2Q8R7S6T5V4W30026',
      payment_id: '01J9ZXK4M2Q8R7S6T5V4W3002T',
      amount: 30000,
    },
    reason: 'Paiement saisi deux fois (fictif)',
    status: 'REQUESTED',
    effective_status: 'REQUESTED',
    requested_by: '01J9ZXK4M2Q8R7S6T5V4W30005',
    requested_by_name: 'Caisse Fictive',
    requested_at: '2026-10-15T09:00:00.000Z',
    decided_by: null,
    decided_by_name: null,
    decided_at: null,
    decision_reason: null,
    expires_at: null,
    consumed_at: null,
    target: {
      patient: {
        id: '01J9ZXK4M2Q8R7S6T5V4W30011',
        record_number: 'EE-2026-0001',
        last_name: 'PATIENT FICTIF',
        first_names: 'Élodie Marie',
      },
      invoice_number: 'TEST-FAC-2026-000001',
      visit_occurred_at: null,
      payment: { amount: 30000, method: 'MTN_MOMO', effective_at: '2026-10-15T08:30:00.000Z' },
    },
    ...overrides,
  }
}

function page(items: Approval[]): ApprovalPage {
  return { data: items, meta: { current_page: 1, last_page: 1, per_page: 25, total: items.length } }
}

interface Stub {
  requests: Recorded[]
  decisions: Recorded[]
  reauthentications: number
}

/** Serveur simulé : la première décision demande la réauthentification (423), la suivante réussit. */
function stub(items: Approval[], permissions: string[] = MANAGER): Stub {
  const state: Stub = { requests: [], decisions: [], reauthentications: 0 }
  const me = meResponse(permissions, 'Gérante Fictive', [...ALL_TEST_MODULES])
  me.data.id = ME_ID
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: unknown, init?: RequestInit): Promise<Response> => {
      const url = new URL(String(input), 'https://cabinet.test')
      const method = init?.method ?? 'GET'
      const body =
        typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : null
      const request: Recorded = {
        method,
        path: url.pathname,
        query: url.searchParams,
        body,
        headers: (init?.headers ?? {}) as Record<string, string>,
      }
      state.requests.push(request)
      if (url.pathname === '/up') return json(200, { status: 'ok' })
      if (url.pathname === '/api/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (url.pathname === '/api/v1/auth/me') return json(200, me)
      if (url.pathname === '/api/v1/approvals' && method === 'GET') return json(200, page(items))
      if (url.pathname === '/api/v1/auth/reauthenticate' && method === 'POST') {
        state.reauthentications += 1
        return json(200, { data: { reauthenticated_until: '2026-10-15T09:15:00.000Z' } })
      }
      if (url.pathname === `/api/v1/approvals/${APPROVAL_ID}/decision` && method === 'POST') {
        state.decisions.push(request)
        if (state.reauthentications === 0) {
          return apiError(
            423,
            'REAUTHENTICATION_REQUIRED',
            'Confirmez votre mot de passe pour cette opération sensible.',
          )
        }
        return json(200, {
          data: approval({
            status: body?.['decision'] as Approval['status'],
            effective_status: body?.['decision'] as Approval['effective_status'],
            decided_by: ME_ID,
            decided_by_name: 'Gérante Fictive',
            decided_at: '2026-10-15T09:05:00.000Z',
          }),
        })
      }
      return apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.')
    }),
  )
  return state
}

describe('écran Approbations de la gérante', () => {
  it('montre la nature, la cible, le contenu exact vérifié, le motif et la demandeuse', async () => {
    stub([approval()])
    const { root, unmount } = await openApp('/parametres/approbations')

    const text = textOf(root)
    expect(text).toContain('Contre-écriture d’un paiement')
    expect(text).toContain('PATIENT FICTIF Élodie Marie (dossier EE-2026-0001)')
    expect(text).toContain('TEST-FAC-2026-000001')
    expect(text).toContain('Montant contre-passé')
    expect(text).toContain('Paiement saisi deux fois (fictif)')
    expect(text).toContain('Caisse Fictive')
    expect(text).toContain('En attente de la gérante')
    expect(text).not.toMatch(/pay[ée]\b|gratuit/i)
    unmount()
  })

  it('l’accord demande le mot de passe (423) puis reprend la même décision avec la même clé', async () => {
    const state = stub([approval()])
    const { root, unmount } = await openApp('/parametres/approbations')

    await click(buttonByName(root, 'Accorder'))
    expect(state.decisions).toHaveLength(1)
    expect(textOf(root)).toContain('Confirmer votre mot de passe')

    await type(root, 'Mot de passe', 'MotDePasseFictif2026')
    await click(buttonByName(root, 'Confirmer et reprendre'))
    await flushPromises()

    expect(state.reauthentications).toBe(1)
    expect(state.decisions).toHaveLength(2)
    const [first, second] = state.decisions
    expect(second?.headers['Idempotency-Key']).toBe(first?.headers['Idempotency-Key'])
    expect(second?.body).toEqual({
      approval_id: APPROVAL_ID,
      decision: 'APPROVED',
      reason: null,
      expected_version: null,
    })
    expect(textOf(root)).toContain('Approbation accordée et enregistrée sur le serveur.')
    unmount()
  })

  it('un refus sans motif n’envoie rien', async () => {
    const state = stub([approval()])
    const { root, unmount } = await openApp('/parametres/approbations')

    await click(buttonByName(root, 'Refuser'))

    expect(state.decisions).toHaveLength(0)
    expect(textOf(root)).toContain('Indiquez le motif du refus')
    unmount()
  })

  it('la demandeuse ne décide jamais de sa propre demande, et une demande échue ne se décide plus', async () => {
    stub([
      approval({ requested_by: ME_ID }),
      approval({
        id: '01J9ZXK4M2Q8R7S6T5V4W30039',
        status: 'APPROVED',
        effective_status: 'EXPIRED',
      }),
    ])
    const { root, unmount } = await openApp('/parametres/approbations')

    expect(buttonByName(root, 'Accorder')).toBeNull()
    expect(textOf(root)).toContain('Vous avez fait cette demande')
    expect(textOf(root)).toContain('Échue')
    unmount()
  })

  it('un compte qui demande seulement voit ses demandes sans pouvoir décider', async () => {
    stub([approval({ requested_by: ME_ID })], ['approval.request'])
    const { root, unmount } = await openApp('/parametres/approbations')

    expect(textOf(root)).toContain('Mes demandes d’approbation')
    expect(buttonByName(root, 'Accorder')).toBeNull()
    expect(buttonByName(root, 'Refuser')).toBeNull()
    unmount()
  })
})
