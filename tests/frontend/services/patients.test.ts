import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { resetNetworkForTests } from '@/app/network'
import { getPatient, searchPatients } from '@/app/services/patients'
import { useRead } from '@/app/services/reads'
import type { Patient, PatientPage } from '@/domain/contracts/generated'

// Lectures patient du mode en ligne (P01, P02) : URL, fraîcheur, `live`, et le sens des erreurs.
// Données FICTIVES.
const ID = '01J9ZXK4M2Q8R7S6T5V4W30001'

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const PAGE: PatientPage = {
  data: [
    {
      id: ID,
      record_number: 'EE-2026-0001',
      last_name: 'PATIENT FICTIF',
      first_names: 'Élodie',
      sex: 'F',
      birth_date: null,
      phone_primary: null,
      archived: false,
      merged_into_patient_id: null,
      version: 1,
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
}

const EMPTY: PatientPage = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
}

afterEach(() => {
  vi.useRealTimers()
  resetNetworkForTests()
})

describe('searchPatients', () => {
  it('interroge GET /api/v1/patients avec le texte et la page, encodés', async () => {
    const fetchMock = vi.fn(async () => json(200, PAGE))
    vi.stubGlobal('fetch', fetchMock)

    await searchPatients('  Éb  Ngono+237 ', 2)

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    const parsed = new URL(url, 'https://cabinet.test')
    expect(parsed.pathname).toBe('/api/v1/patients')
    expect(parsed.searchParams.get('query')).toBe('Éb Ngono+237')
    expect(parsed.searchParams.get('page')).toBe('2')
    expect(init.method).toBe('GET')
    expect(init.body).toBeUndefined()
    // Le « + » d’un indicatif est encodé : le serveur ne le lit pas comme une espace.
    expect(url).toContain('%2B237')
  })

  it('rend la réponse avec l’heure de cet appel et `live`, sans cache', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-15T09:42:00.000Z'))
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json(200, PAGE)),
    )

    const outcome = await searchPatients('fictif')

    expect(outcome.data).toEqual(PAGE)
    expect(outcome.live).toBe(true)
    expect(outcome.freshness).toEqual({ serverAsOf: '2026-10-15T09:42:00.000Z', complete: true })
  })

  it('une liste vide du serveur est « aucun résultat » pour useRead (réponse de cet appel)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json(200, EMPTY)),
    )
    const read = useRead(() => searchPatients('inconnu'), {
      isEmpty: (page: PatientPage) => page.data.length === 0,
    })
    await flushPromises()

    expect(read.state.value).toBe('empty')
  })

  it('sans réponse du serveur : NetworkUnavailable, donc « hors ligne », jamais « aucun résultat »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('Failed to fetch')
      }),
    )

    await expect(searchPatients('fictif')).rejects.toBeInstanceOf(NetworkUnavailable)

    const read = useRead(() => searchPatients('fictif'), {
      isEmpty: (page: PatientPage) => page.data.length === 0,
    })
    await flushPromises()
    expect(read.state.value).toBe('offline')
  })

  it('n’envoie rien sous 2 caractères ni pour une page invalide', async () => {
    const fetchMock = vi.fn(async () => json(200, PAGE))
    vi.stubGlobal('fetch', fetchMock)

    await expect(searchPatients('a')).rejects.toThrow(RangeError)
    await expect(searchPatients('   ')).rejects.toThrow(RangeError)
    await expect(searchPatients('ab', 0)).rejects.toThrow(RangeError)
    await expect(searchPatients('ab', 1.5)).rejects.toThrow(RangeError)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('propage un refus du serveur avec ses erreurs par champ (422)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        json(422, {
          error: {
            code: 'VALIDATION_FAILED',
            message: 'La recherche demande 2 caractères.',
            fields: { query: ['Saisissez au moins 2 caractères.'] },
          },
          correlation_id: 'corr-test-0001',
        }),
      ),
    )

    const error = await searchPatients('ab').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).fields['query']).toEqual(['Saisissez au moins 2 caractères.'])
  })
})

describe('getPatient', () => {
  const PATIENT = { id: ID, record_number: 'EE-2026-0001' } as unknown as Patient

  it('lit GET /api/v1/patients/{id}, identifiant encodé, et rend la fiche', async () => {
    const fetchMock = vi.fn<typeof fetch>(async () => json(200, { data: PATIENT }))
    vi.stubGlobal('fetch', fetchMock)

    const outcome = await getPatient(ID)

    expect(String(fetchMock.mock.calls[0]?.[0])).toBe(`/api/v1/patients/${ID}`)
    expect(outcome.data.record_number).toBe('EE-2026-0001')
    expect(outcome.live).toBe(true)
    expect(outcome.freshness.complete).toBe(true)
    expect(outcome.freshness.serverAsOf).not.toBeNull()
  })

  it('encode un identifiant inattendu au lieu de modifier le chemin', async () => {
    const fetchMock = vi.fn<typeof fetch>(async () =>
      json(404, { error: { code: 'RESOURCE_NOT_FOUND', message: 'Introuvable.' } }),
    )
    vi.stubGlobal('fetch', fetchMock)

    await getPatient('../auth/me').catch(() => undefined)

    expect(String(fetchMock.mock.calls[0]?.[0])).toBe('/api/v1/patients/..%2Fauth%2Fme')
  })

  it('une fiche inconnue (404) est une erreur d’application, pas « aucun résultat »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        json(404, {
          error: { code: 'RESOURCE_NOT_FOUND', message: 'Ressource introuvable.' },
          correlation_id: 'corr-test-0002',
        }),
      ),
    )

    const read = useRead(() => getPatient(ID))
    await flushPromises()

    expect(read.state.value).toBe('error')
    expect(read.error.value?.status).toBe(404)
    expect(read.error.value?.code).toBe('RESOURCE_NOT_FOUND')
  })

  it('une erreur 500 ou une page d’hébergeur est « hors ligne », pas une fiche absente', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response('<html>503</html>', {
            status: 503,
            headers: { 'Content-Type': 'text/html' },
          }),
      ),
    )

    const read = useRead(() => getPatient(ID))
    await flushPromises()

    expect(read.state.value).toBe('offline')
  })
})
