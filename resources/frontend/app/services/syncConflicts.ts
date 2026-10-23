import { ApiError, apiRequest, prepareSession } from '@/app/api'
import type { Conflict, ConflictPage, ConflictResponse } from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

/**
 * Conflits de synchronisation des postes (O05), pour la gérante (`approval.decide`, module `offline`) : liste et
 * décision motivée, avec réauthentification récente (423 : l'écran demande le mot de passe puis rejoue la MÊME
 * décision, même clé d'idempotence). Aucune charge de commande ne transite : seulement la commande d'origine, le motif
 * du refus et la décision.
 */
export type ConflictDecision = NonNullable<Conflict['decision']>

export const DECISION_LABELS: Readonly<Record<ConflictDecision, string>> = {
  ACCEPT_LOCAL: 'Appliquer la pièce du poste telle quelle (après vérification)',
  KEEP_CENTRAL: 'Garder l’état du serveur (la pièce du poste n’est pas appliquée)',
  REJECT: 'Rejeter la pièce du poste',
  CORRECT: 'Rejeter et la remplacer par une commande correctrice',
}

export async function listSyncConflicts(
  status: 'OPEN' | 'RESOLVED' | null,
  page = 1,
): Promise<ConflictPage> {
  const query = new URLSearchParams({ page: String(page) })
  if (status !== null) query.set('status', status)
  return apiRequest<ConflictPage>('GET', `/api/v1/sync/conflicts?${query.toString()}`)
}

export async function resolveSyncConflict(
  conflictId: string,
  input: { decision: ConflictDecision; reason: string; correction_command_id: string | null },
  idempotencyKey: string = newUlid(),
): Promise<Conflict> {
  const path = `/api/v1/sync/conflicts/${encodeURIComponent(conflictId)}/resolution`
  const body = { expected_version: null, ...input }
  const headers = { 'Idempotency-Key': idempotencyKey }
  try {
    return (await apiRequest<ConflictResponse>('POST', path, body, headers)).data
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 419) throw error
    await prepareSession()
    return (await apiRequest<ConflictResponse>('POST', path, body, headers)).data
  }
}
