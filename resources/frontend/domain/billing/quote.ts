import type {
  ConvertQuoteRequest,
  CreateQuoteDraftRequest,
  IssueQuoteRequest,
  Quote,
  QuoteDecisionRequest,
  QuoteRecipient,
  QuoteRevision,
  QuotesStatus,
  ReviseQuoteRequest,
  UpdateQuoteDraftRequest,
} from '../contracts/generated'
import { Quantity } from '../values/decimal'
import { nonEmpty } from '../values/list'
import {
  type DraftLineError,
  type DraftLineForm,
  draftLinesPayload,
  MAX_DRAFT_LINES,
  validateDraftLines,
} from './draft'

/**
 * Devis côté écran (L1D07) : lignes saisies avec les MÊMES règles qu'un brouillon de facture (`draftTotals`,
 * `validateDraftLines`, jumelles des règles du serveur), charges des commandes en ligne `CreateQuoteDraft`,
 * `UpdateQuoteDraft`, `IssueQuote`, `ReviseQuote`, `RecordQuoteDecision`, `ConvertQuote`, et comparaison des prix
 * d'une révision avec le catalogue actuel avant la conversion. Aucun flottant : quantités en décimal exact, montants
 * en FCFA entiers. Le serveur recalcule et décide (droits, validité, statut).
 *
 * Un devis n'est ni une facture ni un reçu : rien ici ne parle de règlement, de « payé » ou de stock réservé.
 */

/** Unités entières du référentiel (`units.integer_only` = 1), comme pour les lignes relues d'une facture. */
const INTEGER_UNITS: ReadonlySet<string> = new Set(['UNIT', 'PAIR'])

/**
 * Alias structurels des requêtes générées (contenu identique) : la file des commandes (`MutationRequest.payload`)
 * attend un objet indexable, ce qu'une interface n'est pas.
 */
type Payload<T> = Pick<T, keyof T>
export type CreateQuotePayload = Payload<Omit<CreateQuoteDraftRequest, 'expected_version'>>
export type UpdateQuotePayload = Payload<Omit<UpdateQuoteDraftRequest, 'expected_version'>>
export type IssueQuotePayload = Payload<Omit<IssueQuoteRequest, 'expected_version'>>
export type ReviseQuotePayload = Payload<Omit<ReviseQuoteRequest, 'expected_version'>>
export type QuoteDecisionPayload = Payload<Omit<QuoteDecisionRequest, 'expected_version'>>
export type ConvertQuotePayload = Payload<Omit<ConvertQuoteRequest, 'expected_version'>>
type QuoteLinePayload = CreateQuotePayload['lines'][number]

const STATUS_LABELS: Readonly<Record<QuotesStatus, string>> = {
  DRAFT: 'Brouillon',
  ISSUED: 'Émis',
  ACCEPTED: 'Accepté par le client',
  DECLINED: 'Refusé par le client',
  EXPIRED: 'Expiré',
  CONVERTED: 'Converti en facture',
}

const STATUS_SYMBOLS: Readonly<Record<QuotesStatus, string>> = {
  DRAFT: '✎',
  ISSUED: '✓',
  ACCEPTED: '★',
  DECLINED: '✕',
  EXPIRED: '⌛',
  CONVERTED: '→',
}

export function quoteStatusLabel(status: QuotesStatus): string {
  return STATUS_LABELS[status]
}

export function quoteStatusSymbol(status: QuotesStatus): string {
  return STATUS_SYMBOLS[status]
}

/** Titre d'un devis : son numéro, ou « brouillon » tant qu'il n'est pas émis. */
export function quoteTitle(number: string | null): string {
  return number === null ? 'Devis en brouillon (sans numéro)' : `Devis ${number}`
}

/** Destinataire en une ligne : patient (nom, dossier) ou prospect (nom, téléphone). */
export function recipientLabel(recipient: QuoteRecipient): string {
  const name =
    recipient.first_names === null || recipient.first_names.trim() === ''
      ? recipient.last_name
      : `${recipient.last_name} ${recipient.first_names}`
  if (recipient.kind === 'PATIENT') {
    return recipient.record_number === null ? name : `${name} · dossier ${recipient.record_number}`
  }
  return recipient.phone === null ? `${name} (prospect)` : `${name} (prospect, ${recipient.phone})`
}

/** Lignes actuelles d'un devis relu (brouillon ou dernière révision), sous la forme de l'éditeur. */
export function linesFromQuote(quote: Quote): DraftLineForm[] {
  return [...quote.lines]
    .sort((left, right) => left.position - right.position)
    .map((line) => ({
      lineId: line.id,
      catalogItemId: line.catalog_item_id,
      reference: null,
      description: line.description_snapshot,
      unitCode: line.unit_code,
      integerOnly: INTEGER_UNITS.has(line.unit_code),
      stockMode: null,
      catalogPrice: null,
      quantity: Quantity.of(line.quantity).canonical(),
      unitPriceGross: line.unit_price_gross,
      discountGross: line.discount_gross === 0 ? null : line.discount_gross,
      prescriptionId: null,
    }))
}

/**
 * Contrôles de saisie avant l'envoi (le serveur refait tout), avec les messages d'un devis : un devis compte au plus
 * 200 lignes et ne s'émet pas sans ligne.
 */
export function validateQuoteLines(
  lines: readonly DraftLineForm[],
  options: { forIssue?: boolean } = {},
): { errors: DraftLineError[]; general: string | null } {
  const check = validateDraftLines(lines)
  if (lines.length > MAX_DRAFT_LINES) {
    return { errors: check.errors, general: `Un devis compte au plus ${MAX_DRAFT_LINES} lignes.` }
  }
  if (options.forIssue === true && lines.length === 0) {
    return {
      errors: check.errors,
      general: 'Un devis sans ligne ne s’émet pas : ajoutez au moins une ligne.',
    }
  }
  return check
}

/** Lignes de la charge : positions 1..n dans l'ordre affiché, remise vide = 0, aucune prescription. */
export function quoteLinesPayload(lines: readonly DraftLineForm[]): QuoteLinePayload[] {
  return draftLinesPayload(lines).map((line) => ({
    line_id: line.line_id,
    position: line.position,
    catalog_item_id: line.catalog_item_id,
    description: line.description,
    quantity: line.quantity,
    unit_price_gross: line.unit_price_gross,
    discount_gross: line.discount_gross,
  }))
}

export type QuoteRecipientInput =
  { kind: 'PATIENT'; patientId: string } | { kind: 'PROSPECT'; name: string; phone: string | null }

export function buildCreateQuotePayload(input: {
  quoteId: string
  recipient: QuoteRecipientInput
  validUntil: string | null
  lines: readonly DraftLineForm[]
}): CreateQuotePayload {
  const phone = input.recipient.kind === 'PROSPECT' ? (input.recipient.phone?.trim() ?? '') : ''
  return {
    quote_id: input.quoteId,
    patient_id: input.recipient.kind === 'PATIENT' ? input.recipient.patientId : null,
    prospect:
      input.recipient.kind === 'PROSPECT'
        ? { name: input.recipient.name.trim(), phone: phone === '' ? null : phone }
        : null,
    valid_until: input.validUntil,
    lines: quoteLinesPayload(input.lines),
  }
}

export function buildUpdateQuotePayload(input: {
  quoteId: string
  validUntil: string | null
  lines: readonly DraftLineForm[]
}): UpdateQuotePayload {
  return {
    quote_id: input.quoteId,
    valid_until: input.validUntil,
    lines: quoteLinesPayload(input.lines),
  }
}

export function buildIssueQuotePayload(quoteId: string, totalGross: number): IssueQuotePayload {
  return { quote_id: quoteId, total_gross: totalGross }
}

export function buildReviseQuotePayload(input: {
  quoteId: string
  reason: string
  validUntil: string
  lines: readonly DraftLineForm[]
  totalGross: number
}): ReviseQuotePayload {
  return {
    quote_id: input.quoteId,
    reason: input.reason.trim(),
    valid_until: input.validUntil,
    lines: nonEmpty(quoteLinesPayload(input.lines), 'ligne'),
    total_gross: input.totalGross,
  }
}

export function buildDecisionPayload(
  quoteId: string,
  decision: 'ACCEPTED' | 'DECLINED',
  reason: string,
): QuoteDecisionPayload {
  const trimmed = reason.trim()
  return { quote_id: quoteId, decision, reason: trimmed === '' ? null : trimmed }
}

export type PriceBasis = 'QUOTE' | 'CATALOG'

export function buildConvertPayload(input: {
  quoteId: string
  revisionNo: number
  invoiceId: string
  patientId: string | null
  priceBasis: PriceBasis
}): ConvertQuotePayload {
  return {
    quote_id: input.quoteId,
    revision_no: input.revisionNo,
    invoice_id: input.invoiceId,
    patient_id: input.patientId,
    price_basis: input.priceBasis,
    prices_confirmed: true,
  }
}

/** Brouillon ou facture né de la conversion et non abandonné : la conversion a déjà eu lieu. */
export function activeInvoiceOf(quote: Quote): Quote['invoices'][number] | null {
  return quote.invoices.find((invoice) => invoice.status !== 'ABANDONED') ?? null
}

export interface QuoteActions {
  editDraft: boolean
  issue: boolean
  revise: boolean
  accept: boolean
  decline: boolean
  convert: boolean
}

/**
 * Actions proposées selon le statut À L'INSTANT (le serveur revérifie) : un brouillon se modifie et s'émet ; un devis
 * émis, accepté ou refusé se révise (même expiré) ; seul un devis émis encore valable s'accepte ; un devis émis ou
 * accepté se refuse ; un devis émis ou accepté encore valable se convertit, ou un devis converti dont le brouillon a
 * été abandonné.
 */
export function quoteActions(quote: Quote, canWrite: boolean): QuoteActions {
  const none: QuoteActions = {
    editDraft: false,
    issue: false,
    revise: false,
    accept: false,
    decline: false,
    convert: false,
  }
  if (!canWrite) return none
  const status = quote.status
  const effective = quote.effective_status
  const reconvertible = status === 'CONVERTED' && activeInvoiceOf(quote) === null
  return {
    editDraft: status === 'DRAFT',
    issue: status === 'DRAFT',
    revise: status === 'ISSUED' || status === 'ACCEPTED' || status === 'DECLINED',
    accept: status === 'ISSUED' && effective !== 'EXPIRED',
    decline: status === 'ISSUED' || status === 'ACCEPTED',
    convert:
      ((status === 'ISSUED' || status === 'ACCEPTED') && effective !== 'EXPIRED') || reconvertible,
  }
}

export interface PriceComparisonRow {
  position: number
  description: string
  quantity: string
  quotePrice: number
  quoteDiscount: number
  /** Prix actuel du catalogue ; null : ligne hors catalogue, ou prix non lu. */
  catalogPrice: number | null
  /** Vrai si le prix actuel est connu et diffère de celui du devis. */
  differs: boolean
}

/**
 * Comparaison, ligne par ligne, des prix d'une révision avec le catalogue ACTUEL (prix lus par l'écran) : la
 * personne qui convertit voit l'écart et choisit explicitement la base de prix de la facture.
 */
export function priceComparison(
  revision: QuoteRevision,
  catalogPrices: ReadonlyMap<string, number>,
): PriceComparisonRow[] {
  return revision.snapshot.lines.map((line) => {
    const current =
      line.catalog_item_id === null ? null : (catalogPrices.get(line.catalog_item_id) ?? null)
    return {
      position: line.position,
      description: line.description,
      quantity: line.quantity,
      quotePrice: line.unit_price_gross,
      quoteDiscount: line.discount_gross,
      catalogPrice: current,
      differs: current !== null && current !== line.unit_price_gross,
    }
  })
}

/** Révision la plus récente (celle des lignes actuelles), ou null pour un brouillon. */
export function latestRevision(quote: Quote): QuoteRevision | null {
  let latest: QuoteRevision | null = null
  for (const revision of quote.revisions) {
    if (latest === null || revision.revision_no > latest.revision_no) latest = revision
  }
  return latest
}
