/**
 * Mouvements d'espèces hors vente d'une session de caisse (L1D05) : règles d'écran, sans effet, identiques à celles
 * du serveur (`CashMovements::assertShape`). Le montant saisi est TOUJOURS positif ; le sens vient de la nature :
 * un dépôt entre dans le tiroir, un retrait ou une dépense en sort, une correction de l'ouverture va dans le sens
 * choisi. Une dépense a une catégorie, et elle seule. Une sortie ne dépasse jamais les espèces attendues du tiroir.
 * Jamais un paiement : ni facture, ni reçu.
 */
import type { CashMovementSubject } from '@/domain/contracts/generated'

export type CashMovementKind = CashMovementSubject['movement_kind']
export type CorrectionDirection = 'IN' | 'OUT'

export const MOVEMENT_KINDS: readonly CashMovementKind[] = [
  'EXPENSE',
  'WITHDRAWAL',
  'DEPOSIT',
  'OPENING_CORRECTION',
]

export const MOVEMENT_REASON_MIN = 3
export const MOVEMENT_REASON_MAX = 500
export const MOVEMENT_REFERENCE_MAX = 180
export const MOVEMENT_AMOUNT_MAX = 1_000_000_000_000

export interface MovementInput {
  kind: CashMovementKind | null
  /** Montant saisi en FCFA, positif (null : non saisi). */
  amount: number | null
  /** Sens d'une correction de l'ouverture (ignoré pour les autres natures). */
  direction: CorrectionDirection | null
  categoryId: string | null
  reason: string
  reference: string
}

export interface CheckedMovement {
  kind: CashMovementKind
  signedAmount: number
  categoryId: string | null
  reason: string
  reference: string | null
}

export type MovementCheck =
  | { ok: true; movement: CheckedMovement }
  | {
      ok: false
      errors: Partial<
        Record<'kind' | 'amount' | 'direction' | 'category' | 'reason' | 'reference', string>
      >
    }

/** Montant signé d'un mouvement : entrée positive, sortie négative. */
export function signedAmountFor(
  kind: CashMovementKind,
  amount: number,
  direction: CorrectionDirection | null,
): number {
  if (kind === 'DEPOSIT') return amount
  if (kind === 'OPENING_CORRECTION') return direction === 'OUT' ? -amount : amount
  return -amount
}

/**
 * Vérifie la saisie. `available` : espèces attendues du tiroir à l'affichage (une sortie qui les dépasse est
 * refusée ici comme au serveur, qui rejuge à l'instant).
 */
export function checkMovement(input: MovementInput, available: number): MovementCheck {
  const errors: Partial<
    Record<'kind' | 'amount' | 'direction' | 'category' | 'reason' | 'reference', string>
  > = {}
  if (input.kind === null) errors.kind = 'Choisissez la nature du mouvement.'
  if (input.amount === null || !Number.isSafeInteger(input.amount) || input.amount <= 0) {
    errors.amount = 'Indiquez un montant en FCFA, supérieur à zéro.'
  } else if (input.amount > MOVEMENT_AMOUNT_MAX) {
    errors.amount = 'Montant trop élevé.'
  }
  if (input.kind === 'OPENING_CORRECTION' && input.direction === null) {
    errors.direction = 'Indiquez si la correction ajoute ou retire des espèces.'
  }
  if (input.kind === 'EXPENSE' && input.categoryId === null) {
    errors.category = 'Choisissez la catégorie de la dépense.'
  }
  const reason = input.reason.trim()
  if (reason.length < MOVEMENT_REASON_MIN) {
    errors.reason = `Indiquez le motif (au moins ${MOVEMENT_REASON_MIN} caractères).`
  } else if (reason.length > MOVEMENT_REASON_MAX) {
    errors.reason = `Le motif dépasse ${MOVEMENT_REASON_MAX} caractères.`
  }
  const reference = input.reference.trim()
  if (reference.length > MOVEMENT_REFERENCE_MAX) {
    errors.reference = `La référence dépasse ${MOVEMENT_REFERENCE_MAX} caractères.`
  }
  if (input.kind !== null && input.amount !== null && errors.amount === undefined) {
    const signed = signedAmountFor(input.kind, input.amount, input.direction)
    if (signed < 0 && available + signed < 0) {
      errors.amount = `Le tiroir ne contient en théorie que ${available} FCFA : une sortie ne peut pas les dépasser.`
    }
  }
  if (Object.keys(errors).length > 0 || input.kind === null || input.amount === null) {
    return { ok: false, errors }
  }
  return {
    ok: true,
    movement: {
      kind: input.kind,
      signedAmount: signedAmountFor(input.kind, input.amount, input.direction),
      categoryId: input.kind === 'EXPENSE' ? input.categoryId : null,
      reason,
      reference: reference === '' ? null : reference,
    },
  }
}

/** Somme signée des mouvements de la liste : entrées moins sorties (entiers exacts). */
export function movementsTotal(movements: readonly { signed_amount: number }[]): number {
  return movements.reduce((sum, movement) => sum + movement.signed_amount, 0)
}
