import type { Consultation, StepName, Visit } from '../contracts/generated'
import { Instant } from '../values/instant'
import { DELIVERED_STEPS, type DeliveredStep } from './labels'
import type { StepDataByName } from './steps'

/**
 * Charges des commandes `StartConsultation` et `SaveClinicalStep` (contrat F06). Aucune règle
 * n'est rejouée ici : `validateStep` a déjà produit la donnée à envoyer.
 */

/** Charge `SaveClinicalStep` (alias d'objet : transmissible au répartiteur de mutations). */
export type SaveStepRequestPayload = {
  consultation_id: string
  step: { step: StepName; data: object }
}

/**
 * L'étape est REMPLACÉE en entier : une ligne absente de `data` est supprimée par le serveur.
 * `consultationId` figure dans la charge ET dans le chemin de la route (le serveur vérifie l'égalité).
 */
export function buildSaveStepPayload<S extends DeliveredStep>(
  consultationId: string,
  step: S,
  data: StepDataByName[S],
): SaveStepRequestPayload {
  return { consultation_id: consultationId, step: { step, data } }
}

export type StartConsultationRequestPayload = {
  consultation_id: string
  visit_id: string
  patient_id: string
  effective_at: string
  date_change_reason: null
  exam_invoice_id: null
  exam_waiver_approval_id: null
}

/** Instant courant au format du contrat (`2026-10-15T09:42:00.000Z`, UTC), calculé à l'envoi. */
export function currentInstant(now: number = Date.now()): string {
  return Instant.fromEpochMilliseconds(now).toString()
}

/**
 * Charge de démarrage d'un examen. `effective_at` est l'instant courant, jamais futur ni modifié ici.
 * Le contrôle du paiement de l'examen n'existe pas encore (B04, B06) : la facture d'examen et la
 * dérogation sont nulles et le serveur ne les exige pas dans ce jalon.
 */
export function buildStartConsultationPayload(
  visit: Pick<Visit, 'id' | 'patient_id'>,
  consultationId: string,
  effectiveAt: string,
): StartConsultationRequestPayload {
  return {
    consultation_id: consultationId,
    visit_id: visit.id,
    patient_id: visit.patient_id,
    effective_at: effectiveAt,
    date_change_reason: null,
    exam_invoice_id: null,
    exam_waiver_approval_id: null,
  }
}

/**
 * Vrai si `value` a la forme d'une consultation exploitable par l'écran : identifiant, version, état
 * et les trois rubriques livrées. Une réponse qui ne l'a pas n'est jamais affichée comme une
 * consultation vide.
 */
export function isConsultation(value: unknown): value is Consultation {
  if (typeof value !== 'object' || value === null) return false
  const record = value as Record<string, unknown>
  const steps = record['steps']
  if (
    typeof record['id'] !== 'string' ||
    typeof record['patient_id'] !== 'string' ||
    typeof record['version'] !== 'number' ||
    !Number.isSafeInteger(record['version']) ||
    typeof record['status'] !== 'string' ||
    typeof steps !== 'object' ||
    steps === null
  ) {
    return false
  }
  return DELIVERED_STEPS.every((step) => {
    const data = (steps as Record<string, unknown>)[step]
    return typeof data === 'object' && data !== null
  })
}

/**
 * Consultation lue dans la réponse d'une commande (`{ data: Consultation }`), ou null si la réponse
 * n'a pas la forme attendue : l'appelant relit alors la consultation sur le serveur au lieu de
 * supposer ce qui est enregistré.
 */
export function consultationFromResult(result: unknown): Consultation | null {
  const data =
    typeof result === 'object' && result !== null && 'data' in result
      ? (result as { data: unknown }).data
      : null
  return isConsultation(data) ? data : null
}
