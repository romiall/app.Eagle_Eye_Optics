import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { resetNetworkForTests } from '@/app/network'
import { getConsultation } from '@/app/services/consultations'
import { useRead } from '@/app/services/reads'

import { consultation, CONSULTATION_ID } from '../consultations/support'
import { apiError, json } from '../patients/support'

// Lecture de la consultation en ligne (C01) : URL, fraîcheur, `live`, sens des erreurs. Une réponse qui
// n'a pas la forme d'une consultation n'est jamais présentée comme une consultation vide. Données
// FICTIVES.
afterEach(() => {
  vi.useRealTimers()
  resetNetworkForTests()
})

describe('getConsultation', () => {
  it('interroge GET /api/v1/consultations/{id} sans corps, identifiant encodé', async () => {
    const fetchMock = vi.fn(async () => json(200, { data: consultation() }))
    vi.stubGlobal('fetch', fetchMock)

    await getConsultation(CONSULTATION_ID)

    const call = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(call[0]).toBe(`/api/v1/consultations/${CONSULTATION_ID}`)
    expect(call[1].method).toBe('GET')
    expect(call[1].body).toBeUndefined()

    await getConsultation('a/b c')
    expect((fetchMock.mock.calls[1] as unknown as [string])[0]).toBe(
      '/api/v1/consultations/a%2Fb%20c',
    )
  })

  it('rend la consultation avec l’heure de cet appel et `live`, sans cache', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-22T09:42:00.000Z'))
    const data = consultation({ version: 5, status: 'IN_PROGRESS' })
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json(200, { data })),
    )

    const outcome = await getConsultation(CONSULTATION_ID)

    expect(outcome.data).toEqual(data)
    expect(outcome.live).toBe(true)
    expect(outcome.freshness).toEqual({ serverAsOf: '2026-10-22T09:42:00.000Z', complete: true })
  })

  it('sans réponse du serveur : NetworkUnavailable, donc « hors ligne », jamais une consultation vide', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('Failed to fetch')
      }),
    )
    await expect(getConsultation(CONSULTATION_ID)).rejects.toBeInstanceOf(NetworkUnavailable)

    const read = useRead(() => getConsultation(CONSULTATION_ID))
    await flushPromises()
    expect(read.state.value).toBe('offline')
    expect(read.data.value).toBeNull()
  })

  it('403 et 404 du serveur sont propagés tels quels, jamais convertis en vide', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => apiError(404, 'RESOURCE_NOT_FOUND', 'Introuvable.')),
    )
    await expect(getConsultation(CONSULTATION_ID)).rejects.toMatchObject({ status: 404 })
    const missing = useRead(() => getConsultation(CONSULTATION_ID))
    await flushPromises()
    expect(missing.state.value).toBe('error')
    expect(missing.error.value?.status).toBe(404)

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => apiError(403, 'ACTION_FORBIDDEN', 'Action non autorisée.')),
    )
    const forbidden = useRead(() => getConsultation(CONSULTATION_ID))
    await flushPromises()
    expect(forbidden.state.value).toBe('forbidden')
  })

  it.each([
    ['sans données', { data: null }],
    [
      'sans étapes',
      { data: { id: CONSULTATION_ID, patient_id: 'P', version: 1, status: 'DRAFT' } },
    ],
    ['étape manquante', { data: { ...consultation(), steps: { ANAMNESIS: {} } } }],
    ['version non entière', { data: { ...consultation(), version: 1.5 } }],
  ])('une réponse %s est une erreur du serveur, pas une consultation vide', async (_name, body) => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json(200, body)),
    )
    const error = await getConsultation(CONSULTATION_ID).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).fromApplication).toBe(false)
    const read = useRead(() => getConsultation(CONSULTATION_ID))
    await flushPromises()
    expect(read.state.value).toBe('offline')
  })
})
