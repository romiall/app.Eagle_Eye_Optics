import { ApiError, apiRequest, prepareSession } from '@/app/api'
import type { PullPage, PushRequest, PushResponse } from '@/domain/contracts/generated'
import { SyncRefused, type SyncTransport } from '@/offline/api'

/**
 * Transport HTTP de la synchronisation du poste (O05) : session et CSRF de l'application, enveloppe d'erreur du fichier
 * 08. Un refus explicite de l'application devient `SyncRefused` (code stable) ; une coupure, un délai dépassé ou une
 * page d'hébergeur restent des erreurs réseau : les commandes repartent telles quelles au prochain essai.
 */
function refusal(error: unknown): unknown {
  if (error instanceof ApiError && error.fromApplication) {
    return new SyncRefused(error.status, error.code, error.message, error.details)
  }
  return error
}

async function withCsrfRetry<T>(work: () => Promise<T>): Promise<T> {
  try {
    return await work()
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 419) throw refusal(error)
    await prepareSession()
    try {
      return await work()
    } catch (again) {
      throw refusal(again)
    }
  }
}

export const httpSyncTransport: SyncTransport = {
  push: (body: PushRequest) =>
    withCsrfRetry(() => apiRequest<PushResponse>('POST', '/api/v1/sync/push', body)),
  pull: (deviceId: string, cursor: string | null) => {
    const query = new URLSearchParams({ device_id: deviceId })
    if (cursor !== null) query.set('cursor', cursor)
    return withCsrfRetry(() => apiRequest<PullPage>('GET', `/api/v1/sync/pull?${query.toString()}`))
  },
}
