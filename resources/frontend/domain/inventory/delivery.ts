import type {
  ConfirmDeliveryPayload,
  InvoiceDeliveries,
  PhysicalReturnsConditionCode,
  RegisterPhysicalReturnPayload,
  RegisterStockMovementPayload,
} from '../contracts/generated'
import { RuleViolation } from '../errors'
import { type FixedDecimal, Quantity } from '../values/decimal'
import { trimBlanks } from '../values/text'

/**
 * Remise au patient (I01), retour physique, casse et ajustement (I02), EN LIGNE. Jumeaux d'écran de
 * `app/Domain/Inventory/Rules/DeliveryRules.php` : contrôles avant l'envoi et charges des commandes. Le serveur
 * rejuge tout sous verrou (reste à remettre, réserve ouverte, lot non expiré au jour local, approbation exacte,
 * disponible central) : ces règles servent à DIRE la limite, jamais à la décider.
 *
 *   reste à remettre   = quantité facturée − déjà remise
 *   reste retournable  = quantité remise − déjà retournée
 *
 * Quantités en chaînes décimales exactes (aucun calcul flottant). Vocabulaire : une remise ne dit jamais « payé » ni
 * « soldé » ; une facture non soldée se remet avec l'approbation de la gérante.
 */

export const REASON_MIN_LENGTH = 3
export const REASON_MAX_LENGTH = 1000

export type DeliveryLine = InvoiceDeliveries['lines'][number]
export type DeliveredLine = InvoiceDeliveries['deliveries'][number]['lines'][number]

export const CONDITION_LABELS: Readonly<Record<PhysicalReturnsConditionCode, string>> = {
  SELLABLE: 'Intact, vendable',
  DAMAGED: 'Abîmé',
  QUARANTINE: 'À contrôler (quarantaine)',
  OTHER: 'Autre',
}

export const CONDITIONS: readonly PhysicalReturnsConditionCode[] = [
  'SELLABLE',
  'DAMAGED',
  'QUARANTINE',
  'OTHER',
]

export type MovementKind = RegisterStockMovementPayload['kind']

export const MOVEMENT_LABELS: Readonly<Record<MovementKind, string>> = {
  BREAKAGE: 'Casse ou perte (sortie du stock)',
  ADJUSTMENT: 'Ajustement après comptage',
}

/** « 1,5 » pour 1.500, « 2 » pour 2.000 : quantité exacte lisible. */
export function quantityLabel(canonical: string): string {
  if (!canonical.includes('.')) return canonical
  return canonical.replace(/0+$/, '').replace(/\.$/, '').replace('.', ',')
}

function zero(): FixedDecimal {
  return Quantity.of('0')
}

function parseQuantity(text: string, field: string): FixedDecimal | null {
  return Quantity.parse(text.trim() === '' ? null : text, field)
}

/** Ligne remettable maintenant (article du catalogue, reste à remettre, réserve ouverte s'il est en stock). */
export function isDeliverable(line: DeliveryLine): boolean {
  return line.deliverable
}

/** Lignes encore à remettre, dans l'ordre de la facture. */
export function pendingLines(view: InvoiceDeliveries): DeliveryLine[] {
  return view.lines.filter(
    (line) => line.item_id !== null && Quantity.of(line.remaining).units > 0n,
  )
}

/** Toutes les lignes avec article sont remises (remise totale). */
export function fullyDelivered(view: InvoiceDeliveries): boolean {
  return view.lines.every((line) => line.item_id === null || Quantity.of(line.remaining).isZero())
}

/** Plafond de la saisie : le reste à remettre, et la réserve ouverte pour un article en stock. */
export function deliveryCeiling(line: DeliveryLine): FixedDecimal {
  const remaining = Quantity.of(line.remaining)
  if (line.stock_mode !== 'STOCKED') return remaining
  const open = Quantity.of(line.reserved_open)
  return open.compare(remaining) < 0 ? open : remaining
}

export interface DeliveryForm {
  /** Quantité saisie par ligne de facture ; vide = ligne non remise cette fois. */
  quantities: Readonly<Record<string, string>>
}

export type DeliveryErrors = Readonly<Record<string, string>>

export type DeliveryValidation =
  | { ok: true; lines: { invoiceLineId: string; itemId: string; quantity: string }[] }
  | { ok: false; errors: DeliveryErrors; general: string | null }

/**
 * Contrôle d'une remise : au moins une ligne, chaque quantité > 0, entière pour un article vendu à l'unité, au plus le
 * reste à remettre (et la réserve ouverte d'un article en stock).
 */
export function validateDelivery(view: InvoiceDeliveries, form: DeliveryForm): DeliveryValidation {
  const errors: Record<string, string> = {}
  const lines: { invoiceLineId: string; itemId: string; quantity: string }[] = []
  for (const line of view.lines) {
    const text = form.quantities[line.invoice_line_id] ?? ''
    if (text.trim() === '') continue
    if (line.item_id === null) {
      errors[line.invoice_line_id] = 'Une ligne libre ne porte aucun article à remettre.'
      continue
    }
    let quantity: FixedDecimal | null
    try {
      quantity = parseQuantity(text, 'quantity')
      if (quantity === null) continue
      Quantity.forLine(quantity, line.quantity_integer_only)
    } catch (error) {
      if (!(error instanceof RuleViolation)) throw error
      errors[line.invoice_line_id] = error.message
      continue
    }
    const ceiling = deliveryCeiling(line)
    if (quantity.compare(ceiling) > 0) {
      errors[line.invoice_line_id] = ceiling.isZero()
        ? line.stock_mode === 'STOCKED' && !Quantity.of(line.remaining).isZero()
          ? 'Aucune réserve ouverte pour cet article : il ne peut pas être remis sur cette vente.'
          : 'Cette ligne est déjà entièrement remise.'
        : `Au plus ${quantityLabel(ceiling.canonical())} à remettre sur cette ligne.`
      continue
    }
    lines.push({
      invoiceLineId: line.invoice_line_id,
      itemId: line.item_id,
      quantity: quantity.canonical(),
    })
  }
  if (Object.keys(errors).length > 0) return { ok: false, errors, general: null }
  if (lines.length === 0) {
    return { ok: false, errors: {}, general: 'Indiquez la quantité remise d’au moins une ligne.' }
  }
  return { ok: true, lines }
}

/** La remise de cette facture exige-t-elle l'approbation de la gérante (reste dû à l'instant de la lecture) ? */
export function creditDeliveryRequired(view: Pick<InvoiceDeliveries, 'amount_due'>): boolean {
  return view.amount_due !== null && view.amount_due > 0
}

/** Charge `ConfirmDelivery` (alias d'objet : transmissible au répartiteur de mutations). */
export type ConfirmDeliveryRequestPayload = {
  [K in keyof ConfirmDeliveryPayload]: ConfirmDeliveryPayload[K]
}

/**
 * Route directe : positions, lots, réserves et mouvements à null (le serveur choisit sur les réserves de la vente,
 * FEFO). Identifiants de remise et de lignes connus avant l'envoi (une reprise ne remet jamais deux fois).
 */
export function buildDeliveryPayload(input: {
  invoiceId: string
  deliveryId: string
  deliveredAt: string
  creditApprovalId: string | null
  lines: readonly {
    invoiceLineId: string
    itemId: string
    quantity: string
    deliveryLineId: string
  }[]
}): ConfirmDeliveryRequestPayload {
  if (input.lines.length === 0) {
    throw RuleViolation.invalid(
      'lines',
      'delivery.lines_required',
      'Une remise porte au moins une ligne.',
    )
  }
  return {
    delivery_id: input.deliveryId,
    invoice_id: input.invoiceId,
    delivered_at: input.deliveredAt,
    credit_delivery_approval_id: input.creditApprovalId,
    lines: input.lines.map((line) => ({
      delivery_line_id: line.deliveryLineId,
      invoice_line_id: line.invoiceLineId,
      item_id: line.itemId,
      quantity: line.quantity,
      stock_position_id: null,
      stock_lot_id: null,
      reservation_id: null,
      stock_movement_id: null,
    })) as ConfirmDeliveryRequestPayload['lines'],
  }
}

// --- Retour physique (I02) ---------------------------------------------------------------------------

export interface ReturnForm {
  quantity: string
  condition: PhysicalReturnsConditionCode | null
  restock: boolean
  reason: string
  creditNoteId: string | null
}

export type ReturnField = 'quantity' | 'condition' | 'restock' | 'reason'
export type ReturnErrors = Partial<Record<ReturnField, string>>

export type ReturnValidation =
  | {
      ok: true
      quantity: string
      condition: PhysicalReturnsConditionCode
      restock: boolean
      reason: string
    }
  | { ok: false; errors: ReturnErrors }

export function returnable(line: Pick<DeliveredLine, 'returnable'>): FixedDecimal {
  return Quantity.of(line.returnable)
}

function checkReason(text: string): { ok: true; reason: string } | { ok: false; error: string } {
  const reason = trimBlanks(text)
  if (reason.length < REASON_MIN_LENGTH) {
    return { ok: false, error: `Indiquez le motif (au moins ${REASON_MIN_LENGTH} caractères).` }
  }
  if (reason.length > REASON_MAX_LENGTH) {
    return { ok: false, error: `Le motif compte au plus ${REASON_MAX_LENGTH} caractères.` }
  }
  return { ok: true, reason }
}

/**
 * Retour ≤ quantité remise non retournée ; état constaté obligatoire ; remise en stock seulement d'un article
 * vendable d'un article géré en stock ; motif obligatoire.
 */
export function validateReturn(
  line: Pick<DeliveredLine, 'returnable' | 'stock_position_id'>,
  form: ReturnForm,
  integerOnly: boolean,
): ReturnValidation {
  const errors: ReturnErrors = {}
  let quantity: FixedDecimal | null
  try {
    quantity = parseQuantity(form.quantity, 'quantity')
    if (quantity === null) errors.quantity = 'La quantité retournée est obligatoire.'
    else Quantity.forLine(quantity, integerOnly)
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    errors.quantity = error.message
    quantity = null
  }
  const ceiling = returnable(line)
  if (quantity !== null && errors.quantity === undefined && quantity.compare(ceiling) > 0) {
    errors.quantity = ceiling.isZero()
      ? 'Toute la quantité remise sur cette ligne est déjà retournée.'
      : `Au plus ${quantityLabel(ceiling.canonical())} (quantité remise non retournée).`
  }
  if (form.condition === null) errors.condition = 'Indiquez l’état constaté de l’article.'
  if (form.restock && form.condition !== null && form.condition !== 'SELLABLE') {
    errors.restock = 'Seul un article contrôlé vendable est remis en stock.'
  }
  if (form.restock && line.stock_position_id === null) {
    errors.restock = 'Cet article n’est pas géré en stock : il ne se remet pas en stock.'
  }
  const reason = checkReason(form.reason)
  if (!reason.ok) errors.reason = reason.error
  if (
    Object.keys(errors).length > 0 ||
    quantity === null ||
    form.condition === null ||
    !reason.ok
  ) {
    return { ok: false, errors }
  }
  return {
    ok: true,
    quantity: quantity.canonical(),
    condition: form.condition,
    restock: form.restock,
    reason: reason.reason,
  }
}

export type PhysicalReturnRequestPayload = {
  [K in keyof RegisterPhysicalReturnPayload]: RegisterPhysicalReturnPayload[K]
}

export function buildReturnPayload(input: {
  returnId: string
  deliveryLineId: string
  itemId: string
  quantity: string
  condition: PhysicalReturnsConditionCode
  restock: boolean
  approvedBy: string
  occurredAt: string
  reason: string
  creditNoteId: string | null
  movementId: string
}): PhysicalReturnRequestPayload {
  return {
    physical_return_id: input.returnId,
    delivery_line_id: input.deliveryLineId,
    item_id: input.itemId,
    quantity: input.quantity,
    condition_code: input.condition,
    accepted_for_restock: input.restock,
    approved_by: input.approvedBy,
    occurred_at: input.occurredAt,
    reason: input.reason,
    credit_note_id: input.creditNoteId,
    // Aucun mouvement pour un retour non remis en stock (le serveur le refuserait).
    stock_movement_id: input.restock ? input.movementId : null,
  }
}

// --- Casse et ajustement (I02) -----------------------------------------------------------------------

export interface MovementForm {
  kind: MovementKind
  /** Quantité saisie SANS signe ; le sens vient de `direction` (une casse sort toujours). */
  quantity: string
  direction: 'out' | 'in'
  reason: string
}

export type MovementField = 'quantity' | 'reason' | 'lot'
export type MovementErrors = Partial<Record<MovementField, string>>

export type MovementValidation =
  { ok: true; signedQuantity: FixedDecimal; reason: string } | { ok: false; errors: MovementErrors }

/**
 * Casse : sortie ; ajustement : sortie ou entrée. Quantité > 0, entière pour un article vendu à l'unité ; une sortie ne
 * dépasse pas le disponible (physique − réserves − quotas) de la position ou du lot choisi ; lot obligatoire dès que
 * la position a des lots ; motif obligatoire.
 */
export function validateMovement(
  form: MovementForm,
  context: { available: string; integerOnly: boolean; lotRequired: boolean; lotChosen: boolean },
): MovementValidation {
  const errors: MovementErrors = {}
  let quantity: FixedDecimal | null
  try {
    quantity = parseQuantity(form.quantity, 'quantity')
    if (quantity === null) errors.quantity = 'La quantité est obligatoire.'
    else Quantity.forLine(quantity, context.integerOnly)
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    errors.quantity = error.message
    quantity = null
  }
  const out = form.kind === 'BREAKAGE' || form.direction === 'out'
  if (quantity !== null && errors.quantity === undefined && out) {
    const available = Quantity.of(context.available.startsWith('-') ? '0' : context.available)
    if (quantity.compare(available) > 0) {
      errors.quantity = available.isZero()
        ? 'Aucune quantité disponible à retirer : la marchandise réservée pour une vente ou confiée à un poste ne se retire pas.'
        : `Au plus ${quantityLabel(available.canonical())} disponible à retirer.`
    }
  }
  if (context.lotRequired && !context.lotChosen) {
    errors.lot = 'Cet article est suivi par lots : choisissez le lot concerné.'
  }
  const reason = checkReason(form.reason)
  if (!reason.ok) errors.reason = reason.error
  if (Object.keys(errors).length > 0 || quantity === null || !reason.ok)
    return { ok: false, errors }
  const signed = out ? zero().minus(quantity) : quantity
  return { ok: true, signedQuantity: signed, reason: reason.reason }
}

export type StockMovementRequestPayload = {
  [K in keyof RegisterStockMovementPayload]: RegisterStockMovementPayload[K]
}

export function buildMovementPayload(input: {
  movementId: string
  itemId: string
  positionId: string
  lotId: string | null
  kind: MovementKind
  signedQuantity: FixedDecimal
  approvalId: string
  reason: string
  occurredAt: string
}): StockMovementRequestPayload {
  if (input.signedQuantity.isZero()) {
    throw RuleViolation.invalid(
      'signed_quantity',
      'movement.zero',
      'Un mouvement de quantité nulle n’existe pas.',
    )
  }
  if (input.kind === 'BREAKAGE' && !input.signedQuantity.isNegative()) {
    throw RuleViolation.invalid(
      'signed_quantity',
      'movement.breakage_positive',
      'Une casse retire du stock.',
    )
  }
  return {
    movement_id: input.movementId,
    item_id: input.itemId,
    position_id: input.positionId,
    lot_id: input.lotId,
    kind: input.kind,
    signed_quantity: input.signedQuantity.canonical(),
    // Coût unitaire inconnu à l'écran : null explicite, jamais zéro inventé.
    unit_cost: null,
    approval_id: input.approvalId,
    reason: input.reason,
    occurred_at: input.occurredAt,
  }
}

/** Champ de l'écran qui porte une erreur du serveur (clé de `error.fields`), ou null. */
export function deliveryLineFieldFromServer(
  key: string,
  submitted: readonly { invoiceLineId: string }[],
): string | null {
  const match = /^lines\.(\d+)\./.exec(key)
  if (match === null) return null
  return submitted[Number(match[1])]?.invoiceLineId ?? null
}

export { zero as zeroQuantity }
