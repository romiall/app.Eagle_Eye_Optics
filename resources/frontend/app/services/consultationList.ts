import { apiRequest } from '@/app/api'
import type { ConsultationSummaryPage, ConsultationsStatus } from '@/domain/contracts/generated'

import type { ReadOutcome } from './reads'

/**
 * Liste des examens du cabinet (écran « Consultations », `GET /consultations`) : patient, état, dates et
 * professionnel, AUCUNE valeur clinique ; `clinical.read`, module « consultations ». Le dossier lui-même se
 * lit par la consultation (lecture tracée). Lecture du serveur seulement : rien n'est gardé sur le poste.
 */
export interface ConsultationListFilters {
  /** Jour civil du cabinet `AAAA-MM-JJ` (date effective de l'examen), ou null pour tous les jours. */
  day: string | null
  status: ConsultationsStatus | null
  /** Seulement les examens du professionnel connecté. */
  mine: boolean
  page: number
}

export async function listConsultations(
  filters: ConsultationListFilters,
): Promise<ReadOutcome<ConsultationSummaryPage>> {
  if (!Number.isSafeInteger(filters.page) || filters.page < 1) {
    throw new RangeError('Page de résultats invalide.')
  }
  const parameters = new URLSearchParams({ page: String(filters.page) })
  if (filters.day !== null) parameters.set('date', filters.day)
  if (filters.status !== null) parameters.set('status', filters.status)
  if (filters.mine) parameters.set('mine', 'true')
  const response = await apiRequest<ConsultationSummaryPage>(
    'GET',
    `/api/v1/consultations?${parameters}`,
  )
  return {
    data: response,
    freshness: { serverAsOf: new Date().toISOString(), complete: true },
    live: true,
  }
}
