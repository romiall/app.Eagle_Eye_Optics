import { RuleViolation } from '../errors'
import { type FixedDecimal, Quantity } from '../values/decimal'

/**
 * Disponibilités de stock, jumelles de `app/Domain/Inventory/Rules/StockAvailability.php` :
 *   central = physique − réservations centrales − quotas locaux non consommés
 *   local   = quota alloué − consommé − restitué − réservations locales actives
 * Une réservation sous quota n'est jamais soustraite deux fois (IT15).
 */
export function centralAvailable(
  physical: FixedDecimal,
  centralReserved: FixedDecimal,
  offlineAllocated: FixedDecimal,
): FixedDecimal {
  return physical.minus(centralReserved).minus(offlineAllocated)
}

export function remainingQuota(
  allocated: FixedDecimal,
  consumed: FixedDecimal,
  returned: FixedDecimal,
): FixedDecimal {
  const remaining = allocated.minus(consumed).minus(returned)
  if (remaining.isNegative()) {
    throw RuleViolation.invalid(
      'allocation',
      'stock.allocation_overdrawn',
      'Le quota est consommé et restitué au-delà de son allocation.',
    )
  }
  return remaining
}

export function localAvailable(
  allocated: FixedDecimal,
  consumed: FixedDecimal,
  returned: FixedDecimal,
  localReserved: FixedDecimal,
): FixedDecimal {
  return remainingQuota(allocated, consumed, returned).minus(localReserved)
}

export function assertCanTake(
  requested: FixedDecimal,
  available: FixedDecimal,
  rule = 'stock.unavailable',
): void {
  Quantity.forLine(requested, false)
  if (requested.compare(available) > 0) {
    throw new RuleViolation(
      'STOCK_UNAVAILABLE',
      rule,
      'quantity',
      'Quantité demandée supérieure au stock disponible.',
      { available: available.isNegative() ? Quantity.of('0').canonical() : available.canonical() },
    )
  }
}

/** Remise hors-ligne sous quota appliquée au serveur : sortie du physique et quota consommés ensemble. */
export function applySyncedQuotaDelivery(
  physical: FixedDecimal,
  allocated: FixedDecimal,
  consumed: FixedDecimal,
  returned: FixedDecimal,
  quantity: FixedDecimal,
): { physical: FixedDecimal; consumed: FixedDecimal } {
  assertCanTake(quantity, remainingQuota(allocated, consumed, returned), 'stock.quota_exceeded')
  const physicalAfter = physical.minus(quantity)
  if (physicalAfter.isNegative()) {
    throw new RuleViolation(
      'STOCK_UNAVAILABLE',
      'stock.physical_negative',
      'quantity',
      'La sortie rendrait le stock physique négatif.',
      { available: physical.canonical() },
    )
  }
  return {
    physical: physicalAfter,
    consumed: Quantity.bounded(consumed.units + quantity.units, 'consumed_quantity'),
  }
}
