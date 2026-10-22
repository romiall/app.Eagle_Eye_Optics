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

// --- Finalisation et correction (C03) ---------------------------------------------------------------

/** Charge `CompleteConsultation` (alias d'objet : transmissible au répartiteur de mutations). */
export type CompleteRequestPayload = {
  consultation_id: string
  revision_id: string
  revision_no: 1
  snapshot_sha256: string
  finalization_missing_data_reason: string | null
}

/**
 * Charge de finalisation. `digest` est l'empreinte SHA-256 de JCS(steps) des rubriques TELLES QUE LUES sur le
 * serveur (`stepsDigest`) : le serveur la recalcule et refuse un écart. `revisionId` est créé sur le poste. La
 * justification d'absence est celle que la personne a saisie (null si elle n'en donne pas).
 */
export function buildCompletePayload(
  consultationId: string,
  revisionId: string,
  digest: string,
  missingDataReason: string | null,
): CompleteRequestPayload {
  return {
    consultation_id: consultationId,
    revision_id: revisionId,
    revision_no: 1,
    snapshot_sha256: digest,
    finalization_missing_data_reason: missingDataReason,
  }
}

/** Charge `AmendConsultation`. */
export type AmendRequestPayload = {
  consultation_id: string
  revision_id: string
  revision_no: number
  previous_revision_id: string
  reason: string
  steps: { step: StepName; data: object }[]
  snapshot_sha256: string
}

/** Identifiant de la dernière révision d'une consultation terminée, ou null si elle n'en porte aucune. */
export function latestRevisionId(
  consultation: Pick<Consultation, 'current_revision' | 'revisions'>,
): string | null {
  const latest = consultation.revisions.find(
    (revision) => revision.revision_no === consultation.current_revision,
  )
  return latest?.id ?? null
}

/**
 * Charge de correction : les rubriques envoyées REMPLACENT les rubriques actuelles ; `digest` est l'empreinte
 * SHA-256 de JCS(steps) de la requête elle-même (intégrité de ce qui est envoyé), que le serveur recalcule.
 * Retourne null si la consultation n'a pas de révision précédente (elle n'est pas terminée).
 */
export function buildAmendPayload(
  consultation: Pick<Consultation, 'id' | 'current_revision' | 'revisions'>,
  revisionId: string,
  reason: string,
  steps: { step: StepName; data: object }[],
  digest: string,
): AmendRequestPayload | null {
  const previous = latestRevisionId(consultation)
  if (previous === null) return null
  return {
    consultation_id: consultation.id,
    revision_id: revisionId,
    revision_no: consultation.current_revision + 1,
    previous_revision_id: previous,
    reason,
    steps,
    snapshot_sha256: digest,
  }
}
