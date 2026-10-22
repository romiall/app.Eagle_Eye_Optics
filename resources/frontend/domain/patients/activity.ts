import { workStatusLabel } from '../clinical/labels'
import type {
  PatientActivityInvoice,
  PatientActivityItem,
  PatientActivityMeta,
  PaymentsMethod,
} from '../contracts/generated'
import { CivilDate } from '../values/civilDate'
import { formatMoney } from '../values/money'
import { reasonLabel, statusLabel } from './visits'

/**
 * Activité d'un patient (onglet « Activité » de la fiche ; fichier 02 : historique jamais écrasé). Règles
 * pures de présentation : ni Vue, ni réseau, ni stockage. Le serveur n'envoie que les sections que le
 * compte peut voir ; ces fonctions ne nomment donc jamais une section qui n'est pas dans la réponse.
 *
 * Aucune valeur clinique n'arrive dans la liste (ni motif de consultation, ni mesure, ni médicament) :
 * date, état, professionnel, type et numéro seulement. Une facture dit son solde PROJETÉ par le serveur,
 * jamais « payée » : un solde nul peut venir d'un avoir autant que d'un encaissement.
 */
export type ActivitySection = NonNullable<PatientActivityMeta['section']>

export const ACTIVITY_SECTIONS: readonly ActivitySection[] = [
  'VISITS',
  'EXAMS',
  'PRESCRIPTIONS',
  'FINANCIAL',
]

export const SECTION_LABELS: Readonly<Record<ActivitySection, string>> = {
  VISITS: 'Visites',
  EXAMS: 'Examens',
  PRESCRIPTIONS: 'Ordonnances',
  FINANCIAL: 'Pièces financières',
}

/** Vide CONFIRMÉ par le serveur pour une section visible (jamais dit pour une section absente). */
export const SECTION_EMPTY: Readonly<Record<ActivitySection, string>> = {
  VISITS: 'Aucune visite enregistrée',
  EXAMS: 'Aucun examen enregistré',
  PRESCRIPTIONS: 'Aucune ordonnance enregistrée',
  FINANCIAL: 'Aucune pièce financière enregistrée',
}

export const ALL_EMPTY = 'Aucune activité enregistrée'

export function isActivitySection(value: unknown): value is ActivitySection {
  return typeof value === 'string' && (ACTIVITY_SECTIONS as readonly string[]).includes(value)
}

/** Section d'un élément de la liste. */
export function sectionOf(item: PatientActivityItem): ActivitySection {
  switch (item.type) {
    case 'VISIT':
      return 'VISITS'
    case 'CONSULTATION':
      return 'EXAMS'
    case 'PRESCRIPTION':
    case 'MEDICATION_ORDER':
      return 'PRESCRIPTIONS'
    default:
      return 'FINANCIAL'
  }
}

/** Clé stable d'un élément (une consultation et son ordonnance de médicaments partagent l'identifiant). */
export function itemKey(item: PatientActivityItem): string {
  return `${item.type}:${item.id}`
}

export const METHOD_LABELS: Readonly<Record<PaymentsMethod, string>> = {
  CASH: 'Espèces',
  MTN_MOMO: 'MTN Mobile Money',
  ORANGE_MONEY: 'Orange Money',
  BANK_TRANSFER: 'Virement bancaire',
  CHEQUE: 'Chèque',
  OTHER: 'Autre mode',
}

export function methodLabel(method: string): string {
  return Object.prototype.hasOwnProperty.call(METHOD_LABELS, method)
    ? METHOD_LABELS[method as PaymentsMethod]
    : `Mode inconnu (${method})`
}

const PRESCRIPTION_STATUS: Readonly<Record<string, string>> = {
  DRAFT: 'Brouillon',
  VALIDATED: 'Validée',
}

function prescriptionStatusLabel(status: string): string {
  return PRESCRIPTION_STATUS[status] ?? `État inconnu (${status})`
}

/** Titre d'un élément : sa nature et, pour une pièce, son numéro. */
export function itemTitle(item: PatientActivityItem): string {
  switch (item.type) {
    case 'VISIT':
      return `Visite : ${reasonLabel(item.reason)}`
    case 'CONSULTATION':
      return 'Examen de vue'
    case 'PRESCRIPTION':
      return item.origin === 'EXTERNAL'
        ? `Ordonnance externe transcrite, version ${item.version_no}`
        : `Prescription optique, version ${item.version_no}`
    case 'MEDICATION_ORDER':
      return 'Ordonnance de médicaments'
    case 'INVOICE':
      return `Facture ${item.number}`
    case 'RECEIPT':
      return `Reçu ${item.number}`
    case 'CREDIT_NOTE':
      return `Avoir ${item.number}`
    case 'REFUND':
      return `Remboursement ${item.number}`
  }
}

/**
 * Situation d'une facture d'après son solde projeté par le serveur. Jamais « payée » : « solde nul » dit
 * seulement qu'il ne reste rien à encaisser (encaissements ou avoirs).
 */
export function settlementText(invoice: PatientActivityInvoice): string {
  switch (invoice.settlement) {
    case 'UNPAID':
      return `Reste dû : ${formatMoney(invoice.balance_due)} (aucun encaissement net)`
    case 'PARTIALLY_PAID':
      return `Reste dû : ${formatMoney(invoice.balance_due)} (encaissé : ${formatMoney(invoice.paid_net)})`
    case 'SETTLED':
      return `Solde nul : reste dû ${formatMoney(0)}`
    case 'IN_CREDIT':
      return `Crédit à rembourser : ${formatMoney(invoice.credit_available)}`
  }
}

/** Date civile en français (« 30/09/2026 »), sans fuseau : c'est une date du calendrier. */
function civilDateFr(iso: string): string {
  const date = CivilDate.fromString(iso)
  return `${String(date.day).padStart(2, '0')}/${String(date.month).padStart(2, '0')}/${String(date.year)}`
}

function invoiceReference(number: string | null): string {
  return number === null ? 'Facture sans numéro' : `Facture ${number}`
}

/**
 * Lignes de détail d'un élément, dans l'ordre d'affichage. Identifiants, états, montants et numéros
 * seulement : aucune valeur clinique n'est disponible ni affichée.
 */
export function itemDetails(item: PatientActivityItem): string[] {
  switch (item.type) {
    case 'VISIT':
      return [`Statut : ${statusLabel(item.status)}`]
    case 'CONSULTATION':
      return [`État : ${workStatusLabel(item.status)}`, `Professionnel : ${item.clinician_name}`]
    case 'PRESCRIPTION': {
      const lines = [`État : ${prescriptionStatusLabel(item.status)}`]
      if (item.origin === 'EXTERNAL' && item.external_prescribed_on !== null) {
        lines.push(`Prescrite à l’extérieur le ${civilDateFr(item.external_prescribed_on)}`)
      }
      return lines
    }
    case 'MEDICATION_ORDER':
      return [`Consultation : ${workStatusLabel(item.consultation_status)}`]
    case 'INVOICE': {
      const lines = [`Montant : ${formatMoney(item.total_gross)}`, settlementText(item)]
      if (item.origin === 'LEGACY_BALANCE') lines.push('Créance reprise du papier')
      return lines
    }
    case 'RECEIPT': {
      const lines = [
        `Encaissement : ${formatMoney(item.amount)} (${methodLabel(item.method)})`,
        invoiceReference(item.invoice_number),
      ]
      if (item.reversed_amount > 0) {
        lines.push(`Contre-passé : ${formatMoney(item.reversed_amount)} (le reçu reste conservé)`)
      }
      return lines
    }
    case 'CREDIT_NOTE':
      return [`Montant : ${formatMoney(item.amount_gross)}`, invoiceReference(item.invoice_number)]
    case 'REFUND':
      return [
        `Remboursé : ${formatMoney(item.amount)} (${methodLabel(item.method)})`,
        invoiceReference(item.invoice_number),
      ]
  }
}
