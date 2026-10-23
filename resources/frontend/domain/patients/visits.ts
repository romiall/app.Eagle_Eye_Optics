import type {
  RegisterVisitPayload,
  Visit,
  VisitsReason,
  VisitsStatus,
} from '../contracts/generated'
import { RuleViolation } from '../errors'
import { CivilDate } from '../values/civilDate'
import { Instant } from '../values/instant'
import { optionalText, trimBlanks } from '../values/text'

/**
 * Visites et file du jour (P03 ; fichier 02, « Patients et accueil » ; AT05). Règles pures : ni Vue,
 * ni réseau, ni stockage. Une visite est ADMINISTRATIVE (motif, heure d'arrivée, statut décidé par le
 * serveur) : aucune donnée clinique. Contrôle du paiement de l'examen inactif (défaut) : le serveur
 * répond `NOT_CONTROLLED` pour un examen ou un contrôle de suivi, et l'écran ne dit jamais ce que
 * cette valeur ne prouve pas. Contrôle actif (`EEO_EXAM_FEE_CONTROL=billed`, B04 et B06) : frais à
 * encaisser, dispense demandée, frais encaissés (avec le numéro du reçu réel) ou dispense accordée.
 */
export const NOTE_MAX_LENGTH = 4000

/** Motifs administratifs, dans l'ordre de la liste de choix. */
export const VISIT_REASONS: readonly VisitsReason[] = [
  'EXAM',
  'DIRECT_PURCHASE',
  'PICKUP',
  'FOLLOW_UP',
]

export const REASON_LABELS: Readonly<Record<VisitsReason, string>> = {
  EXAM: 'Examen de vue',
  DIRECT_PURCHASE: 'Achat direct',
  PICKUP: 'Retrait de commande',
  FOLLOW_UP: 'Contrôle de suivi',
}

export function reasonLabel(reason: VisitsReason): string {
  return REASON_LABELS[reason]
}

export function isVisitReason(value: string): value is VisitsReason {
  return (VISIT_REASONS as readonly string[]).includes(value)
}

/** Vrai pour un motif qui appelle un examen : un achat ou un retrait n'en impose aucun. */
export function isExamReason(reason: VisitsReason): boolean {
  return reason === 'EXAM' || reason === 'FOLLOW_UP'
}

/**
 * Explication d'un motif à la saisie. Elle dit ce que l'application fait, pas davantage : aucun examen
 * pour un achat ou un retrait ; pour un examen, le règlement est exigé seulement si le serveur contrôle
 * réellement le paiement de l'examen (`examFeeControlled`, lu dans la session : `/auth/me`).
 */
export function reasonHint(reason: VisitsReason, examFeeControlled = false): string {
  if (!isExamReason(reason)) return 'Aucun examen ni consultation n’est créé pour cette visite.'
  return examFeeControlled
    ? 'Les frais d’examen de cette visite seront à encaisser avant le démarrage de l’examen, sauf dispense accordée par la gérante.'
    : 'Le contrôle du paiement de l’examen n’est pas encore actif : l’examen n’est pas bloqué faute de règlement.'
}

/** Statuts décidés par le serveur ; seuls certains sont produits à ce jalon, tous ont un libellé exact. */
export const STATUS_LABELS: Readonly<Record<VisitsStatus, string>> = {
  ARRIVED: 'Arrivé',
  AWAITING_EXAM_FEE: 'En attente du paiement de l’examen',
  READY_FOR_EXAM: 'Prêt pour l’examen',
  IN_CONSULTATION: 'En consultation',
  COMPLETED: 'Terminé',
  CANCELLED: 'Annulé',
}

/** Symbole de chaque statut : la couleur n'est jamais le seul signal. */
export const STATUS_SYMBOLS: Readonly<Record<VisitsStatus, string>> = {
  ARRIVED: '○',
  AWAITING_EXAM_FEE: '…',
  READY_FOR_EXAM: '✓',
  IN_CONSULTATION: '●',
  COMPLETED: '✓',
  CANCELLED: '✕',
}

/** Libellé d'un statut ; un statut que cette version ne connaît pas est dit tel quel, jamais deviné. */
export function statusLabel(status: string): string {
  return isVisitStatus(status) ? STATUS_LABELS[status] : `Statut inconnu (${status})`
}

export function statusSymbol(status: string): string {
  return isVisitStatus(status) ? STATUS_SYMBOLS[status] : '?'
}

function isVisitStatus(value: string): value is VisitsStatus {
  return Object.prototype.hasOwnProperty.call(STATUS_LABELS, value)
}

export interface ExamFeeMarker {
  symbol: string
  text: string
  /** Vrai quand le paiement de l'examen concerne la visite mais n'est contrôlé nulle part. */
  notControlled: boolean
  /** Vrai quand une action est attendue avant l'examen (frais à encaisser, dispense en attente). */
  pending?: boolean
}

/**
 * Marqueur du contrôle du paiement de l'examen. `NOT_CONTROLLED` n'est ni « payé » ni « impayé » :
 * rien n'est vérifié. `NOT_APPLICABLE` (achat, retrait) n'a pas d'examen à régler. Contrôle actif :
 * `UNPAID` (à encaisser), `WAIVER_REQUESTED` (dispense demandée), `PAID` (encaissés : le numéro du
 * reçu réel est cité, jamais « payé » sans pièce), `WAIVED` (dispense accordée par la gérante, qui
 * n'est pas une gratuité).
 */
export function examFeeMarker(
  control: Visit['exam_fee_control'],
  receiptNumber: string | null = null,
): ExamFeeMarker {
  switch (control) {
    case 'NOT_CONTROLLED':
      return { symbol: '⚠', text: 'Paiement de l’examen non contrôlé', notControlled: true }
    case 'UNPAID':
      return {
        symbol: '…',
        text: 'Frais d’examen à encaisser',
        notControlled: false,
        pending: true,
      }
    case 'WAIVER_REQUESTED':
      return {
        symbol: '…',
        text: 'Dispense des frais d’examen demandée : en attente de la gérante',
        notControlled: false,
        pending: true,
      }
    case 'PAID':
      return {
        symbol: '✓',
        text:
          receiptNumber === null
            ? 'Frais d’examen encaissés'
            : `Frais d’examen encaissés : reçu n° ${receiptNumber}`,
        notControlled: false,
      }
    case 'WAIVED':
      return { symbol: '✓', text: 'Dispense approuvée par la gérante', notControlled: false }
    default:
      return { symbol: '–', text: 'Pas d’examen', notControlled: false }
  }
}

/** Instant courant au format du contrat (`2026-10-15T09:42:00.000Z`, UTC), calculé à l'envoi. */
export function currentInstant(now: number = Date.now()): string {
  return Instant.fromEpochMilliseconds(now).toString()
}

/** Jour voisin d'un jour civil ISO ; null aux bornes du calendrier admis (jamais une date inventée). */
export function shiftDay(day: string, days: number): string | null {
  try {
    return CivilDate.fromString(day).addDays(days).toString()
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return null
  }
}

/** Visites du patient dans une liste du jour, hors visites annulées (qui ne comptent pas comme arrivées). */
export function existingVisits(visits: readonly Visit[], patientId: string): Visit[] {
  return visits.filter((visit) => visit.patient_id === patientId && visit.status !== 'CANCELLED')
}

// --- Saisie d'une arrivée -------------------------------------------------------------------------

export interface ArrivalForm {
  patientId: string | null
  reason: VisitsReason | null
  note: string
}

export type ArrivalField = 'patient' | 'reason' | 'note'

export type ArrivalErrors = Partial<Record<ArrivalField, string>>

export interface ArrivalValues {
  patient_id: string
  reason: VisitsReason
  administrative_note: string | null
}

export type ArrivalValidation =
  { ok: true; values: ArrivalValues } | { ok: false; errors: ArrivalErrors }

/**
 * Règles locales avant tout envoi : un patient choisi dans la liste, un motif, une note facultative
 * (vide → null, jamais une chaîne vide). Le serveur reste l'autorité (fiche archivée ou fusionnée…).
 */
export function validateArrival(form: ArrivalForm): ArrivalValidation {
  const errors: ArrivalErrors = {}
  if (form.patientId === null) {
    errors.patient = 'Choisissez le patient dans la liste des résultats.'
  }
  if (form.reason === null) {
    errors.reason = 'Choisissez le motif de la visite.'
  }
  let note: string | null = null
  try {
    note = optionalText(form.note, NOTE_MAX_LENGTH, 'administrative_note')
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    errors.note =
      error.rule === 'text.too_long'
        ? `La note ne peut pas dépasser ${NOTE_MAX_LENGTH} caractères.`
        : error.message
  }
  if (errors.patient !== undefined || errors.reason !== undefined || errors.note !== undefined) {
    return { ok: false, errors }
  }
  return {
    ok: true,
    values: {
      // Les gardes ci-dessus ont établi que ces deux champs sont renseignés.
      patient_id: form.patientId as string,
      reason: form.reason as VisitsReason,
      administrative_note: note,
    },
  }
}

/**
 * Charge `RegisterVisit` (alias d'objet : transmissible au répartiteur de mutations, qu'une interface ne
 * serait pas). Mêmes champs que le contrat F06.
 */
export type RegisterVisitRequestPayload = {
  [K in keyof RegisterVisitPayload]: RegisterVisitPayload[K]
}

export interface RegisterVisitIds {
  /** Identifiant de la visite, connu avant tout envoi (`effectId('visit_id')`). */
  visitId: string
  /** Rendez-vous dont c'est l'arrivée (S01), s'il y en a un : il passe « arrivé » avec la visite. */
  appointmentId?: string | null
}

/**
 * Charge de la commande `RegisterVisit` (contrat F06). `occurred_at` est l'instant d'arrivée, calculé
 * par l'appelant À L'ENVOI ; aucun rendez-vous n'est lié depuis cet écran (`appointment_id` null).
 */
export function buildRegisterVisitPayload(
  values: ArrivalValues,
  ids: RegisterVisitIds,
  occurredAt: string,
): RegisterVisitRequestPayload {
  return {
    visit_id: ids.visitId,
    patient_id: values.patient_id,
    reason: values.reason,
    occurred_at: occurredAt,
    appointment_id: ids.appointmentId ?? null,
    administrative_note: values.administrative_note,
  }
}

/** Champ de l'écran qui porte une erreur du serveur (clé de `error.fields`), ou null. */
export function arrivalFieldFromServer(key: string): ArrivalField | null {
  switch (key) {
    case 'patient_id':
      return 'patient'
    case 'reason':
      return 'reason'
    case 'administrative_note':
      return 'note'
    default:
      return null
  }
}

// --- Annulation d'une visite (P03) -------------------------------------------------------------------

export const CANCEL_REASON_MIN_LENGTH = 3
export const CANCEL_REASON_MAX_LENGTH = 500

/** Statuts d'une visite encore avant l'examen : seuls ceux-là s'annulent (le serveur revérifie tout). */
export const CANCELLABLE_STATUSES: readonly VisitsStatus[] = [
  'ARRIVED',
  'AWAITING_EXAM_FEE',
  'READY_FOR_EXAM',
]

/**
 * Visite annulable depuis la file : avant l'examen et sans consultation connue. Une consultation existante
 * n'est visible qu'avec le droit clinique : sans lui, le serveur refuse (409) et l'écran le dit.
 */
export function isCancellable(visit: Pick<Visit, 'status' | 'consultation_id'>): boolean {
  return CANCELLABLE_STATUSES.includes(visit.status) && visit.consultation_id === null
}

/** Motif obligatoire de l'annulation (information d'accueil, 3 à 500 caractères). */
export function validateCancelReason(
  text: string,
): { ok: true; reason: string } | { ok: false; error: string } {
  const reason = trimBlanks(text)
  if (reason.length < CANCEL_REASON_MIN_LENGTH) {
    return {
      ok: false,
      error: `Indiquez le motif de l’annulation (au moins ${CANCEL_REASON_MIN_LENGTH} caractères).`,
    }
  }
  if (reason.length > CANCEL_REASON_MAX_LENGTH) {
    return {
      ok: false,
      error: `Le motif compte au plus ${CANCEL_REASON_MAX_LENGTH} caractères.`,
    }
  }
  return { ok: true, reason }
}
