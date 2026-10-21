import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { type ReadOutcome, useRead } from '@/app/services/reads'

const FRESH = { serverAsOf: '2026-10-15T09:42:00.000Z', complete: true }
const LOCAL_ONLY = { serverAsOf: null, complete: false }
const PARTIAL = { serverAsOf: '2026-10-15T09:42:00.000Z', complete: false }

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((ok, ko) => {
    resolve = ok
    reject = ko
  })
  return { promise, resolve, reject }
}

const isEmpty = (data: string[]): boolean => data.length === 0

describe('interface de lecture commune (états d’écran du fichier 04)', () => {
  it('rend la donnée avec sa fraîcheur', async () => {
    const read = useRead(async () => ({ data: ['dossier'], freshness: FRESH }))
    expect(read.state.value).toBe('loading')
    await flushPromises()

    expect(read.state.value).toBe('ready')
    expect(read.data.value).toEqual(['dossier'])
    expect(read.freshness.value).toEqual(FRESH)
  })

  it('« aucun résultat » seulement quand le serveur a répondu de façon complète', async () => {
    const certain = useRead(async () => ({ data: [] as string[], freshness: FRESH }), { isEmpty })
    await flushPromises()
    expect(certain.state.value).toBe('empty')
  })

  it.each([
    ['données du poste seulement', LOCAL_ONLY],
    ['liste du serveur tronquée ou partielle', PARTIAL],
  ])(
    'un résultat vide (%s) n’est jamais « aucun dossier » : recherche serveur indisponible',
    async (_name, freshness) => {
      const read = useRead(async () => ({ data: [] as string[], freshness }), { isEmpty })
      await flushPromises()

      expect(read.state.value).toBe('offline')
      expect(read.data.value).toEqual([])
    },
  )

  it('un résultat non vide des données du poste est affiché, avec une fraîcheur qui le dit', async () => {
    const read = useRead(async () => ({ data: ['fiche préparée'], freshness: LOCAL_ONLY }), {
      isEmpty,
    })
    await flushPromises()

    expect(read.state.value).toBe('ready')
    expect(read.freshness.value).toEqual(LOCAL_ONLY)
  })

  it('serveur injoignable : « hors ligne », jamais « aucun dossier »', async () => {
    const offline = useRead<string[]>(async () => {
      throw new NetworkUnavailable()
    })
    await flushPromises()

    expect(offline.state.value).toBe('offline')
    expect(offline.data.value).toBeNull()
  })

  it('garde la donnée affichée quand une relecture échoue faute de réseau', async () => {
    let fail = false
    const read = useRead(async () => {
      if (fail) throw new NetworkUnavailable()
      return { data: 'valeur', freshness: FRESH }
    })
    await flushPromises()
    fail = true
    await read.reload()

    expect(read.state.value).toBe('offline')
    expect(read.data.value).toBe('valeur')
  })

  it.each([
    [401, true, 'session-expired'],
    [419, true, 'session-expired'],
    [403, true, 'forbidden'],
    [404, true, 'error'],
    [422, true, 'error'],
    [500, true, 'offline'],
    [503, true, 'offline'],
    [502, false, 'offline'],
    [200, false, 'offline'],
  ] as const)(
    'traduit un refus %s (application : %s) en état « %s »',
    async (status, fromApplication, state) => {
      const read = useRead<string>(async () => {
        throw new ApiError(status, 'HTTP_ERROR', 'Refus.', {}, null, {}, null, fromApplication)
      })
      await flushPromises()
      expect(read.state.value).toBe(state)
      expect(read.error.value?.status).toBe(status)
    },
  )

  it('un défaut du chargeur n’enferme pas l’écran en « chargement » ni ne rejette une promesse', async () => {
    const read = useRead<string[]>(async () => {
      throw new Error('Instantané serveur en double : P1.')
    })
    await flushPromises()

    expect(read.state.value).toBe('error')
    expect(read.failure.value).toBeInstanceOf(Error)
    expect(read.error.value).toBeNull()
  })

  it('ignore une réponse arrivée après une relecture plus récente', async () => {
    const first = deferred<ReadOutcome<string>>()
    const second = deferred<ReadOutcome<string>>()
    const pending = [first, second]
    const read = useRead(() => (pending.shift() ?? second).promise)
    const reload = read.reload()

    second.resolve({ data: 'récente', freshness: FRESH })
    await reload
    first.resolve({ data: 'ancienne', freshness: FRESH })
    await flushPromises()

    expect(read.data.value).toBe('récente')
  })

  it('ignore aussi l’échec d’une lecture obsolète (il n’écrase ni l’état ni la donnée)', async () => {
    const first = deferred<ReadOutcome<string>>()
    const second = deferred<ReadOutcome<string>>()
    const pending = [first, second]
    const read = useRead(() => (pending.shift() ?? second).promise)
    const reload = read.reload()

    second.resolve({ data: 'récente', freshness: FRESH })
    await reload
    first.reject(new NetworkUnavailable())
    await flushPromises()

    expect(read.state.value).toBe('ready')
    expect(read.data.value).toBe('récente')

    // Même un défaut inattendu d'une lecture obsolète est ignoré.
    const third = deferred<ReadOutcome<string>>()
    const fourth = deferred<ReadOutcome<string>>()
    const next = [third, fourth]
    const again = useRead(() => (next.shift() ?? fourth).promise)
    const secondReload = again.reload()
    fourth.resolve({ data: 'dernière', freshness: FRESH })
    await secondReload
    third.reject(new Error('défaut tardif'))
    await flushPromises()
    expect(again.state.value).toBe('ready')
    expect(again.failure.value).toBeNull()
  })
})
