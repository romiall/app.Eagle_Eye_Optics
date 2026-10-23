import { apiRequest } from '@/app/api'
import type {
  AgendaConfiguration,
  AgendaConfigurationResponse,
  Appointment,
  AppointmentList,
  AvailableSlots,
  AvailableSlotsResponse,
  ContactTask,
  ContactTaskList,
  FollowUpPlan,
  FollowUpPlanList,
} from '@/domain/contracts/generated'
import { isUlid } from '@/domain/values/ulid'

import type { ReadOutcome } from './reads'

/**
 * Lectures de l'agenda (S01) et des suivis (S02), EN LIGNE : configuration, créneaux libres d'un jour, rendez-vous
 * d'une période, patients à revoir, tâches de contact du jour. Les écritures passent par `useMutation` (chemin
 * central, clé d'idempotence) ; rien n'est conservé sur le poste. Un créneau affiché peut être pris entre-temps :
 * la réservation le revérifie sous verrou (409 `SLOT_UNAVAILABLE`).
 */
function answered<T>(data: T): ReadOutcome<T> {
  return { data, freshness: { serverAsOf: new Date().toISOString(), complete: true }, live: true }
}

function assertDay(day: string, what: string): void {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(day))
    throw new RangeError(`${what} invalide (AAAA-MM-JJ attendu).`)
}

function assertId(id: string, what: string): void {
  if (!isUlid(id)) throw new RangeError(`Identifiant de ${what} invalide.`)
}

export async function getAgendaConfiguration(): Promise<ReadOutcome<AgendaConfiguration>> {
  const response = await apiRequest<AgendaConfigurationResponse>(
    'GET',
    '/api/v1/agenda/configuration',
  )
  return answered(response.data)
}

export async function listAvailableSlots(
  date: string,
  resourceId: string,
  serviceId: string,
): Promise<ReadOutcome<AvailableSlots>> {
  assertDay(date, 'Jour')
  assertId(resourceId, 'ressource')
  assertId(serviceId, 'prestation')
  const query = new URLSearchParams({ date, resource_id: resourceId, service_id: serviceId })
  const response = await apiRequest<AvailableSlotsResponse>(
    'GET',
    `/api/v1/agenda/slots?${query.toString()}`,
  )
  return answered(response.data)
}

export interface AppointmentFilters {
  resourceId?: string | null
  patientId?: string | null
}

export async function listAppointments(
  from: string,
  to: string,
  filters: AppointmentFilters = {},
): Promise<ReadOutcome<Appointment[]>> {
  assertDay(from, 'Début de période')
  assertDay(to, 'Fin de période')
  const query = new URLSearchParams({ from, to })
  if (filters.resourceId) {
    assertId(filters.resourceId, 'ressource')
    query.set('resource_id', filters.resourceId)
  }
  if (filters.patientId) {
    assertId(filters.patientId, 'patient')
    query.set('patient_id', filters.patientId)
  }
  const response = await apiRequest<AppointmentList>(
    'GET',
    `/api/v1/appointments?${query.toString()}`,
  )
  return answered(response.data)
}

/** Patients à revoir dont la date recommandée tombe dans la période. */
export async function listFollowUps(
  from: string,
  to: string,
): Promise<ReadOutcome<FollowUpPlan[]>> {
  assertDay(from, 'Début de période')
  assertDay(to, 'Fin de période')
  const query = new URLSearchParams({ from, to })
  const response = await apiRequest<FollowUpPlanList>(
    'GET',
    `/api/v1/follow-ups?${query.toString()}`,
  )
  return answered(response.data)
}

/** Contrôles recommandés d'une consultation. */
export async function listConsultationFollowUps(
  consultationId: string,
): Promise<ReadOutcome<FollowUpPlan[]>> {
  assertId(consultationId, 'consultation')
  const response = await apiRequest<FollowUpPlanList>(
    'GET',
    `/api/v1/follow-ups?consultation_id=${consultationId}`,
  )
  return answered(response.data)
}

/** Tâches de contact d'un jour (retards compris) ; aujourd'hui du cabinet par défaut. */
export async function listContactTasks(
  day: string | null = null,
): Promise<ReadOutcome<ContactTask[]>> {
  let path = '/api/v1/contact-tasks'
  if (day !== null) {
    assertDay(day, 'Jour')
    path += `?due_date=${day}`
  }
  const response = await apiRequest<ContactTaskList>('GET', path)
  return answered(response.data)
}
