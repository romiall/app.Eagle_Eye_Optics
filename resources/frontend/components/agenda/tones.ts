/**
 * Teintes de présentation de l'agenda et des suivis (charte du logo, docs/implementation/shell.md) :
 * puce et filet d'un rendez-vous selon son statut, puce d'une tâche de contact ou d'un contrôle
 * recommandé, repère d'échéance (en retard, aujourd'hui, plus tard). Présentation seule : statuts
 * et dates viennent du serveur et rien n'est décidé ici ; le libellé écrit porte toujours le sens,
 * la teinte et l'icône ne font que l'accompagner (fichier 04). Associations vérifiées par
 * tests/frontend/shell/palette.test.ts : filets or, encre, accent, alerte, danger et bordure de
 * champ sur surface ou fond crème ; texte de l'échéance en danger, or foncé ou secondaire sur son
 * fond léger.
 */
import { dayOf, isOpenTask } from '@/domain/agenda/agenda'
import type {
  AppointmentsStatus,
  ContactTask,
  ContactTasksStatus,
  FollowUpPlan,
  FollowUpPlansStatus,
} from '@/domain/contracts/generated'
import { CivilDate } from '@/domain/values/civilDate'

/** Teintes de `StatusChip` (même liste que son type `ChipTone`). */
export type AgendaTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'gold'

/** Présentation d'un rendez-vous : puce (teinte, icône), filet gauche de la carte, carte en retrait. */
export interface AppointmentLook {
  tone: AgendaTone
  /** Nom d'une icône d'`AppIcon` : une forme par statut, en plus du libellé. */
  icon: string
  rail: string
  /** Rendez-vous annulé : carte sur le fond crème, sans ombre. */
  quiet: boolean
}

export const APPOINTMENT_LOOKS: Readonly<Record<AppointmentsStatus, AppointmentLook>> = {
  PENDING_CONFIRMATION: { tone: 'warning', icon: 'clock', rail: 'border-l-warning', quiet: false },
  CONFIRMED: { tone: 'gold', icon: 'check', rail: 'border-l-gold', quiet: false },
  ARRIVED: { tone: 'success', icon: 'arrival', rail: 'border-l-accent', quiet: false },
  COMPLETED: { tone: 'info', icon: 'check-circle', rail: 'border-l-primary', quiet: false },
  CANCELLED: { tone: 'neutral', icon: 'x-circle', rail: 'border-l-field', quiet: true },
  NO_SHOW: { tone: 'danger', icon: 'alert', rail: 'border-l-danger', quiet: false },
}

/** Statut inconnu de cette version (contrat plus récent) : présentation neutre, sans icône d'état. */
export const UNKNOWN_APPOINTMENT_LOOK: AppointmentLook = {
  tone: 'neutral',
  icon: 'dot',
  rail: 'border-l-line',
  quiet: false,
}

export const CONTACT_STATUS_TONES: Readonly<Record<ContactTasksStatus, AgendaTone>> = {
  PENDING: 'gold',
  CONTACTED: 'success',
  BOOKED: 'success',
  NO_RESPONSE: 'warning',
  REFUSED: 'neutral',
  POSTPONED: 'info',
  CANCELLED: 'neutral',
}

export const FOLLOW_UP_STATUS_TONES: Readonly<Record<FollowUpPlansStatus, AgendaTone>> = {
  ACTIVE: 'gold',
  SCHEDULED: 'success',
  CLOSED: 'neutral',
  CANCELLED: 'neutral',
}

/** Échéance par rapport au jour civil du cabinet : dépassée, ce jour, ou à venir (ou traitée). */
export type DueState = 'overdue' | 'today' | 'later'

/** Filet gauche de la carte, cadre de l'échéance et teinte de son libellé. */
export interface DueLook {
  rail: string
  box: string
  label: string
}

export const DUE_LOOKS: Readonly<Record<DueState, DueLook>> = {
  overdue: { rail: 'border-l-danger', box: 'border-danger bg-danger-soft', label: 'text-danger' },
  today: { rail: 'border-l-gold', box: 'border-gold bg-gold-soft', label: 'text-gold-ink' },
  later: { rail: 'border-l-line', box: 'border-line bg-canvas', label: 'text-muted' },
}

export function dueState(day: CivilDate, today: CivilDate): DueState {
  const order = day.compare(today)
  if (order < 0) return 'overdue'
  return order === 0 ? 'today' : 'later'
}

/** Échéance d'une tâche de contact encore ouverte ; une tâche traitée n'est jamais « en retard ». */
export function taskDueState(
  task: Pick<ContactTask, 'status' | 'due_at'>,
  today: CivilDate,
  timeZone: string,
): DueState {
  return isOpenTask(task.status) ? dueState(dayOf(task.due_at, timeZone), today) : 'later'
}

/** Échéance d'un contrôle encore à programmer ; un contrôle programmé ou clos n'est pas en retard. */
export function planDueState(
  plan: Pick<FollowUpPlan, 'status' | 'recommended_date'>,
  today: CivilDate,
): DueState {
  return plan.status === 'ACTIVE'
    ? dueState(CivilDate.fromString(plan.recommended_date), today)
    : 'later'
}
