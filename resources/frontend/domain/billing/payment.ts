import type { InvoiceBalance, PaymentsMethod, RegisterPaymentPayload } from '../contracts/generated'
import { RuleViolation } from '../errors'
import { positiveMoney } from '../values/money'
import { trimBlanks } from '../values/text'

/**
 * Encaissement d'une facture émise (B04), EN LIGNE : saisie, contrôles d'écran et charge de
 * `RegisterPayment`. Le serveur décide : montant au plus le reste dû verrouillé (409
 * `PAYMENT_EXCEEDS_BALANCE` avec `amount_due`), `ledger_version` attendue (409 `VERSION_CONFLICT`),
 * session de caisse ouverte de l'auteur pour les espèces, référence pour les modes désignés. Un
 * encaissement constaté par l'opératrice n'est jamais vérifié auprès d'un opérateur télécom en L0.
 *
 * Vocabulaire : jamais « payé », « soldé », « acquitté », « gratuit ». Les montants affichés viennent
 * de la projection du serveur (`balance`) : « Total encaissé » (`paid_net`) et « Reste à encaisser »
 * (`balance_due`).
 */
export const PAYMENT_METHODS: readonly PaymentsMethod[] = [
  'CASH',
  'MTN_MOMO',
  'ORANGE_MONEY',
  'BANK_TRANSFER',
  'CHEQUE',
  'OTHER',
]

/**
 * Modes dont la référence est obligatoire par défaut (`EEO_PAYMENT_REFERENCE_REQUIRED` du serveur,
 * paramétrable) : l'écran le signale, le serveur tranche.
 */
export const REFERENCE_REQUIRED_METHODS: ReadonlySet<PaymentsMethod> = new Set<PaymentsMethod>([
  'MTN_MOMO',
  'ORANGE_MONEY',
  'BANK_TRANSFER',
])

export const REFERENCE_MAX_LENGTH = 180

export function isPaymentMethod(value: string | null): value is PaymentsMethod {
  return value !== null && (PAYMENT_METHODS as readonly string[]).includes(value)
}

/**
 * Référence obligatoire pour ce mode ? Liste du serveur (`GET /sales/settings`) quand elle est lue, sinon la
 * liste par défaut : l'écran le signale, le serveur tranche toujours.
 */
export function referenceRequired(
  method: PaymentsMethod | null,
  required: readonly PaymentsMethod[] | null = null,
): boolean {
  if (method === null) return false
  return required === null ? REFERENCE_REQUIRED_METHODS.has(method) : required.includes(method)
}

export interface PaymentForm {
  amount: number | null
  method: PaymentsMethod | null
  reference: string
}

export type PaymentField = 'amount' | 'method' | 'reference' | 'cashSession'

export type PaymentErrors = Partial<Record<PaymentField, string>>

export interface PaymentContext {
  /** Reste à encaisser de la projection du serveur (`balance.balance_due`). */
  amountDue: number
  /** Session de caisse ouverte du compte (espèces), ou null. */
  cashSessionId: string | null
  /** Modes à référence obligatoire lus sur le serveur ; absent ou null : liste par défaut. */
  referenceRequiredMethods?: readonly PaymentsMethod[] | null
}

/** Contrôles d'écran avant l'envoi ; le serveur refait tout sous verrou. */
export function validatePayment(
  form: PaymentForm,
  context: PaymentContext,
):
  | { ok: true; amount: number; method: PaymentsMethod; reference: string | null }
  | {
      ok: false
      errors: PaymentErrors
    } {
  const errors: PaymentErrors = {}
  if (context.amountDue <= 0) {
    errors.amount = 'Il ne reste rien à encaisser sur cette facture.'
  } else if (form.amount === null) {
    errors.amount = 'Le montant est obligatoire.'
  } else {
    try {
      positiveMoney(form.amount, 'amount')
      if (form.amount > context.amountDue) {
        errors.amount = 'Le montant dépasse le reste à encaisser de cette facture.'
      }
    } catch (error) {
      if (!(error instanceof RuleViolation)) throw error
      errors.amount = error.message
    }
  }
  if (form.method === null) errors.method = 'Choisissez le mode de règlement.'
  const reference = trimBlanks(form.reference)
  if (reference.length > REFERENCE_MAX_LENGTH) {
    errors.reference = `La référence compte au plus ${REFERENCE_MAX_LENGTH} caractères.`
  } else if (
    reference === '' &&
    referenceRequired(form.method, context.referenceRequiredMethods ?? null)
  ) {
    errors.reference =
      'La référence est obligatoire pour ce mode de règlement (numéro de transaction ou de virement).'
  }
  if (form.method === 'CASH' && context.cashSessionId === null) {
    errors.cashSession =
      'Un encaissement en espèces exige une session de caisse ouverte : ouvrez la caisse d’abord.'
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

export interface PaymentEffects {
  paymentId: string
  receiptId: string
  receiptNumberUsageId: string
  receiptDocumentId: string
}

export type PaymentRequestPayload = Omit<RegisterPaymentPayload, 'receipt_number'>

/**
 * Charge de `RegisterPayment` sans le numéro de reçu (le répartiteur met `receipt_number: null` : le
 * serveur numérote, jamais renuméroté). Identifiants des effets fixés par l'écran pour cette intention :
 * une reprise (double clic, coupure) rejoue la même commande, jamais un second encaissement.
 */
export function buildPaymentPayload(input: {
  invoiceId: string
  amount: number
  method: PaymentsMethod
  reference: string | null
  effectiveAt: string
  cashSessionId: string | null
  effects: PaymentEffects
}): PaymentRequestPayload {
  return {
    invoice_id: input.invoiceId,
    payment_id: input.effects.paymentId,
    receipt_id: input.effects.receiptId,
    receipt_number_usage_id: input.effects.receiptNumberUsageId,
    amount: input.amount,
    currency: 'XAF',
    method: input.method,
    reference: input.reference,
    effective_at: input.effectiveAt,
    cash_session_id: input.method === 'CASH' ? input.cashSessionId : null,
    retroactive_reason: null,
    receipt_document_id: input.effects.receiptDocumentId,
  }
}

/** Lignes du récapitulatif d'une facture, depuis la projection du serveur (jamais recalculée ici). */
export interface BalanceLine {
  key: 'total' | 'collected' | 'credited' | 'refunded' | 'due' | 'credit'
  label: string
  amount: number
  emphasis: boolean
}

export function balanceLines(balance: InvoiceBalance): BalanceLine[] {
  const lines: BalanceLine[] = [
    { key: 'total', label: 'Total de la facture', amount: balance.total_gross, emphasis: false },
    { key: 'collected', label: 'Total encaissé', amount: balance.paid_net, emphasis: false },
  ]
  if (balance.credited > 0) {
    lines.push({ key: 'credited', label: 'Avoirs', amount: balance.credited, emphasis: false })
  }
  if (balance.refunded > 0) {
    lines.push({ key: 'refunded', label: 'Remboursé', amount: balance.refunded, emphasis: false })
  }
  lines.push({
    key: 'due',
    label: 'Reste à encaisser',
    amount: Math.max(0, balance.balance_due),
    emphasis: true,
  })
  if (balance.credit_available > 0) {
    lines.push({
      key: 'credit',
      label: 'Crédit en faveur du patient',
      amount: balance.credit_available,
      emphasis: false,
    })
  }
  return lines
}

/** Nature d'un reçu, calculée par le serveur (`DEPOSIT` ou `BALANCE_SETTLEMENT`), jamais saisie. */
export function receiptKindLabel(kind: 'DEPOSIT' | 'BALANCE_SETTLEMENT'): string {
  return kind === 'DEPOSIT' ? 'Acompte' : 'Règlement du solde'
}

/**
 * Mots qu'aucun écran de caisse n'affiche (fichier 03, D01) : un solde nul n'est pas une preuve de
 * paiement, et « gratuit » n'existe pas sans pièce. Les tests relisent les textes rendus.
 */
export const FORBIDDEN_SETTLEMENT_WORDS: readonly string[] = [
  'payé',
  'payée',
  'soldé',
  'soldée',
  'acquitté',
  'acquittée',
  'gratuit',
]
