import { apiRequest } from '@/app/api'
import type { ApprovalPage, ApprovalResponse, ApprovalsStatus } from '@/domain/contracts/generated'
import { isUlid } from '@/domain/values/ulid'

import type { ReadOutcome } from './reads'

/**
 * Lectures des approbations exactes (B06), EN LIGNE seulement, module « settings ». La gérante lit toutes
 * les demandes du cabinet ; un autre compte, seulement les siennes (le serveur filtre). Chaque lecture rend
 * la donnée avec sa fraîcheur et `live` vrai : seul un vide `live` devient « aucune demande ». Un réseau
 * coupé reste `NetworkUnavailable`, jamais une liste vide ; rien n'est conservé sur le poste.
 *
 * Les écritures (demande, décision) passent par le répartiteur de mutations (`RequestApproval`,
 * `DecideApproval` du contrat F06) : clé d'idempotence stable, réauthentification de la gérante (423)
 * puis reprise de la même commande.
 */
function answered<T>(data: T): ReadOutcome<T> {
  return { data, freshness: { serverAsOf: new Date().toISOString(), complete: true }, live: true }
}

/** Une page (25) des demandes, les plus récentes d'abord ; `status` filtre sur le statut effectif. */
export async function listApprovals(
  status: ApprovalsStatus | null,
  page = 1,
): Promise<ReadOutcome<ApprovalPage>> {
  if (!Number.isSafeInteger(page) || page < 1) throw new RangeError('Page invalide.')
  const parameters = new URLSearchParams({ page: String(page) })
  if (status !== null) parameters.set('status', status)
  return answered(await apiRequest<ApprovalPage>('GET', `/api/v1/approvals?${parameters}`))
}

export async function getApproval(id: string): Promise<ReadOutcome<ApprovalResponse>> {
  if (!isUlid(id)) throw new RangeError('Identifiant d’approbation invalide.')
  return answered(
    await apiRequest<ApprovalResponse>('GET', `/api/v1/approvals/${encodeURIComponent(id)}`),
  )
}
