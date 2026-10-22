import type {
  Approval,
  ApprovalsStatus,
  ApprovalSubject,
  CreditNoteSubject,
  DecideApprovalPayload,
  DraftLineRightsSubject,
  ExamFeeWaiverSubject,
  PaymentReversalSubject,
  RefundsMethod,
  RefundSubject,
  RequestApprovalPayload,
} from '../contracts/generated'
import { RuleViolation } from '../errors'
import { optionalText } from '../values/text'

/**
 * Approbations exactes (B06 ; fichier 03 : « Approbation : demandeur, opération cible, contenu exact ou
 * empreinte, motif, montant, décision gérante, date et expiration »). Règles pures : ni Vue, ni réseau,
 * ni stockage.
 *
 * L'écran décrit le CONTENU exact de l'opération bloquée (`subject`) ; le serveur en dérive l'action, la
 * cible et l'empreinte, puis reconnaît la même opération au moment de l'exécuter. Une demande ne vaut que
 * pour CE contenu : un montant, une ligne ou un mode différents exigent une nouvelle demande. Aucun texte
 * ne dit « payé », « soldé » ni « gratuit » : une dispense n'est qu'une autorisation de démarrer l'examen.
 */
export const REASON_MIN_LENGTH = 3
export const REASON_MAX_LENGTH = 1000
export const DECISION_REASON_MAX_LENGTH = 4000

export type ApprovalKind = ApprovalSubject['kind']

export const KIND_LABELS: Readonly<Record<ApprovalKind, string>> = {
  EXAM_FEE_WAIVER: 'Dispense des frais d’examen',
  DRAFT_LINE_RIGHTS: 'Prix ou remise d’un brouillon de facture',
  PAYMENT_REVERSAL: 'Contre-écriture d’un paiement',
  CREDIT_NOTE: 'Avoir sur une facture émise',
  REFUND: 'Remboursement d’un crédit',
}

export function kindLabel(kind: Approval['kind']): string {
  return kind === null ? 'Autorisation directe de la gérante' : KIND_LABELS[kind]
}

export const STATUS_LABELS: Readonly<Record<ApprovalsStatus, string>> = {
  REQUESTED: 'En attente de la gérante',
  APPROVED: 'Accordée, pas encore utilisée',
  REFUSED: 'Refusée',
  EXPIRED: 'Échue',
  CONSUMED: 'Utilisée',
}

/** Symbole de chaque statut : la couleur n'est jamais le seul signal. */
export const STATUS_SYMBOLS: Readonly<Record<ApprovalsStatus, string>> = {
  REQUESTED: '…',
  APPROVED: '✓',
  REFUSED: '✕',
  EXPIRED: '⌛',
  CONSUMED: '●',
}

export function statusLabel(status: string): string {
  return Object.prototype.hasOwnProperty.call(STATUS_LABELS, status)
    ? STATUS_LABELS[status as ApprovalsStatus]
    : `Statut inconnu (${status})`
}

export function statusSymbol(status: string): string {
  return Object.prototype.hasOwnProperty.call(STATUS_SYMBOLS, status)
    ? STATUS_SYMBOLS[status as ApprovalsStatus]
    : '?'
}

/** Seule une demande en attente (statut EFFECTIF : une demande échue ne se décide plus) se décide. */
export function isDecidable(approval: Pick<Approval, 'effective_status'>): boolean {
  return approval.effective_status === 'REQUESTED'
}

/** Filtres de la liste de la gérante. */
export type ApprovalFilter = 'REQUESTED' | 'ALL'

// --- Contenu exact des opérations -------------------------------------------------------------------

export function examFeeWaiverSubject(visitId: string, patientId: string): ExamFeeWaiverSubject {
  return { kind: 'EXAM_FEE_WAIVER', visit_id: visitId, patient_id: patientId }
}

/** Ligne d'un brouillon telle que la commande `CreateInvoiceDraft` / `UpdateDraft` la porte. */
export interface DraftLinePayload {
  line_id?: string
  position: number
  catalog_item_id: string | null
  description: string
  quantity: string
  unit_price_gross: number
  discount_gross: number
  prescription_id: string | null
}

/**
 * Contenu d'une demande de prix ou de remise : TOUTES les lignes du brouillon telles qu'elles seront
 * envoyées (sans leurs identifiants). Le brouillon s'enregistre ensuite avec exactement ces lignes.
 */
export function draftLineRightsSubject(
  invoiceId: string,
  patientId: string,
  lines: readonly DraftLinePayload[],
): DraftLineRightsSubject {
  if (lines.length === 0) {
    throw RuleViolation.invalid(
      'lines',
      'approval.subject_lines_required',
      'Le brouillon à approuver comporte au moins une ligne.',
    )
  }
  const mapped = lines.map((line) => ({
    position: line.position,
    catalog_item_id: line.catalog_item_id,
    description: line.description,
    quantity: line.quantity,
    unit_price_gross: line.unit_price_gross,
    discount_gross: line.discount_gross,
    prescription_id: line.prescription_id,
  }))
  return {
    kind: 'DRAFT_LINE_RIGHTS',
    invoice_id: invoiceId,
    patient_id: patientId,
    lines: mapped as DraftLineRightsSubject['lines'],
  }
}

export function paymentReversalSubject(
  invoiceId: string,
  paymentId: string,
  amount: number,
): PaymentReversalSubject {
  return { kind: 'PAYMENT_REVERSAL', invoice_id: invoiceId, payment_id: paymentId, amount }
}

export interface CreditNoteLineInput {
  invoice_line_id: string
  quantity: string | null
  amount_gross: number
}

export function creditNoteSubject(
  invoiceId: string,
  lines: readonly CreditNoteLineInput[],
): CreditNoteSubject {
  if (lines.length === 0) {
    throw RuleViolation.invalid(
      'lines',
      'approval.subject_lines_required',
      'Un avoir porte au moins une ligne de la facture.',
    )
  }
  const amount = lines.reduce((sum, line) => sum + line.amount_gross, 0)
  return {
    kind: 'CREDIT_NOTE',
    invoice_id: invoiceId,
    amount_gross: amount,
    lines: lines.map((line) => ({ ...line })) as CreditNoteSubject['lines'],
  }
}

export function refundSubject(
  invoiceId: string,
  creditNoteId: string,
  amount: number,
  method: RefundsMethod,
): RefundSubject {
  return {
    kind: 'REFUND',
    invoice_id: invoiceId,
    credit_note_id: creditNoteId,
    amount,
    method,
  }
}

// --- Demande et décision ----------------------------------------------------------------------------

/** Charge `RequestApproval` (alias d'objet : transmissible au répartiteur de mutations). */
export type RequestApprovalRequestPayload = {
  [K in keyof RequestApprovalPayload]: RequestApprovalPayload[K]
}

/** Charge `DecideApproval`. */
export type DecideApprovalRequestPayload = {
  [K in keyof DecideApprovalPayload]: DecideApprovalPayload[K]
}

export type ReasonValidation = { ok: true; reason: string } | { ok: false; error: string }

/** Motif d'une demande : obligatoire, 3 à 1000 caractères (le serveur revérifie). */
export function validateRequestReason(text: string): ReasonValidation {
  try {
    const reason = optionalText(text, REASON_MAX_LENGTH, 'reason')
    if (reason === null) return { ok: false, error: 'Indiquez le motif de la demande.' }
    if ([...reason].length < REASON_MIN_LENGTH) {
      return { ok: false, error: `Le motif compte au moins ${REASON_MIN_LENGTH} caractères.` }
    }
    return { ok: true, reason }
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return { ok: false, error: `Le motif ne peut pas dépasser ${REASON_MAX_LENGTH} caractères.` }
  }
}

export function buildRequestApprovalPayload(
  approvalId: string,
  subject: ApprovalSubject,
  reason: string,
  expiresAt: string | null = null,
): RequestApprovalRequestPayload {
  return { approval_id: approvalId, subject, reason, expires_at: expiresAt }
}

export type DecisionValidation =
  { ok: true; payload: DecideApprovalRequestPayload } | { ok: false; error: string }

/**
 * Décision de la gérante : un refus exige un motif (il est rendu à la personne qui a demandé), un accord
 * l'admet facultatif.
 */
export function buildDecision(
  approvalId: string,
  decision: 'APPROVED' | 'REFUSED',
  text: string,
): DecisionValidation {
  let reason: string | null
  try {
    reason = optionalText(text, DECISION_REASON_MAX_LENGTH, 'reason')
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return {
      ok: false,
      error: `Le motif ne peut pas dépasser ${DECISION_REASON_MAX_LENGTH} caractères.`,
    }
  }
  if (decision === 'REFUSED' && reason === null) {
    return {
      ok: false,
      error: 'Indiquez le motif du refus : il est rendu à la personne qui a demandé.',
    }
  }
  return { ok: true, payload: { approval_id: approvalId, decision, reason } }
}

// --- Description du contenu (écran de la gérante) ----------------------------------------------------

export interface SubjectLine {
  label: string
  value: string
}

/**
 * Lignes lisibles du contenu exact vérifié par le serveur (montants mis en forme par l'appelant). Un
 * contenu absent (non vérifiable, ou exécution directe) est dit tel quel : rien n'est deviné.
 */
export function describeSubject(
  subject: ApprovalSubject | null,
  formatMoney: (amount: number) => string,
  methodLabel: (method: string) => string,
): SubjectLine[] {
  if (subject === null) {
    return [{ label: 'Contenu', value: 'Contenu exact non vérifiable : ne pas décider sur cette base.' }]
  }
  switch (subject.kind) {
    case 'EXAM_FEE_WAIVER':
      return [{ label: 'Opération', value: 'Démarrer l’examen de cette visite sans facture de frais réglée' }]
    case 'DRAFT_LINE_RIGHTS':
      return subject.lines.map((line) => ({
        label: `Ligne ${line.position}`,
        value: `${line.description} — ${line.quantity} × ${formatMoney(line.unit_price_gross)}${
          line.discount_gross > 0 ? `, remise ${formatMoney(line.discount_gross)}` : ''
        }${line.catalog_item_id === null ? ' (ligne libre)' : ''}`,
      }))
    case 'PAYMENT_REVERSAL':
      return [{ label: 'Montant contre-passé', value: formatMoney(subject.amount) }]
    case 'CREDIT_NOTE':
      return [
        { label: 'Montant de l’avoir', value: formatMoney(subject.amount_gross) },
        { label: 'Lignes visées', value: String(subject.lines.length) },
      ]
    case 'REFUND':
      return [
        { label: 'Montant remboursé', value: formatMoney(subject.amount) },
        { label: 'Mode', value: methodLabel(subject.method) },
      ]
  }
}

/** Champ de la demande qui porte une erreur du serveur (clé de `error.fields`), ou null. */
export function requestFieldFromServer(key: string): 'reason' | null {
  return key === 'reason' ? 'reason' : null
}
