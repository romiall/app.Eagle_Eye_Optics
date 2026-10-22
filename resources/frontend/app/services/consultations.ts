import { ApiError, apiRequest } from '@/app/api'
import type { Consultation, ConsultationResponse } from '@/domain/contracts/generated'
import { isConsultation } from '@/domain/clinical/payload'

import type { ReadOutcome } from './reads'

/**
 * Lecture du serveur pour l'écran de consultation (C01). Comme pour les patients, la lecture rend la
 * donnée avec sa fraîcheur (`serverAsOf` : l'heure de la réponse du serveur à CET appel) et `live`
 * vrai. Une erreur du serveur est propagée telle quelle (403 sans `clinical.read`, 404 d'une
 * consultation inconnue, d'un autre cabinet ou d'un module fermé) ; un réseau coupé reste
 * `NetworkUnavailable`, jamais une consultation vide ni une ancienne copie.
 *
 * L'écriture (`StartConsultation`, `SaveClinicalStep`) passe par `useMutation`, non par ce fichier.
 *
 * Mode en ligne seulement : une consultation est lue sur le serveur. Les données cliniques ne sont
 * jamais conservées dans le navigateur avant que le coffre chiffré existe (O01 à O05).
 */
export async function getConsultation(id: string): Promise<ReadOutcome<Consultation>> {
  const response = await apiRequest<ConsultationResponse>(
    'GET',
    `/api/v1/consultations/${encodeURIComponent(id)}`,
  )
  if (!isConsultation(response?.data)) {
    // Réponse d'un autre format (hébergeur, proxy) : une erreur, pas une consultation vide.
    throw new ApiError(
      502,
      'HTTP_ERROR',
      'Réponse inattendue du serveur (consultation).',
      {},
      null,
      {},
      null,
      false,
    )
  }
  return {
    data: response.data,
    freshness: { serverAsOf: new Date().toISOString(), complete: true },
    live: true,
  }
}
