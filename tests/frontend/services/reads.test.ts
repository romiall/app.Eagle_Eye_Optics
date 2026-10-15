import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { type ReadOutcome, useRead } from '@/app/services/reads'

const FRESH = { serverAsOf: '2026-10-15T09:42:00.000Z', complete: true }

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((ok, ko) => {
    resolve = ok
    reject = ko
  })
  return { promise, resolve, reject }
}

describe('interface de lecture commune (états d’écran du fichier 04)', () => {
  it('rend la donnée avec sa fraîcheur', async () => {
    const read = useRead(async () => ({ data: ['dossier'], freshness: FRESH }))
    expect(read.state.value).toBe('loading')
    await flushPromises()

    expect(read.state.value).toBe('ready')
    expect(read.data.value).toEqual(['dossier'])
    expect(read.freshness.value).toEqual(FRESH)
  })

  it('distingue une liste vide d’un serveur injoignable (jamais « aucun dossier » hors ligne)', async () => {
    const empty = useRead(async () => ({ data: [] as string[], freshness: FRESH }), {
      isEmpty: (data) => data.length === 0,
    })
    const offline = useRead<string[]>(async () => {
      throw new NetworkUnavailable()
    })
    await flushPromises()

    expect(empty.state.value).toBe('empty')
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
    [401, 'session-expired'],
    [419, 'session-expired'],
    [403, 'forbidden'],
    [500, 'error'],
  ] as const)('traduit un refus %s en état « %s »', async (status, state) => {
    const read = useRead<string>(async () => {
      throw new ApiError(status, 'HTTP_ERROR', 'Refus.')
    })
    await flushPromises()
    expect(read.state.value).toBe(state)
    expect(read.error.value?.status).toBe(status)
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
})
