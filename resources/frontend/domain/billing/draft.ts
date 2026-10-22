import type {
  CatalogItem,
  CatalogItemsStockMode,
  CreateInvoiceDraftPayload,
  Invoice,
  UpdateDraftPayload,
} from '../contracts/generated'
import { RuleViolation } from '../errors'
import { Quantity } from '../values/decimal'
import { computeLineAmounts, invoiceTotal, type LineAmounts } from './lineAmounts'

/**
 * Brouillon de facture côté écran (B02) : lignes saisies, calcul des montants et charges des commandes
 * `CreateInvoiceDraft` / `UpdateDraft`. Les montants viennent des MÊMES fonctions pures que le serveur
 * (`computeLineAmounts`, `invoiceTotal`, jumelles de `LineAmounts.php`, rejouées sur
 * `contracts/vectors/invoice.json`) : l'écran affiche, le serveur recalcule et décide (prix imposé,
 * plafond de remise, taxe). Aucun flottant : quantités en décimal exact, montants en FCFA entiers.
 *
 * La taxe comprise n'est pas calculée ici : la règle fiscale d'un article n'est lue que par la gérante
 * (`GET /tax-rules`). Le total TTC n'en dépend pas ; la ventilation affichée est celle des lignes que
 * le serveur a enregistrées.
 */

/** Unités entières du référentiel (`units.integer_only` = 1). */
const INTEGER_UNITS: ReadonlySet<string> = new Set(['UNIT', 'PAIR'])

/** Bornes du contrat `CreateInvoiceDraft` (200 lignes, désignation de 500 caractères). */
export const MAX_DRAFT_LINES = 200
export const DESCRIPTION_MAX_LENGTH = 500

/** Catégorie et unité d'une ligne libre (comme `DraftLines::FREE_LINE_*` du serveur). */
export const FREE_LINE_UNIT = 'UNIT'

/** Caractère de contrôle (U+0000 à U+001F, U+007F), retours à la ligne compris, comme le serveur. */
function hasControlCharacter(text: string): boolean {
  for (const character of text) {
    const code = character.codePointAt(0) ?? 0
    if (code <= 0x1f || code === 0x7f) return true
  }
  return false
}

export interface DraftLineForm {
  lineId: string
  catalogItemId: string | null
  reference: string | null
  description: string
  unitCode: string
  integerOnly: boolean
  /** Mode de stock de l'article (null : ligne libre ou ligne relue sans article connu). */
  stockMode: CatalogItemsStockMode | null
  /** Prix du catalogue au moment du choix ; null pour une ligne libre ou relue. */
  catalogPrice: number | null
  /** Quantité canonique (« 1.000 ») ou null (non saisie). */
  quantity: string | null
  unitPriceGross: number | null
  /** Remise TTC en FCFA ; null = aucune remise saisie (0). */
  discountGross: number | null
  prescriptionId: string | null
}

export type DraftLineField =
  'line' | 'catalogItemId' | 'description' | 'quantity' | 'unitPriceGross' | 'discountGross'

export interface DraftLineError {
  index: number
  field: DraftLineField
  message: string
}

/** Ligne choisie dans le catalogue : désignation, unité et prix de l'article, quantité 1. */
export function lineFromCatalogItem(item: CatalogItem, lineId: string): DraftLineForm {
  return {
    lineId,
    catalogItemId: item.id,
    reference: item.reference,
    description: item.name,
    unitCode: item.unit_code,
    integerOnly: item.quantity_integer_only,
    stockMode: item.stock_mode,
    catalogPrice: item.sale_price,
    quantity: '1.000',
    unitPriceGross: item.sale_price,
    discountGross: null,
    prescriptionId: null,
  }
}

/** Ligne hors catalogue (réservée à l'autorité de prix) : désignation et prix à saisir. */
export function freeLine(lineId: string): DraftLineForm {
  return {
    lineId,
    catalogItemId: null,
    reference: null,
    description: '',
    unitCode: FREE_LINE_UNIT,
    integerOnly: true,
    stockMode: null,
    catalogPrice: null,
    quantity: '1.000',
    unitPriceGross: null,
    discountGross: null,
    prescriptionId: null,
  }
}

/**
 * Lignes d'un brouillon relu sur le serveur. L'exigence « quantité entière » de l'article n'est pas
 * renvoyée par la ressource : elle est déduite de l'unité du référentiel (unité, paire) ; le serveur
 * la revérifie sur l'article.
 */
export function linesFromInvoice(invoice: Invoice): DraftLineForm[] {
  return [...invoice.lines]
    .sort((left, right) => left.position - right.position)
    .map((line) => ({
      lineId: line.id,
      catalogItemId: line.catalog_item_id,
      reference: line.reference_snapshot,
      description: line.description_snapshot,
      unitCode: line.unit_snapshot,
      integerOnly: INTEGER_UNITS.has(line.unit_snapshot),
      stockMode: null,
      catalogPrice: null,
      quantity: Quantity.of(line.quantity).canonical(),
      unitPriceGross: line.unit_price_gross,
      discountGross: line.discount_gross === 0 ? null : line.discount_gross,
      prescriptionId: line.prescription_id,
    }))
}

export type LineComputation =
  { ok: true; amounts: LineAmounts } | { ok: false; field: DraftLineField; message: string }

function fieldOfRule(field: string): DraftLineField {
  switch (field) {
    case 'quantity':
      return 'quantity'
    case 'unit_price_gross':
      return 'unitPriceGross'
    case 'discount_gross':
      return 'discountGross'
    default:
      return 'line'
  }
}

/** Montants d'une ligne par les règles partagées (brut arrondi au FCFA, remise, net). */
export function computeDraftLine(line: DraftLineForm): LineComputation {
  if (line.quantity === null) {
    return { ok: false, field: 'quantity', message: 'La quantité est obligatoire.' }
  }
  if (line.unitPriceGross === null) {
    return { ok: false, field: 'unitPriceGross', message: 'Le prix unitaire est obligatoire.' }
  }
  try {
    const amounts = computeLineAmounts(
      Quantity.of(line.quantity),
      line.unitPriceGross,
      line.discountGross ?? 0,
      null,
      line.integerOnly,
    )
    return { ok: true, amounts }
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return { ok: false, field: fieldOfRule(error.field), message: error.message }
  }
}

export interface DraftTotals {
  lines: LineComputation[]
  /** Total TTC (somme des nets), ou null tant qu'une ligne n'est pas calculable. */
  total: number | null
  /** Refus du total (dépassement des bornes), sinon null. */
  totalError: string | null
}

export function draftTotals(lines: readonly DraftLineForm[]): DraftTotals {
  const computed = lines.map(computeDraftLine)
  const amounts: LineAmounts[] = []
  for (const line of computed) {
    if (!line.ok) return { lines: computed, total: null, totalError: null }
    amounts.push(line.amounts)
  }
  try {
    return { lines: computed, total: invoiceTotal(amounts), totalError: null }
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return { lines: computed, total: null, totalError: error.message }
  }
}

/**
 * Contrôles de saisie avant l'envoi (le serveur refait tout) : désignation, quantité, prix, remise et
 * nombre de lignes. `forIssue` : une facture sans ligne ne s'émet pas.
 */
export function validateDraftLines(
  lines: readonly DraftLineForm[],
  options: { forIssue?: boolean } = {},
): { errors: DraftLineError[]; general: string | null } {
  const errors: DraftLineError[] = []
  let general: string | null = null
  if (lines.length > MAX_DRAFT_LINES) {
    general = `Une facture compte au plus ${MAX_DRAFT_LINES} lignes.`
  } else if (options.forIssue === true && lines.length === 0) {
    general = 'Une facture sans ligne ne s’émet pas : ajoutez au moins une ligne.'
  }
  lines.forEach((line, index) => {
    const description = line.description.trim()
    if (description === '') {
      errors.push({ index, field: 'description', message: 'La désignation est obligatoire.' })
    } else if (description.length > DESCRIPTION_MAX_LENGTH) {
      errors.push({
        index,
        field: 'description',
        message: `La désignation compte au plus ${DESCRIPTION_MAX_LENGTH} caractères.`,
      })
    } else if (hasControlCharacter(description)) {
      errors.push({
        index,
        field: 'description',
        message: 'La désignation tient sur une ligne, sans caractère de contrôle.',
      })
    }
    const computed = computeDraftLine(line)
    if (!computed.ok) errors.push({ index, field: computed.field, message: computed.message })
  })
  if (general === null && errors.length === 0) {
    const totals = draftTotals(lines)
    if (totals.totalError !== null) general = totals.totalError
  }
  return { errors, general }
}

type PayloadLine = CreateInvoiceDraftPayload['lines'][number]

/** Lignes de la charge : positions 1..n dans l'ordre affiché, remise vide = 0. */
export function draftLinesPayload(lines: readonly DraftLineForm[]): PayloadLine[] {
  return lines.map((line, index) => {
    if (line.quantity === null || line.unitPriceGross === null) {
      throw new Error('Ligne non validée : quantité et prix sont requis avant l’envoi.')
    }
    return {
      line_id: line.lineId,
      position: index + 1,
      catalog_item_id: line.catalogItemId,
      description: line.description.trim(),
      quantity: line.quantity,
      unit_price_gross: line.unitPriceGross,
      discount_gross: line.discountGross ?? 0,
      prescription_id: line.prescriptionId,
    }
  })
}

export function buildCreateDraftPayload(input: {
  invoiceId: string
  patientId: string
  visitId: string | null
  dueDate: string | null
  lines: readonly DraftLineForm[]
}): CreateInvoiceDraftPayload {
  return {
    invoice_id: input.invoiceId,
    patient_id: input.patientId,
    visit_id: input.visitId,
    due_date: input.dueDate,
    lines: draftLinesPayload(input.lines),
  }
}

export function buildUpdateDraftPayload(input: {
  invoiceId: string
  visitId: string | null
  dueDate: string | null
  lines: readonly DraftLineForm[]
}): UpdateDraftPayload {
  return {
    invoice_id: input.invoiceId,
    visit_id: input.visitId,
    due_date: input.dueDate,
    lines: draftLinesPayload(input.lines),
  }
}

/** Même contenu de lignes (ordre, article, désignation, quantité, prix, remise) : rien à enregistrer. */
export function sameDraftLines(
  left: readonly DraftLineForm[],
  right: readonly DraftLineForm[],
): boolean {
  if (left.length !== right.length) return false
  return left.every((line, index) => {
    const other = right[index]
    return (
      other !== undefined &&
      line.lineId === other.lineId &&
      line.catalogItemId === other.catalogItemId &&
      line.description.trim() === other.description.trim() &&
      line.quantity === other.quantity &&
      line.unitPriceGross === other.unitPriceGross &&
      (line.discountGross ?? 0) === (other.discountGross ?? 0)
    )
  })
}

/** Erreur du serveur rattachée à une ligne (`lines.2.discount_gross`) ; null pour un autre champ. */
export function serverLineError(
  key: string,
  message: string,
): DraftLineError | null {
  const match = /^lines\.([0-9]+)(?:\.([a-z_]+))?$/.exec(key)
  if (match === null) return null
  const index = Number(match[1])
  const field: DraftLineField = (() => {
    switch (match[2]) {
      case 'catalog_item_id':
        return 'catalogItemId'
      case 'description':
        return 'description'
      case 'quantity':
        return 'quantity'
      case 'unit_price_gross':
        return 'unitPriceGross'
      case 'discount_gross':
        return 'discountGross'
      default:
        return 'line'
    }
  })()
  return { index, field, message }
}

/**
 * Prix unitaire modifiable : seulement par l'autorité de prix (`catalog.manage`). Sans elle, le prix
 * du catalogue s'impose (le serveur refuse tout autre prix).
 */
export function priceEditable(priceAuthority: boolean): boolean {
  return priceAuthority
}

/**
 * Règle de remise affichée à côté du champ : le serveur tranche. `capPercent` est le plafond EFFECTIF de ce
 * compte lu sur le serveur (`GET /sales/settings`, taux exact « 10.0000 ») ; inconnu : texte générique.
 */
export function discountRule(priceAuthority: boolean, capPercent: string | null = null): string {
  if (priceAuthority) return 'Remise en FCFA, au plus le montant brut de la ligne (autorité de prix).'
  if (capPercent === null) {
    return 'Remise réservée à la gérante : sans autorité de prix, le plafond fixé sur le serveur s’applique (0 % par défaut) et le serveur refuse ce qui le dépasse.'
  }
  const shown = capPercent.replace(/\.?0+$/, '').replace('.', ',')
  return shown === '0' || shown === ''
    ? 'Remise réservée à la gérante : aucune remise n’est admise pour ce compte (le serveur refuse toute remise).'
    : `Remise en FCFA, au plus ${shown} % du montant brut de la ligne pour ce compte (le serveur refuse ce qui le dépasse).`
}
