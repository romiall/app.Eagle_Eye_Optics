import { apiRequest } from '@/app/api'
import type {
  Patient,
  PatientActivity,
  PatientActivityMeta,
  PatientPage,
  PatientResponse,
} from '@/domain/contracts/generated'
import { searchTerm } from '@/domain/patients/duplicates'

import type { ReadOutcome } from './reads'

/**
 * Lectures du serveur pour les écrans patient (P01, P02). Chaque lecture rend la donnée avec sa
 * fraîcheur (`serverAsOf` : l'heure de la réponse du serveur à CET appel, `complete`) et `live`
 * vrai : seul un vide `live` peut devenir « aucun résultat » (`useRead`). Une erreur du serveur est
 * propagée telle quelle : un 404 est une erreur d'application (fiche inconnue), jamais une liste
 * vide ; un réseau coupé reste `NetworkUnavailable`.
 *
 * Ces lectures sont celles du mode en ligne. Le moteur local (annuaire préparé du poste, O01 à O05)
 * s'ajoutera derrière la même interface, sans changer les écrans.
 */
function answered<T>(data: T): ReadOutcome<T> {
  return { data, freshness: { serverAsOf: new Date().toISOString(), complete: true }, live: true }
}

/**
 * Recherche paginée : nom ou prénom (casse, accents et ordre des mots ignorés), téléphone (chiffres)
 * ou numéro de dossier. Moins de 2 caractères n'est jamais envoyé : l'écran le refuse avant.
 */
export async function searchPatients(query: string, page = 1): Promise<ReadOutcome<PatientPage>> {
  const term = searchTerm(query)
  if (!term.ok) throw new RangeError('La recherche demande 2 caractères au moins.')
  if (!Number.isSafeInteger(page) || page < 1) throw new RangeError('Page de résultats invalide.')
  const parameters = new URLSearchParams({ query: term.text, page: String(page) })
  const response = await apiRequest<PatientPage>('GET', `/api/v1/patients?${parameters}`)
  return answered(response)
}

/** Fiche d'un patient. Identité seulement : aucune donnée clinique. */
export async function getPatient(id: string): Promise<ReadOutcome<Patient>> {
  const response = await apiRequest<PatientResponse>(
    'GET',
    `/api/v1/patients/${encodeURIComponent(id)}`,
  )
  return answered(response.data)
}

/** Section de l'activité d'un patient (filtre facultatif). */
export type ActivitySectionCode = NonNullable<PatientActivityMeta['section']>

/**
 * Une page (25 éléments) de l'activité d'un patient, du plus récent au plus ancien : visites, et selon
 * les droits du compte, examens, ordonnances et pièces financières (le serveur n'envoie que les sections
 * autorisées, sans aucune mention des autres). `section` null : toutes les sections visibles.
 */
export async function listPatientActivity(
  id: string,
  section: ActivitySectionCode | null = null,
  page = 1,
): Promise<ReadOutcome<PatientActivity>> {
  if (!Number.isSafeInteger(page) || page < 1) throw new RangeError('Page d’activité invalide.')
  const parameters = new URLSearchParams({ page: String(page) })
  if (section !== null) parameters.set('section', section)
  const response = await apiRequest<PatientActivity>(
    'GET',
    `/api/v1/patients/${encodeURIComponent(id)}/activity?${parameters}`,
  )
  return answered(response)
}
