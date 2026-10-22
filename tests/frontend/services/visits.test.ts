import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { resetNetworkForTests } from '@/app/network'
import { useRead } from '@/app/services/reads'
import { listDayVisits, listVisits, MAX_DAY_PAGES } from '@/app/services/visits'
import type { VisitPage } from '@/domain/contracts/generated'

import { json, visit, visitPage } from '../visits/support'

// Lectures de la file du jour en ligne (P03) : URL, fraîcheur, `live`, pagination et sens des erreurs.
// Données FICTIVES.
afterEach(() => {
  vi.useRealTimers()
  resetNetworkForTests()
})

function urlOf(call: unknown[]): URL {
  return new URL(String(call[0]), 'https://cabinet.test')
}

describe('listVisits', () => {
  it('interroge GET /api/v1/visits avec le jour civil et la page, sans corps', async () => {
    const fetchMock = vi.fn(async () => json(200, visitPage([visit()])))
    vi.stubGlobal('fetch', fetchMock)

    await listVisits('2026-10-15', 2)

    const call = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    const parsed = urlOf(call)
    expect(parsed.pathname).toBe('/api/v1/visits')
    expect(parsed.searchParams.get('date')).toBe('2026-10-15')
    expect(parsed.searchParams.get('page')).toBe('2')
    expect(call[1].method).toBe('GET')
    expect(call[1].body).toBeUndefined()
  })

  it('la première page est la page par défaut', async () => {
    const fetchMock = vi.fn(async () => json(200, visitPage([])))
    vi.stubGlobal('fetch', fetchMock)

    await listVisits('2026-10-15')

    expect(urlOf(fetchMock.mock.calls[0] as unknown[]).searchParams.get('page')).toBe('1')
  })

  it('rend la réponse avec l’heure de cet appel et `live`, sans cache', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-15T09:42:00.000Z'))
    const page = visitPage([visit()])
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json(200, page)),
    )

    const outcome = await listVisits('2026-10-15')

    expect(outcome.data).toEqual(page)
    expect(outcome.live).toBe(true)
    expect(outcome.freshness).toEqual({ serverAsOf: '2026-10-15T09:42:00.000Z', complete: true })
  })

  it('une liste vide du serveur est « aucune visite » pour useRead (réponse de cet appel)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json(200, visitPage([]))),
    )
    const read = useRead(() => listVisits('2026-10-15'), {
      isEmpty: (page: VisitPage) => page.data.length === 0,
    })
    await flushPromises()

    expect(read.state.value).toBe('empty')
  })

  it('sans réponse du serveur : NetworkUnavailable, donc « hors ligne », jamais « aucune visite »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('Failed to fetch')
      }),
    )

    await expect(listVisits('2026-10-15')).rejects.toBeInstanceOf(NetworkUnavailable)

    const read = useRead(() => listVisits('2026-10-15'), {
      isEmpty: (page: VisitPage) => page.data.length === 0,
    })
    await flushPromises()
    expect(read.state.value).toBe('offline')
  })

  it('une page d’erreur de l’hébergeur est « hors ligne », pas une file vide', async () => {
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
    const read = useRead(() => listVisits('2026-10-15'), {
      isEmpty: (page: VisitPage) => page.data.length === 0,
    })
    await flushPromises()

    expect(read.state.value).toBe('offline')
  })

  it('n’envoie rien pour un jour ou une page invalides', async () => {
    const fetchMock = vi.fn(async () => json(200, visitPage([])))
    vi.stubGlobal('fetch', fetchMock)

    await expect(listVisits('15/10/2026')).rejects.toThrow(RangeError)
    await expect(listVisits('2026-02-30')).rejects.toThrow(RangeError)
    await expect(listVisits('')).rejects.toThrow(RangeError)
    await expect(listVisits('2026-10-15', 0)).rejects.toThrow(RangeError)
    await expect(listVisits('2026-10-15', 1.5)).rejects.toThrow(RangeError)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('propage un refus du serveur avec ses erreurs par champ (422)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        json(422, {
          error: {
            code: 'VALIDATION_FAILED',
            message: 'Données invalides.',
            fields: { date: ['La date est invalide.'] },
          },
          correlation_id: 'corr-test-0001',
        }),
      ),
    )

    const error = await listVisits('2026-10-15').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).fields['date']).toEqual(['La date est invalide.'])
  })

  it('un 403 est un refus de droits, un 404 (module fermé) une erreur : jamais une liste vide', async () => {
    for (const [status, state] of [
      [403, 'forbidden'],
      [404, 'error'],
    ] as const) {
      vi.stubGlobal(
        'fetch',
        vi.fn(async () =>
          json(status, {
            error: { code: 'ACTION_FORBIDDEN', message: 'Refusé.' },
            correlation_id: 'corr-test-0002',
          }),
        ),
      )
      const read = useRead(() => listVisits('2026-10-15'), {
        isEmpty: (page: VisitPage) => page.data.length === 0,
      })
      await flushPromises()
      expect(read.state.value, String(status)).toBe(state)
    }
  })
})

describe('listDayVisits', () => {
  it('rend les visites d’une seule page avec une fraîcheur complète', async () => {
    const fetchMock = vi.fn(async () => json(200, visitPage([visit(), visit({ id: 'B' })])))
    vi.stubGlobal('fetch', fetchMock)

    const outcome = await listDayVisits('2026-10-15')

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(outcome.data.visits).toHaveLength(2)
    expect(outcome.data.truncated).toBe(false)
    expect(outcome.freshness.complete).toBe(true)
    expect(outcome.live).toBe(true)
  })

  it('parcourt toutes les pages de la file', async () => {
    const pages = [
      visitPage([visit({ id: 'A' })], { current_page: 1, last_page: 3, total: 3 }),
      visitPage([visit({ id: 'B' })], { current_page: 2, last_page: 3, total: 3 }),
      visitPage([visit({ id: 'C' })], { current_page: 3, last_page: 3, total: 3 }),
    ]
    const fetchMock = vi.fn(async (input: unknown) => {
      const page = Number(new URL(String(input), 'https://cabinet.test').searchParams.get('page'))
      return json(200, pages[page - 1])
    })
    vi.stubGlobal('fetch', fetchMock)

    const outcome = await listDayVisits('2026-10-15')

    expect(outcome.data.visits.map((entry) => entry.id)).toEqual(['A', 'B', 'C'])
    expect(
      fetchMock.mock.calls.map((call) => urlOf(call as unknown[]).searchParams.get('page')),
    ).toEqual(['1', '2', '3'])
    expect(outcome.data.truncated).toBe(false)
  })

  it('une file plus longue que la limite est dite partielle : l’absence d’une visite n’est pas établie', async () => {
    const fetchMock = vi.fn(async (input: unknown) => {
      const page = Number(new URL(String(input), 'https://cabinet.test').searchParams.get('page'))
      return json(
        200,
        visitPage([visit({ id: `P${page}` })], { current_page: page, last_page: 99, total: 99 }),
      )
    })
    vi.stubGlobal('fetch', fetchMock)

    const outcome = await listDayVisits('2026-10-15', 3)

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(outcome.data.visits).toHaveLength(3)
    expect(outcome.data.truncated).toBe(true)
    expect(outcome.freshness.complete).toBe(false)
    expect(MAX_DAY_PAGES).toBe(10)
  })

  it('un échec sur une page échoue toute la lecture : jamais une file partielle présentée comme complète', async () => {
    const fetchMock = vi.fn(async (input: unknown) => {
      const page = Number(new URL(String(input), 'https://cabinet.test').searchParams.get('page'))
      if (page === 2) throw new TypeError('Failed to fetch')
      return json(200, visitPage([visit()], { current_page: page, last_page: 3, total: 3 }))
    })
    vi.stubGlobal('fetch', fetchMock)

    await expect(listDayVisits('2026-10-15')).rejects.toBeInstanceOf(NetworkUnavailable)
  })

  it('refuse un jour ou une limite invalides sans rien envoyer', async () => {
    const fetchMock = vi.fn(async () => json(200, visitPage([])))
    vi.stubGlobal('fetch', fetchMock)

    await expect(listDayVisits('hier')).rejects.toThrow(RangeError)
    await expect(listDayVisits('2026-10-15', 0)).rejects.toThrow(RangeError)
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
