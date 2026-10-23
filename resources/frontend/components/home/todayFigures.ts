import type { Appointment, Visit, VisitsStatus } from '@/domain/contracts/generated'

/**
 * Comptes du bandeau « Aujourd’hui » de l'accueil, faits sur les seuls statuts rendus par le
 * serveur (file du jour, rendez-vous du jour) : rien n'est déduit d'autre chose et une visite
 * annulée n'est comptée nulle part. Fonctions pures, sans lecture ni mise en forme.
 */

/** Visite arrivée qui attend d'être prise en charge : arrivée, frais d'examen attendus, prête. */
const WAITING_STATUSES: readonly VisitsStatus[] = ['ARRIVED', 'AWAITING_EXAM_FEE', 'READY_FOR_EXAM']

export interface QueueCounts {
  /** Patients en attente (`ARRIVED`, `AWAITING_EXAM_FEE`, `READY_FOR_EXAM`). */
  waiting: number
  /** Patients en consultation (`IN_CONSULTATION`). */
  inConsultation: number
  /** Visites terminées (`COMPLETED`). */
  completed: number
}

export function queueCounts(visits: readonly Pick<Visit, 'status'>[]): QueueCounts {
  const counts: QueueCounts = { waiting: 0, inConsultation: 0, completed: 0 }
  for (const { status } of visits) {
    if (WAITING_STATUSES.includes(status)) counts.waiting += 1
    else if (status === 'IN_CONSULTATION') counts.inConsultation += 1
    else if (status === 'COMPLETED') counts.completed += 1
  }
  return counts
}

/** Rendez-vous qui ne sont pas annulés (à confirmer, confirmés, arrivés, terminés, absents). */
export function activeAppointmentCount(
  appointments: readonly Pick<Appointment, 'status'>[],
): number {
  return appointments.filter((appointment) => appointment.status !== 'CANCELLED').length
}
