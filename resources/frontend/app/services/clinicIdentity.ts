import { session } from '@/app/session'
import { clinicFromIdentity, clinicFromSummary, type DocumentClinic } from '@/documents/data/clinic'

import { getClinicDocumentIdentity } from './staff'

/**
 * Identité du cabinet imprimée sur un document produit maintenant (ordonnance, fiche, lettre, relevé de
 * caisse) : celle que la gérante a saisie dans les paramètres (`GET /clinic/document-identity`, adresse,
 * téléphone, mentions, en-tête et pied). Les pièces financières émises gardent, elles, l'identité figée
 * dans leur snapshot.
 *
 * Si cette lecture échoue (réseau, droit), le document garde le seul nom du cabinet de la session :
 * les champs absents restent vides ou « à renseigner » sur un document de test, jamais inventés.
 */
export async function currentDocumentClinic(): Promise<DocumentClinic> {
  const account = session.account
  if (account === null) {
    throw new RangeError('Session non vérifiée : reconnectez-vous pour imprimer.')
  }
  try {
    return clinicFromIdentity(await getClinicDocumentIdentity())
  } catch {
    return clinicFromSummary(account.clinic)
  }
}
