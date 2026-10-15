import { RuleViolation } from '../errors'
import type { CivilDate } from '../values/civilDate'

/**
 * Lots (collyres), jumeaux de `app/Domain/Inventory/Rules/LotRules.php` : quarantaine ou expiration
 * antérieure à la date LOCALE de remise → refus ; FEFO, lots sans date ensuite, puis identifiant.
 */
export function assertLotDeliverable(
  status: string,
  expiresOn: CivilDate | null,
  localDate: CivilDate,
): void {
  if (status !== 'SELLABLE') {
    throw new RuleViolation(
      'STOCK_UNAVAILABLE',
      'lot.quarantined',
      'lot_id',
      'Ce lot est en quarantaine : il ne peut pas être délivré.',
    )
  }
  if (expiresOn !== null && expiresOn.compare(localDate) < 0) {
    throw new RuleViolation(
      'LOT_EXPIRED',
      'lot.expired',
      'lot_id',
      'Ce lot est expiré à la date de remise.',
      {
        expires_on: expiresOn.toString(),
      },
    )
  }
}

export function fefoOrder(lots: readonly { id: string; expiresOn: CivilDate | null }[]): string[] {
  return [...lots]
    .sort((left, right) => {
      const byDate =
        left.expiresOn === null && right.expiresOn === null
          ? 0
          : left.expiresOn === null
            ? 1
            : right.expiresOn === null
              ? -1
              : left.expiresOn.compare(right.expiresOn)
      if (byDate !== 0) return byDate
      return left.id < right.id ? -1 : left.id > right.id ? 1 : 0
    })
    .map((lot) => lot.id)
}
