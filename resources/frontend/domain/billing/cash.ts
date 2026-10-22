import type { CloseCashSessionPayload, OpenCashSessionPayload } from '../contracts/generated'
import { moneyAmount, signedMoney } from '../values/money'
import { trimBlanks } from '../values/text'

/**
 * Session de caisse (B05), EN LIGNE : ouverture avec un fond, clôture avec le compté. L'attendu est
 * celui que le serveur projette à la lecture (`theoretical_cash` = fond + espèces encaissées −
 * contre-écritures − remboursements espèces + mouvements autorisés) ; à la clôture, il le recalcule
 * sous verrou et refuse (409 `VERSION_CONFLICT`, `expected_amount`) un attendu changé depuis
 * l'affichage. L'écart (compté − attendu) est dit sans jugement ; s'il n'est pas nul, un motif est
 * exigé. Jumeau de `CashClosing::variance` du serveur.
 */
export const VARIANCE_REASON_MAX_LENGTH = 1000

export function cashVariance(counted: number, expected: number): number {
  moneyAmount(counted, 'counted_amount')
  return signedMoney(counted - expected, 'variance')
}

/** Écart dit sans jugement : « Aucun écart », « compté supérieur… », « compté inférieur… ». */
export function varianceDescription(variance: number): string {
  if (variance === 0) return 'Aucun écart entre le compté et l’attendu.'
  return variance > 0
    ? 'Le compté est supérieur à l’attendu.'
    : 'Le compté est inférieur à l’attendu.'
}

export function buildOpenSessionPayload(input: {
  cashSessionId: string
  cashRegisterId: string
  openingAmount: number
  openedAt: string
}): OpenCashSessionPayload {
  return {
    cash_session_id: input.cashSessionId,
    cash_register_id: input.cashRegisterId,
    opening_amount: moneyAmount(input.openingAmount, 'opening_amount'),
    opened_at: input.openedAt,
  }
}

export type CloseField = 'counted' | 'reason'

export function validateClosing(input: {
  counted: number | null
  expected: number
  reason: string
}): { ok: true; variance: number; reason: string | null } | {
  ok: false
  errors: Partial<Record<CloseField, string>>
} {
  if (input.counted === null) {
    return { ok: false, errors: { counted: 'Saisissez le montant compté dans le tiroir.' } }
  }
  const variance = cashVariance(input.counted, input.expected)
  const reason = trimBlanks(input.reason)
  if (reason.length > VARIANCE_REASON_MAX_LENGTH) {
    return {
      ok: false,
      errors: { reason: `Le motif compte au plus ${VARIANCE_REASON_MAX_LENGTH} caractères.` },
    }
  }
  if (variance !== 0 && reason === '') {
    return { ok: false, errors: { reason: 'Un écart de caisse exige un motif.' } }
  }
  return { ok: true, variance, reason: reason === '' ? null : reason }
}

export function buildCloseSessionPayload(input: {
  cashSessionId: string
  closedAt: string
  counted: number
  expected: number
  variance: number
  reason: string | null
}): CloseCashSessionPayload {
  return {
    cash_session_id: input.cashSessionId,
    closed_at: input.closedAt,
    counted_amount: input.counted,
    expected_amount: input.expected,
    variance: input.variance,
    variance_reason: input.reason,
  }
}
