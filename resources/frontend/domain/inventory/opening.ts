import type { CatalogItem, OpeningStockRequest } from '../contracts/generated'
import { RuleViolation } from '../errors'
import { CivilDate } from '../values/civilDate'
import { Quantity } from '../values/decimal'
import { trimBlanks } from '../values/text'

/**
 * Stock initial validé par la gérante (I00), EN LIGNE : un comptage, une ligne par article et
 * emplacement. Le serveur crée positions, lots et mouvements `OPENING` dans une transaction, avec
 * l'approbation directe de la gérante (`approval_id` toujours null ici : les approbations à distance
 * attendent B06). Coût d'achat inconnu = null, jamais 0. Un article périssable exige un lot daté.
 */
export const REASON_MAX_LENGTH = 1000
export const BATCH_MAX_LENGTH = 64

export interface OpeningLineForm {
  movementId: string
  lotId: string
  itemId: string | null
  locationId: string | null
  quantity: string | null
  batchNumber: string
  /** Date civile `AAAA-MM-JJ` ou null. */
  expiresOn: string | null
  unitCost: number | null
}

export type OpeningLineField = 'itemId' | 'locationId' | 'quantity' | 'batchNumber' | 'expiresOn'

export interface OpeningLineError {
  index: number
  field: OpeningLineField
  message: string
}

export function validateOpeningStock(input: {
  reason: string
  lines: readonly OpeningLineForm[]
  items: ReadonlyMap<string, CatalogItem>
}): { errors: OpeningLineError[]; reason: string | null; general: string | null } {
  const errors: OpeningLineError[] = []
  const reason = trimBlanks(input.reason)
  let general: string | null = null
  if (reason === '') general = 'Le motif du comptage est obligatoire.'
  else if (reason.length > REASON_MAX_LENGTH) {
    general = `Le motif compte au plus ${REASON_MAX_LENGTH} caractères.`
  }
  if (input.lines.length === 0) general ??= 'Ajoutez au moins une ligne de stock initial.'
  input.lines.forEach((line, index) => {
    const item = line.itemId === null ? undefined : input.items.get(line.itemId)
    if (item === undefined) {
      errors.push({ index, field: 'itemId', message: 'Choisissez un article géré en stock.' })
    } else if (item.stock_mode !== 'STOCKED' || !item.active) {
      errors.push({
        index,
        field: 'itemId',
        message: 'Seul un article actif géré en stock reçoit un stock initial.',
      })
    }
    if (line.locationId === null) {
      errors.push({ index, field: 'locationId', message: 'Choisissez l’emplacement.' })
    }
    if (line.quantity === null) {
      errors.push({ index, field: 'quantity', message: 'La quantité est obligatoire.' })
    } else if (item !== undefined) {
      try {
        Quantity.forLine(Quantity.of(line.quantity), item.quantity_integer_only)
      } catch (error) {
        if (!(error instanceof RuleViolation)) throw error
        errors.push({ index, field: 'quantity', message: error.message })
      }
    }
    const batch = trimBlanks(line.batchNumber)
    if (item?.requires_expiry === true) {
      if (batch === '') {
        errors.push({ index, field: 'batchNumber', message: 'Cet article périssable exige un numéro de lot.' })
      }
      if (line.expiresOn === null) {
        errors.push({ index, field: 'expiresOn', message: 'Cet article périssable exige une date d’expiration.' })
      }
    }
    if (batch.length > BATCH_MAX_LENGTH) {
      errors.push({ index, field: 'batchNumber', message: `Au plus ${BATCH_MAX_LENGTH} caractères.` })
    }
    if (line.expiresOn !== null) {
      try {
        CivilDate.fromString(line.expiresOn)
      } catch (error) {
        if (!(error instanceof RuleViolation)) throw error
        errors.push({ index, field: 'expiresOn', message: 'Date d’expiration invalide.' })
      }
    }
  })
  return { errors, reason: reason === '' ? null : reason, general }
}

type OpeningLinePayload = OpeningStockRequest['lines'][number]

/** Charge de `POST /stock/opening` (aucune version attendue : le corps n'a pas ce champ). */
export function buildOpeningStockPayload(input: {
  countedAt: string
  reason: string
  lines: readonly OpeningLineForm[]
}): OpeningStockRequest {
  const lines = input.lines.map((line): OpeningLinePayload => {
    if (line.itemId === null || line.locationId === null || line.quantity === null) {
      throw new Error('Ligne de stock initial non validée.')
    }
    const batch = trimBlanks(line.batchNumber)
    return {
      movement_id: line.movementId,
      item_id: line.itemId,
      location_id: line.locationId,
      lot:
        batch === '' && line.expiresOn === null
          ? null
          : { lot_id: line.lotId, batch_number: batch, expires_on: line.expiresOn },
      quantity: line.quantity,
      unit_cost: line.unitCost,
    }
  })
  const [first, ...rest] = lines
  if (first === undefined) throw new Error('Stock initial sans ligne.')
  return { counted_at: input.countedAt, reason: input.reason, approval_id: null, lines: [first, ...rest] }
}

export function openingFieldFromServer(key: string): { index: number; field: OpeningLineField } | null {
  const match = /^lines\.([0-9]+)\.([a-z_.]+)$/.exec(key)
  if (match === null) return null
  const index = Number(match[1])
  const name = match[2] ?? ''
  if (name === 'item_id') return { index, field: 'itemId' }
  if (name === 'location_id') return { index, field: 'locationId' }
  if (name === 'quantity') return { index, field: 'quantity' }
  if (name.startsWith('lot.expires_on')) return { index, field: 'expiresOn' }
  if (name.startsWith('lot')) return { index, field: 'batchNumber' }
  return null
}
