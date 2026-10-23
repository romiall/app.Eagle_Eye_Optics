import type { PullPage, PushRequest, PushResponse } from '../../domain/contracts/generated'

/**
 * Accès réseau de la synchronisation (O05), FOURNI par l'application : le moteur local n'appelle jamais `fetch`
 * lui-même (session, CSRF et erreurs restent ceux de `app/api.ts`). `POST /sync/push` et `GET /sync/pull`.
 */
export interface SyncTransport {
  push(body: PushRequest): Promise<PushResponse>
  pull(deviceId: string, cursor: string | null): Promise<PullPage>
}

/**
 * Refus EXPLICITE du serveur (enveloppe du fichier 08, code stable) : intégrité, rang manquant, droits. Toute autre
 * erreur (réseau coupé, délai dépassé, page d'hébergeur) est un serveur injoignable : rien n'est perdu, les mêmes
 * commandes repartent au prochain essai.
 */
export class SyncRefused extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Readonly<Record<string, string | number | null>> = {},
  ) {
    super(message)
    this.name = 'SyncRefused'
  }
}

export function isSyncRefused(error: unknown, code?: string): error is SyncRefused {
  return error instanceof SyncRefused && (code === undefined || error.code === code)
}
