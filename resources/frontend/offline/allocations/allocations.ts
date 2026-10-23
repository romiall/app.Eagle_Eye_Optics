import type { Preparation } from '../../domain/contracts/generated'
import type { AllocationRow } from '../vault/db'
import type { Vault } from '../vault/vault'

/**
 * Ressources préparées pour ce poste (O03 ; fichier 19 « Numéros », « Un seul rédacteur financier », « Droits de
 * stock ») telles que le poste les conserve : plages de numéros avec leur curseur LOCAL, quotas de stock avec leurs
 * consommations locales, propriétés de facture. Le serveur a pris les plages sous verrou, disjointes de toute autre ;
 * le poste ne fait jamais `MAX + 1` et ne renumérote rien : il consomme le prochain numéro de SA plage, dans la même
 * transaction que la pièce (O04).
 *
 * Règles de fusion d'une nouvelle préparation (reconnexion) :
 *  - un curseur local ne RECULE jamais (le poste a peut-être consommé des numéros pas encore synchronisés) ; il avance
 *    au-delà du dernier numéro consommé connu du serveur s'il est plus loin ;
 *  - une ressource que le serveur ne rend plus (clôturée après reprise complète) passe CLOSED localement : elle n'est
 *    plus consommée, jamais supprimée ;
 *  - les consommations et réserves LOCALES d'un quota sont conservées (le serveur ne les connaît qu'après O05).
 *
 * Quantités DECIMAL(12,3) en millièmes entiers (aucun nombre flottant).
 */

/** « 3.000 », « 2.5 », « 7 » → millièmes entiers ; null si la forme n'est pas une quantité décimale admise. */
export function quantityUnits(text: string): number | null {
  const match = /^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/.exec(text)
  if (match === null) return null
  const decimals = (match[2] ?? '').padEnd(3, '0')
  return Number(match[1]) * 1000 + Number(decimals)
}

/** Millièmes entiers → forme canonique DECIMAL(12,3) (« 3 », « 2.5 », « 0.125 »). */
export function unitsToQuantity(units: number): string {
  if (!Number.isSafeInteger(units) || units < 0) throw new RangeError('Quantité locale invalide.')
  const whole = Math.trunc(units / 1000)
  const rest = units - whole * 1000
  if (rest === 0) return String(whole)
  return `${whole}.${String(rest).padStart(3, '0').replace(/0+$/, '')}`
}

/** Numéro rendu exactement comme le serveur : `<préfixe>-AAAA-<valeur complétée à gauche>`. */
export function renderNumber(prefix: string, year: number, value: number, width: number): string {
  return `${prefix}-${String(year).padStart(4, '0')}-${String(value).padStart(width, '0')}`
}

export interface NextNumber {
  allocation: Extract<AllocationRow, { kind: 'NUMBER_RANGE' }>
  value: number
  rendered: string
}

/** Prochain numéro libre d'une série et d'une année (plage ACTIVE la plus ancienne non épuisée), ou null. */
export function nextNumber(
  rows: readonly AllocationRow[],
  documentType: string,
  documentYear: number,
): NextNumber | null {
  const ranges = rows
    .filter(
      (row): row is Extract<AllocationRow, { kind: 'NUMBER_RANGE' }> => row.kind === 'NUMBER_RANGE',
    )
    .filter(
      (row) =>
        row.status === 'ACTIVE' &&
        row.documentType === documentType &&
        row.documentYear === documentYear,
    )
    .filter((row) => row.nextValue <= row.rangeEnd)
    .sort((left, right) => left.rangeStart - right.rangeStart)
  const range = ranges[0]
  if (range === undefined) return null
  return {
    allocation: range,
    value: range.nextValue,
    rendered: renderNumber(range.prefix, range.documentYear, range.nextValue, range.width),
  }
}

/** Numéros encore disponibles d'une série et d'une année (annonce de l'épuisement avant blocage, fichier 19). */
export function remainingNumbers(
  rows: readonly AllocationRow[],
  documentType: string,
  documentYear: number,
): number {
  let remaining = 0
  for (const row of rows) {
    if (row.kind !== 'NUMBER_RANGE' || row.status !== 'ACTIVE') continue
    if (row.documentType !== documentType || row.documentYear !== documentYear) continue
    remaining += Math.max(0, row.rangeEnd - row.nextValue + 1)
  }
  return remaining
}

/** Disponible local d'un quota : alloué − consommé (serveur) − rendu − réservé localement − sorti localement. */
export function localAvailableUnits(row: Extract<AllocationRow, { kind: 'STOCK_QUOTA' }>): number {
  if (row.status !== 'ACTIVE') return 0
  return (
    row.allocatedUnits -
    row.consumedUnits -
    row.returnedUnits -
    row.reservedLocalUnits -
    row.consumedLocalUnits
  )
}

/** Vrai si ce poste est le propriétaire d'écriture ACTIF de la facture (une seule caisse écrit son solde). */
export function ownsInvoice(rows: readonly AllocationRow[], invoiceId: string): boolean {
  return rows.some(
    (row) => row.kind === 'WRITE_OWNER' && row.status === 'ACTIVE' && row.invoiceId === invoiceId,
  )
}

function requireUnits(text: string, what: string): number {
  const units = quantityUnits(text)
  if (units === null) throw new RangeError(`Quantité illisible dans la préparation (${what}).`)
  return units
}

/**
 * Fusion d'une préparation reçue du serveur dans les ressources locales (une transaction), puis conservation du
 * catalogue figé (scellé, compartiment administratif) et de l'heure du serveur. Rend le nombre de ressources actives.
 */
export async function storePreparation(
  vault: Vault,
  preparation: Preparation,
  now: string,
): Promise<{ ranges: number; quotas: number; owners: number }> {
  const counts = await vault.writeAllocations(async (db) => {
    const existing = new Map((await db.allocations.toArray()).map((row) => [row.id, row]))
    const seen = new Set<string>()
    const rows: AllocationRow[] = []

    for (const range of preparation.number_allocations) {
      seen.add(range.allocation_id)
      const start = Number(range.range_start)
      const end = Number(range.range_end)
      const serverNext =
        range.last_consumed_value === null ? start : Number(range.last_consumed_value) + 1
      const previous = existing.get(range.allocation_id)
      const localNext =
        previous !== undefined && previous.kind === 'NUMBER_RANGE' ? previous.nextValue : start
      rows.push({
        id: range.allocation_id,
        kind: 'NUMBER_RANGE',
        status: range.status,
        grantId: range.grant_id,
        documentType: range.document_type,
        documentYear: range.document_year,
        rangeStart: start,
        rangeEnd: end,
        nextValue: Math.max(localNext, serverNext, start),
        prefix: range.prefix,
        width: range.width,
        version: previous === undefined ? 1 : previous.version + 1,
        updatedAt: now,
      })
    }

    for (const quota of preparation.stock_allocations) {
      seen.add(quota.allocation_id)
      const previous = existing.get(quota.allocation_id)
      const local = previous !== undefined && previous.kind === 'STOCK_QUOTA' ? previous : null
      rows.push({
        id: quota.allocation_id,
        kind: 'STOCK_QUOTA',
        status: quota.status,
        itemId: quota.item_id,
        stockPositionId: quota.stock_position_id,
        stockLotId: quota.stock_lot_id,
        allocatedUnits: requireUnits(quota.allocated_quantity, 'quota alloué'),
        consumedUnits: requireUnits(quota.consumed_quantity, 'quota consommé'),
        returnedUnits: requireUnits(quota.returned_quantity, 'quota rendu'),
        reservedLocalUnits: local?.reservedLocalUnits ?? 0,
        consumedLocalUnits: local?.consumedLocalUnits ?? 0,
        version: local === null ? 1 : local.version + 1,
        updatedAt: now,
      })
    }

    for (const owner of preparation.write_owners) {
      seen.add(owner.owner_id)
      const previous = existing.get(owner.owner_id)
      rows.push({
        id: owner.owner_id,
        kind: 'WRITE_OWNER',
        status: 'ACTIVE',
        invoiceId: owner.invoice_id,
        epoch: owner.epoch,
        version: previous === undefined ? 1 : previous.version + 1,
        updatedAt: now,
      })
    }

    // Ce que le serveur ne rend plus est clos localement (jamais supprimé, jamais consommé ensuite).
    for (const [id, row] of existing) {
      if (!seen.has(id) && row.status !== 'CLOSED')
        rows.push({ ...row, status: 'CLOSED', version: row.version + 1, updatedAt: now })
    }
    await db.allocations.bulkPut(rows)
    await db.vault_events.add({
      type: 'resources_prepared',
      at: now,
      profileId: vault.session()?.profileId ?? null,
      detail: {
        ranges: preparation.number_allocations.length,
        quotas: preparation.stock_allocations.length,
        owners: preparation.write_owners.length,
      },
    })
    return {
      ranges: preparation.number_allocations.length,
      quotas: preparation.stock_allocations.length,
      owners: preparation.write_owners.length,
    }
  })

  const current = await vault.getEntity<{ catalog_version: string }>('catalog_snapshot', 'current')
  if (current === null || current.value.catalog_version !== preparation.catalog.catalog_version) {
    await vault.putEntity('catalog_snapshot', 'current', (current?.version ?? 0) + 1, {
      catalog_version: preparation.catalog.catalog_version,
      snapshot_sha256: preparation.catalog.snapshot_sha256,
      snapshot: preparation.catalog.snapshot,
      rules_version: preparation.rules_version,
    })
  }
  await vault.noteServerTime(preparation.server_time)
  return counts
}
