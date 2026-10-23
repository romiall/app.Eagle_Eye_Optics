import { apiRequest } from '@/app/api'
import type { InvoiceDeliveries, InvoiceDeliveriesResponse } from '@/domain/contracts/generated'
import { isUlid } from '@/domain/values/ulid'

import type { ReadOutcome } from './reads'

/**
 * Lecture des remises d'une facture (I01, I02), EN LIGNE seulement : reste à remettre par ligne, réserve ouverte,
 * remises confirmées, retours physiques et reste dû à l'instant. Projection du serveur, rien n'est calculé ni conservé
 * sur le poste. Un réseau coupé reste `NetworkUnavailable` (jamais « rien à remettre »).
 *
 * Les écritures (remise, retour, casse, ajustement) passent par le répartiteur de mutations (`ConfirmDelivery`,
 * `RegisterPhysicalReturn`, `RegisterStockMovement`, routes directes du catalogue F06).
 */
export async function getInvoiceDeliveries(
  invoiceId: string,
): Promise<ReadOutcome<InvoiceDeliveries>> {
  if (!isUlid(invoiceId)) throw new RangeError('Identifiant de facture invalide.')
  const response = await apiRequest<InvoiceDeliveriesResponse>(
    'GET',
    `/api/v1/invoices/${encodeURIComponent(invoiceId)}/deliveries`,
  )
  return {
    data: response.data,
    freshness: { serverAsOf: new Date().toISOString(), complete: true },
    live: true,
  }
}
