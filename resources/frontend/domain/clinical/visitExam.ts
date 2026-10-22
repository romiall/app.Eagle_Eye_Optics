import type { ConsultationsStatus, Visit } from '../contracts/generated'
import { isExamReason } from '../patients/visits'

/**
 * Examen d'une visite (C01 ; fichier 02, « File patients »). La file du jour propose « Démarrer
 * l'examen » pour une visite d'examen ou de suivi arrivée et sans consultation, « Reprendre l'examen »
 * quand une consultation existe. Le contrôle du paiement de l'examen n'existe pas encore (B04, B06) :
 * démarrer n'est PAS bloqué par le règlement, et rien ici n'en parle. Règles pures.
 */

export interface VisitConsultation {
  id: string | null
  status: ConsultationsStatus | null
}

/**
 * La ressource `Visit` porte `consultation_id` et `consultation_status`, rendus SEULEMENT avec
 * `clinical.read` (null sinon, y compris quand la consultation existe) et null tant que l'examen n'est
 * pas démarré. Un champ absent de la réponse (serveur plus ancien) vaut aussi « pas de consultation
 * connue » : l'écran n'invente jamais un examen.
 */
export function consultationOfVisit(visit: Visit): VisitConsultation {
  return { id: visit.consultation_id ?? null, status: visit.consultation_status ?? null }
}

export interface ExamAccess {
  /** `clinical.read` : consulter un examen. */
  canRead: boolean
  /** `clinical.write` : démarrer et modifier un examen (avec `clinical.read` pour le démarrer). */
  canWrite: boolean
  /** Le module « consultations » est ouvert par l'exploitant. */
  moduleOpen: boolean
}

export type ExamAction =
  /** Démarrer l'examen (commande StartConsultation). */
  | 'start'
  /** Reprendre l'examen en cours (consultation brouillon ou en cours). */
  | 'resume'
  /** Consulter un examen terminé. */
  | 'view'

/** Statuts de visite que le serveur accepte de démarrer (`ARRIVED`, et `READY_FOR_EXAM` quand B04 et B06 l'écriront). */
const STARTABLE_STATUSES: readonly string[] = ['ARRIVED', 'READY_FOR_EXAM']

/**
 * Action d'examen proposée pour une visite, ou null. Démarrer exige `clinical.write` ET `clinical.read` :
 * le serveur répond par la consultation et refuse sinon (403). Le serveur revérifie tout : il n'accepte
 * de démarrer qu'une visite d'examen ou de suivi arrivée, sans consultation.
 */
export function examActionOf(visit: Visit, access: ExamAccess): ExamAction | null {
  if (!access.moduleOpen) return null
  const consultation = consultationOfVisit(visit)
  if (consultation.id !== null) {
    if (!access.canRead) return null
    return consultation.status === 'COMPLETED' ? 'view' : 'resume'
  }
  if (
    access.canWrite &&
    access.canRead &&
    isExamReason(visit.reason) &&
    STARTABLE_STATUSES.includes(visit.status)
  ) {
    return 'start'
  }
  return null
}

export const EXAM_ACTION_LABELS: Readonly<Record<ExamAction, string>> = {
  start: 'Démarrer l’examen',
  resume: 'Reprendre l’examen',
  view: 'Consulter l’examen',
}
