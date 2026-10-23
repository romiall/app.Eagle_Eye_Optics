import { ApiError, apiRequest } from '@/app/api'
import type {
  ClinicalRevisionDetail,
  ClinicalRevisionResponse,
  Consultation,
  ConsultationResponse,
  Prescription,
  PrescriptionList,
  PrescriptionResponse,
  VisualAcuityScale,
  VisualAcuityScaleList,
} from '@/domain/contracts/generated'
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

/** Lecture rendue avec sa fraîcheur : l'heure de la réponse du serveur à CET appel, `live` vrai. */
function live<T>(data: T): ReadOutcome<T> {
  return { data, freshness: { serverAsOf: new Date().toISOString(), complete: true }, live: true }
}

function unexpected(subject: string): ApiError {
  return new ApiError(
    502,
    'HTTP_ERROR',
    `Réponse inattendue du serveur (${subject}).`,
    {},
    null,
    {},
    null,
    false,
  )
}

/**
 * Échelles d'acuité du cabinet (C02). Aucune conversion entre échelles n'est rendue. Une liste vide est une
 * réponse valide : le cabinet n'a alors configuré aucune échelle (l'écran le dit, il n'en invente pas).
 */
export async function getVisualAcuityScales(): Promise<ReadOutcome<VisualAcuityScale[]>> {
  const response = await apiRequest<VisualAcuityScaleList>('GET', '/api/v1/visual-acuity-scales')
  if (!Array.isArray(response?.data)) throw unexpected('échelles d’acuité')
  return live(response.data)
}

/**
 * Révision figée d'une consultation, avec son snapshot (C03). Immuable : relue telle qu'enregistrée, jamais
 * recalculée. Lecture tracée côté serveur.
 */
export async function getRevision(
  consultationId: string,
  revisionNo: number,
): Promise<ReadOutcome<ClinicalRevisionDetail>> {
  const response = await apiRequest<ClinicalRevisionResponse>(
    'GET',
    `/api/v1/consultations/${encodeURIComponent(consultationId)}/revisions/${encodeURIComponent(String(revisionNo))}`,
  )
  const data = response?.data
  if (
    typeof data !== 'object' ||
    data === null ||
    typeof data.snapshot !== 'object' ||
    data.snapshot === null
  ) {
    throw unexpected('révision')
  }
  return live(data)
}

/** Toutes les versions des prescriptions optiques d'une consultation, de la plus récente à la plus ancienne (C04). */
export async function getConsultationPrescriptions(
  consultationId: string,
): Promise<ReadOutcome<Prescription[]>> {
  const response = await apiRequest<PrescriptionList>(
    'GET',
    `/api/v1/consultations/${encodeURIComponent(consultationId)}/prescriptions`,
  )
  if (!Array.isArray(response?.data)) throw unexpected('prescriptions')
  return live(response.data)
}

/** Une prescription optique (C04). */
/** Prescriptions d'un patient (internes et ordonnances externes transcrites), de la plus récente à la plus ancienne. */
export async function getPatientPrescriptions(
  patientId: string,
): Promise<ReadOutcome<Prescription[]>> {
  const response = await apiRequest<PrescriptionList>(
    'GET',
    `/api/v1/patients/${encodeURIComponent(patientId)}/prescriptions`,
  )
  if (!Array.isArray(response?.data)) throw unexpected('prescriptions')
  return live(response.data)
}

export async function getPrescription(id: string): Promise<ReadOutcome<Prescription>> {
  const response = await apiRequest<PrescriptionResponse>(
    'GET',
    `/api/v1/prescriptions/${encodeURIComponent(id)}`,
  )
  const data = response?.data
  if (typeof data !== 'object' || data === null || !Array.isArray(data.eyes)) {
    throw unexpected('prescription')
  }
  return live(data)
}
