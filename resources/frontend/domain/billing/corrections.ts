import type {
  CreditNoteSubject,
  Invoice,
  InvoiceBalance,
  InvoiceLine,
  InvoicePayment,
  IssueCreditNotePayload,
  RefundCreditPayload,
  RefundsMethod,
  ReversePaymentPayload,
} from '../contracts/generated'
import { RuleViolation } from '../errors'
import { positiveMoney } from '../values/money'
import { trimBlanks } from '../values/text'

/**
 * Corrections d'une facture ÉMISE (B07, B08), EN LIGNE : contre-écriture d'un paiement enregistré à tort,
 * avoir lié aux lignes d'origine, remboursement d'un crédit. Contrôles d'écran et charges des commandes ;
 * le serveur refait tout sous verrou (cumuls, crédit disponible, session de caisse, approbation exacte).
 *
 * Distinction (fichier 03) : erreur d'encaissement → contre-écriture (ce n'est pas une sortie d'argent) ;
 * réduction de la vente → avoir (la facture, ses lignes et ses reçus ne changent pas) ; argent rendu au patient →
 * remboursement d'un avoir. Le paiement, le reçu et son numéro restent toujours tels qu'émis.
 *
 * Montants entiers en FCFA (aucun calcul flottant) ; quantités en chaînes décimales exactes. Vocabulaire :
 * jamais « payé », « soldé », « acquitté », « gratuit ».
 */

export const CORRECTION_REASON_MIN_LENGTH = 3
export const CORRECTION_REASON_MAX_LENGTH = 1000
export const LINE_REASON_MAX_LENGTH = 4000
export const REFUND_REFERENCE_MAX_LENGTH = 180

export const REFUND_METHODS: readonly RefundsMethod[] = [
  'CASH',
  'MTN_MOMO',
  'ORANGE_MONEY',
  'BANK_TRANSFER',
  'CHEQUE',
  'OTHER',
]

/** Modes dont la référence est obligatoire par défaut, comme pour l'encaissement (le serveur tranche). */
export const REFUND_REFERENCE_REQUIRED: ReadonlySet<RefundsMethod> = new Set<RefundsMethod>([
  'MTN_MOMO',
  'ORANGE_MONEY',
  'BANK_TRANSFER',
])

export function isRefundMethod(value: string | null): value is RefundsMethod {
  return value !== null && (REFUND_METHODS as readonly string[]).includes(value)
}

type ReasonCheck = { ok: true; reason: string } | { ok: false; error: string }

/** Motif obligatoire d'une correction (3 à 1000 caractères, blancs de bord retirés). */
export function checkCorrectionReason(text: string): ReasonCheck {
  const reason = trimBlanks(text)
  if (reason.length < CORRECTION_REASON_MIN_LENGTH) {
    return {
      ok: false,
      error: `Indiquez le motif (au moins ${CORRECTION_REASON_MIN_LENGTH} caractères).`,
    }
  }
  if (reason.length > CORRECTION_REASON_MAX_LENGTH) {
    return {
      ok: false,
      error: `Le motif compte au plus ${CORRECTION_REASON_MAX_LENGTH} caractères.`,
    }
  }
  return { ok: true, reason }
}

function amountError(amount: number | null, ceiling: number, overMessage: string): string | null {
  if (amount === null) return 'Le montant est obligatoire.'
  try {
    positiveMoney(amount, 'amount')
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return error.message
  }
  return amount > ceiling ? overMessage : null
}

// --- Contre-écriture (B07) ---------------------------------------------------------------------------

/** Part d'un paiement confirmé encore contre-passable (montant − contre-écritures déjà enregistrées). */
export function reversibleAmount(
  payment: Pick<InvoicePayment, 'amount' | 'reversed_amount' | 'status'>,
): number {
  if (payment.status !== 'CONFIRMED') return 0
  return Math.max(0, payment.amount - payment.reversed_amount)
}

export type ReversalField = 'amount' | 'reason'
export type ReversalErrors = Partial<Record<ReversalField, string>>

export function validateReversal(
  form: { amount: number | null; reason: string },
  reversible: number,
): { ok: true; amount: number; reason: string } | { ok: false; errors: ReversalErrors } {
  const errors: ReversalErrors = {}
  if (reversible <= 0) {
    errors.amount = 'Ce paiement est déjà entièrement contre-passé.'
  } else {
    const message = amountError(
      form.amount,
      reversible,
      'Le montant dépasse la part encore contre-passable de ce paiement.',
    )
    if (message !== null) errors.amount = message
  }
  const reason = checkCorrectionReason(form.reason)
  if (!reason.ok) errors.reason = reason.error
  if (Object.keys(errors).length > 0 || form.amount === null || !reason.ok) {
    return { ok: false, errors }
  }
  return { ok: true, amount: form.amount, reason: reason.reason }
}

/** Charge `ReversePayment` (alias d'objet : transmissible au répartiteur de mutations). */
export type ReversalRequestPayload = {
  [K in keyof ReversePaymentPayload]: ReversePaymentPayload[K]
}

export function buildReversalPayload(input: {
  invoiceId: string
  paymentId: string
  reversalId: string
  amount: number
  reason: string
  approvalId: string
}): ReversalRequestPayload {
  return {
    invoice_id: input.invoiceId,
    payment_id: input.paymentId,
    reversal_id: input.reversalId,
    amount: input.amount,
    reason: input.reason,
    approval_id: input.approvalId,
  }
}

// --- Avoir (B07) -------------------------------------------------------------------------------------

/** Part de la facture encore créditable (total − avoirs émis), selon la projection du serveur. */
export function creditableTotal(balance: Pick<InvoiceBalance, 'total_gross' | 'credited'>): number {
  return Math.max(0, balance.total_gross - balance.credited)
}

export interface CreditLineForm {
  invoiceLineId: string
  /** Montant crédité sur cette ligne (FCFA), null : ligne non retenue. */
  amount: number | null
  /** Quantité indiquée (chaîne décimale exacte) ou null. */
  quantity: string | null
}

export type CreditNoteErrors = {
  lines?: string
  reason?: string
  /** Erreur par ligne, clé = identifiant de la ligne de facture. */
  byLine?: Readonly<Record<string, string>>
}

const QUANTITY_PATTERN = /^(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$/
const ZERO_QUANTITY = /^0(\.0{1,3})?$/

export function validateCreditNote(
  form: { lines: readonly CreditLineForm[]; reason: string },
  invoice: Pick<Invoice, 'lines' | 'balance'>,
):
  | {
      ok: true
      lines: { invoiceLineId: string; amount: number; quantity: string | null }[]
      total: number
      reason: string
    }
  | { ok: false; errors: CreditNoteErrors } {
  const errors: CreditNoteErrors = {}
  const byLine: Record<string, string> = {}
  const origin = new Map<string, InvoiceLine>(invoice.lines.map((line) => [line.id, line]))
  const kept: { invoiceLineId: string; amount: number; quantity: string | null }[] = []
  let total = 0
  for (const line of form.lines) {
    if (line.amount === null) continue
    const source = origin.get(line.invoiceLineId)
    if (source === undefined) {
      byLine[line.invoiceLineId] = 'Cette ligne n’appartient pas à la facture.'
      continue
    }
    const message = amountError(
      line.amount,
      source.line_total_gross,
      'Le montant dépasse le montant de la ligne d’origine.',
    )
    if (message !== null) {
      byLine[line.invoiceLineId] = message
      continue
    }
    const quantity = line.quantity === null ? null : trimBlanks(line.quantity)
    if (quantity !== null && quantity !== '') {
      if (!QUANTITY_PATTERN.test(quantity) || ZERO_QUANTITY.test(quantity)) {
        byLine[line.invoiceLineId] =
          'La quantité est un nombre positif (au plus trois décimales, point décimal).'
        continue
      }
    }
    kept.push({
      invoiceLineId: line.invoiceLineId,
      amount: line.amount,
      quantity: quantity === null || quantity === '' ? null : quantity,
    })
    total += line.amount
  }
  if (Object.keys(byLine).length > 0) errors.byLine = byLine
  if (kept.length === 0 && errors.byLine === undefined) {
    errors.lines = 'Choisissez au moins une ligne de la facture et le montant à créditer.'
  } else if (total > creditableTotal(invoice.balance)) {
    errors.lines = 'Le total de l’avoir dépasse la part encore créditable de la facture.'
  }
  const reason = checkCorrectionReason(form.reason)
  if (!reason.ok) errors.reason = reason.error
  if (Object.keys(errors).length > 0 || !reason.ok) return { ok: false, errors }
  return { ok: true, lines: kept, total, reason: reason.reason }
}

/** Charge `IssueCreditNote` sans le numéro (le répartiteur met `number: null` : le serveur numérote). */
export type CreditNoteRequestPayload = Omit<IssueCreditNotePayload, 'number'>

export function buildCreditNotePayload(input: {
  invoiceId: string
  creditNoteId: string
  numberUsageId: string
  documentId: string
  approvalId: string
  reason: string
  lines: readonly {
    lineId: string
    invoiceLineId: string
    amount: number
    quantity: string | null
  }[]
}): CreditNoteRequestPayload {
  const lines = input.lines.map((line) => ({
    credit_note_line_id: line.lineId,
    invoice_line_id: line.invoiceLineId,
    quantity: line.quantity,
    amount_gross: line.amount,
    reason: null,
  }))
  return {
    invoice_id: input.invoiceId,
    credit_note_id: input.creditNoteId,
    number_usage_id: input.numberUsageId,
    amount_gross: lines.reduce((sum, line) => sum + line.amount_gross, 0),
    reason: input.reason,
    approval_id: input.approvalId,
    document_id: input.documentId,
    lines: lines as CreditNoteRequestPayload['lines'],
  }
}

/** Contenu exact de la demande d'approbation d'un avoir : les mêmes lignes que la commande. */
export function creditNoteSubjectLines(
  lines: readonly { invoiceLineId: string; amount: number; quantity: string | null }[],
): CreditNoteSubject['lines'] {
  return lines.map((line) => ({
    invoice_line_id: line.invoiceLineId,
    quantity: line.quantity,
    amount_gross: line.amount,
  })) as CreditNoteSubject['lines']
}

// --- Remboursement (B08) -----------------------------------------------------------------------------

/**
 * Montant remboursable sur un avoir : au plus la part de l'avoir non encore remboursée ET le crédit
 * disponible de la facture (projection du serveur).
 */
export function refundableAmount(
  note: { amount_gross: number; refunded_amount?: number; status: string },
  balance: Pick<InvoiceBalance, 'credit_available'>,
): number {
  if (note.status !== 'ISSUED') return 0
  const remaining = Math.max(0, note.amount_gross - (note.refunded_amount ?? 0))
  return Math.min(remaining, Math.max(0, balance.credit_available))
}

export type RefundField = 'amount' | 'method' | 'reference' | 'cashSession'
export type RefundErrors = Partial<Record<RefundField, string>>

export function validateRefund(
  form: { amount: number | null; method: RefundsMethod | null; reference: string },
  context: { refundable: number; cashSessionId: string | null },
):
  | { ok: true; amount: number; method: RefundsMethod; reference: string | null }
  | { ok: false; errors: RefundErrors } {
  const errors: RefundErrors = {}
  if (context.refundable <= 0) {
    errors.amount = 'Aucun crédit à rembourser sur cet avoir.'
  } else {
    const message = amountError(
      form.amount,
      context.refundable,
      'Le montant dépasse le crédit remboursable de cet avoir.',
    )
    if (message !== null) errors.amount = message
  }
  if (form.method === null) errors.method = 'Choisissez le mode de remboursement.'
  const reference = trimBlanks(form.reference)
  if (reference.length > REFUND_REFERENCE_MAX_LENGTH) {
    errors.reference = `La référence compte au plus ${REFUND_REFERENCE_MAX_LENGTH} caractères.`
  } else if (
    reference === '' &&
    form.method !== null &&
    REFUND_REFERENCE_REQUIRED.has(form.method)
  ) {
    errors.reference =
      'La référence est obligatoire pour ce mode (numéro de transaction ou de virement).'
  }
  if (form.method === 'CASH' && context.cashSessionId === null) {
    errors.cashSession =
      'Un remboursement en espèces exige une session de caisse ouverte : ouvrez la caisse d’abord.'
  }
  if (Object.keys(errors).length > 0 || form.amount === null || form.method === null) {
    return { ok: false, errors }
  }
  return {
    ok: true,
    amount: form.amount,
    method: form.method,
    reference: reference === '' ? null : reference,
  }
}

/** Charge `RefundCredit` sans le numéro (le répartiteur met `number: null` : le serveur numérote). */
export type RefundRequestPayload = Omit<RefundCreditPayload, 'number'>

export function buildRefundPayload(input: {
  invoiceId: string
  refundId: string
  creditNoteId: string
  numberUsageId: string
  documentId: string
  approvalId: string
  amount: number
  method: RefundsMethod
  reference: string | null
  effectiveAt: string
  cashSessionId: string | null
}): RefundRequestPayload {
  return {
    invoice_id: input.invoiceId,
    refund_id: input.refundId,
    credit_note_id: input.creditNoteId,
    number_usage_id: input.numberUsageId,
    amount: input.amount,
    method: input.method,
    reference: input.reference,
    approval_id: input.approvalId,
    effective_at: input.effectiveAt,
    cash_session_id: input.method === 'CASH' ? input.cashSessionId : null,
    document_id: input.documentId,
  }
}
