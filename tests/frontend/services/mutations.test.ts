import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { resetNetworkForTests } from '@/app/network'
import { commandContract, commandNames, commandPath } from '@/app/services/commandIndex'
import {
  createMutationDispatcher,
  type LocalCommandEngine,
  type MutationRequest,
} from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'

// Charges FICTIVES (identifiants ULID d'exemple, montants sans valeur réelle).
const INVOICE = '01J9ZXK4M2Q8R7S6T5V4W3X001'
const PAYMENT = '01J9ZXK4M2Q8R7S6T5V4W3P001'
const KEY = '01J9ZXK4M2Q8R7S6T5V4W3K001'

const PAYMENT_REQUEST: MutationRequest = {
  name: 'RegisterPayment',
  path: { id: INVOICE },
  payload: { invoice_id: INVOICE, payment_id: PAYMENT, amount: 20_000, currency: 'XAF' },
  expectedVersion: 2,
  idempotencyKey: KEY,
}

function respond(status: number, body: unknown, contentType = 'application/json'): Response {
  return new Response(typeof body === 'string' ? body : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': contentType },
  })
}

function apiError(status: number, code: string, details: Record<string, unknown> = {}) {
  return respond(status, {
    error: { code, message: `Refus ${code}.`, details },
    correlation_id: 'corr-test-0100',
  })
}

function stubFetch(...responses: (Response | 'network')[]) {
  const fetchMock = vi.fn<(input: RequestInfo | URL, init?: RequestInit) => Promise<Response>>(
    async () => {
      const next = responses.shift()
      if (next === undefined || next === 'network') throw new TypeError('Failed to fetch')
      return next
    },
  )
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function sentHeaders(fetchMock: ReturnType<typeof stubFetch>, call = 0): Record<string, string> {
  const init = fetchMock.mock.calls[call]?.[1] as RequestInit | undefined
  return (init?.headers ?? {}) as Record<string, string>
}

afterEach(() => resetNetworkForTests())

describe('index des commandes lu depuis le contrat F06', () => {
  it('contient les 26 commandes avec route directe, permission et version attendue', () => {
    expect(commandNames()).toHaveLength(26)
    expect(commandContract('RegisterPayment')).toMatchObject({
      method: 'POST',
      path: '/api/v1/invoices/{id}/payments',
      permission: 'payment.collect',
      expectedVersion: 'ledger',
      numberType: 'RECEIPT',
    })
    expect(commandContract('CreatePatient').expectedVersion).toBe('none')
  })

  it('construit le chemin, encode les paramètres et refuse un paramètre manquant', () => {
    const step = commandContract('SaveClinicalStep')
    expect(commandPath(step, { id: 'ABC', step: 'acuité/œil' })).toBe(
      '/api/v1/consultations/ABC/steps/acuit%C3%A9%2F%C5%93il',
    )
    expect(() => commandPath(step, { id: 'ABC' })).toThrow(/step/)
  })
})

describe('répartiteur de mutations', () => {
  it('route directe : en-tête Idempotency-Key, charge et version attendue dans le corps', async () => {
    const fetchMock = stubFetch(respond(201, { data: { payment_id: PAYMENT } }))
    const dispatcher = createMutationDispatcher({ now: () => new Date('2026-10-15T09:42:00Z') })

    const outcome = await dispatcher.dispatch(PAYMENT_REQUEST)

    expect(outcome).toEqual({
      kind: 'synced',
      at: '2026-10-15T09:42:00.000Z',
      result: { data: { payment_id: PAYMENT } },
    })
    expect(fetchMock.mock.calls[0]?.[0]).toBe(`/api/v1/invoices/${INVOICE}/payments`)
    expect(sentHeaders(fetchMock)['Idempotency-Key']).toBe(KEY)
    const body = JSON.parse(String((fetchMock.mock.calls[0]?.[1] as RequestInit).body)) as Record<
      string,
      unknown
    >
    expect(body).toEqual({ ...PAYMENT_REQUEST.payload, expected_version: 2 })
  })

  it('un poste préparé enregistre localement les commandes de son journal, même en ligne', async () => {
    const fetchMock = stubFetch()
    const engine: LocalCommandEngine = {
      handles: (name) => name === 'RegisterPayment',
      commit: vi.fn(async () => ({ recordedAt: '2026-10-15T09:42:00.000Z' })),
    }
    const outcome = await createMutationDispatcher({ localEngine: engine }).dispatch(
      PAYMENT_REQUEST,
    )

    expect(outcome).toEqual({ kind: 'saved-local', at: '2026-10-15T09:42:00.000Z' })
    expect(engine.commit).toHaveBeenCalledWith(PAYMENT_REQUEST)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it.each([
    [apiError(401, 'AUTH_REQUIRED'), 'session-expired'],
    [apiError(419, 'SESSION_CONTEXT_EXPIRED'), 'session-expired'],
    [apiError(409, 'VERSION_CONFLICT'), 'conflict'],
    [apiError(409, 'IDEMPOTENCY_PAYLOAD_MISMATCH'), 'conflict'],
    [apiError(409, 'PAYMENT_EXCEEDS_BALANCE', { amount_due: 5_000 }), 'refused'],
    [apiError(422, 'VALIDATION_FAILED'), 'refused'],
    [apiError(403, 'ACTION_FORBIDDEN'), 'refused'],
    [apiError(503, 'SERVER_BUSY'), 'retryable'],
    [respond(503, '<html>Maintenance</html>', 'text/html'), 'retryable'],
  ] as const)('classe la réponse %# en issue « %s »', async (response, kind) => {
    stubFetch(response)
    const outcome = await createMutationDispatcher().dispatch(PAYMENT_REQUEST)
    expect(outcome.kind).toBe(kind)
  })

  it('garde les valeurs actualisées d’un refus métier (reste dû)', async () => {
    stubFetch(apiError(409, 'PAYMENT_EXCEEDS_BALANCE', { amount_due: 5_000 }))
    const outcome = await createMutationDispatcher().dispatch(PAYMENT_REQUEST)
    expect(outcome.kind === 'refused' ? outcome.error.details : null).toEqual({ amount_due: 5_000 })
  })

  it('sans réponse et sans journal local : « indisponible », rien n’est conclu', async () => {
    stubFetch('network')
    expect(await createMutationDispatcher().dispatch(PAYMENT_REQUEST)).toEqual({
      kind: 'unavailable',
    })
  })

  it('refuse une clé d’idempotence invalide ou une version attendue manquante', async () => {
    const dispatcher = createMutationDispatcher()
    await expect(
      dispatcher.dispatch({ ...PAYMENT_REQUEST, idempotencyKey: 'court' }),
    ).rejects.toThrow()
    const withoutVersion: MutationRequest = {
      name: PAYMENT_REQUEST.name,
      payload: PAYMENT_REQUEST.payload,
      idempotencyKey: KEY,
    }
    await expect(dispatcher.dispatch(withoutVersion)).rejects.toThrow(/Version attendue/)
  })
})

describe('useMutation : saisie préservée, même clé pour une même intention', () => {
  const intent = {
    name: 'RegisterPayment' as const,
    path: { id: INVOICE },
    payload: { invoice_id: INVOICE, payment_id: PAYMENT, amount: 20_000, currency: 'XAF' },
    expectedVersion: 2,
  }

  it('reprend une intention sans réponse avec la même clé (aucun doublon possible)', async () => {
    const fetchMock = stubFetch('network', respond(201, { data: {} }))
    const mutation = useMutation(createMutationDispatcher())

    expect((await mutation.submit(intent)).kind).toBe('unavailable')
    expect(mutation.state.value).toBe('unavailable')
    expect(mutation.saveState.value).toBe('failed')
    expect(mutation.failureMessage.value).toContain('n’est pas confirmé')

    expect((await mutation.retry()).kind).toBe('synced')
    expect(sentHeaders(fetchMock, 1)['Idempotency-Key']).toBe(
      sentHeaders(fetchMock, 0)['Idempotency-Key'],
    )
    expect(mutation.saveState.value).toBe('synced')
  })

  it('une charge modifiée est une nouvelle intention, avec une nouvelle clé', async () => {
    const fetchMock = stubFetch(apiError(422, 'VALIDATION_FAILED'), respond(201, { data: {} }))
    const mutation = useMutation(createMutationDispatcher())

    await mutation.submit(intent)
    await mutation.submit({ ...intent, payload: { ...intent.payload, amount: 15_000 } })

    expect(sentHeaders(fetchMock, 1)['Idempotency-Key']).not.toBe(
      sentHeaders(fetchMock, 0)['Idempotency-Key'],
    )
  })

  it('après un enregistrement réussi, l’envoi suivant est une nouvelle intention', async () => {
    const fetchMock = stubFetch(respond(201, { data: {} }), respond(201, { data: {} }))
    const mutation = useMutation(createMutationDispatcher())

    await mutation.submit(intent)
    await mutation.submit(intent)

    expect(sentHeaders(fetchMock, 1)['Idempotency-Key']).not.toBe(
      sentHeaders(fetchMock, 0)['Idempotency-Key'],
    )
  })

  it('un double clic n’envoie qu’une fois', async () => {
    const fetchMock = stubFetch(respond(201, { data: {} }))
    const mutation = useMutation(createMutationDispatcher())

    const [first, second] = [mutation.submit(intent), mutation.submit(intent)]
    expect(mutation.state.value).toBe('saving')
    await flushPromises()

    expect(await first).toEqual(await second)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('session expirée : saisie conservée, reprise après reconnexion avec la même clé', async () => {
    const fetchMock = stubFetch(apiError(401, 'AUTH_REQUIRED'), respond(201, { data: {} }))
    const mutation = useMutation(createMutationDispatcher())

    await mutation.submit(intent)
    expect(mutation.state.value).toBe('session-expired')
    expect(mutation.failureMessage.value).toContain('vos saisies sont conservées')

    await mutation.retry()
    expect(mutation.state.value).toBe('synced')
    expect(sentHeaders(fetchMock, 1)['Idempotency-Key']).toBe(
      sentHeaders(fetchMock, 0)['Idempotency-Key'],
    )
  })

  it('conflit de version : message qui garde la saisie, aucune nouvelle tentative automatique', async () => {
    const fetchMock = stubFetch(apiError(409, 'VERSION_CONFLICT', { current_version: 3 }))
    const mutation = useMutation(createMutationDispatcher())

    await mutation.submit(intent)

    expect(mutation.state.value).toBe('conflict')
    expect(mutation.error.value?.details).toEqual({ current_version: 3 })
    expect(mutation.failureMessage.value).toContain('vos saisies sont conservées')
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('enregistrement local : « Enregistré sur ce poste » avec son instant, jamais « synchronisé »', async () => {
    const engine: LocalCommandEngine = {
      handles: () => true,
      commit: async () => ({ recordedAt: '2026-10-15T09:42:00.000Z' }),
    }
    const mutation = useMutation(createMutationDispatcher({ localEngine: engine }))

    await mutation.submit(intent)

    expect(mutation.saveState.value).toBe('saved-local')
    expect(mutation.recordedAt.value).toBe('2026-10-15T09:42:00.000Z')
  })
})
