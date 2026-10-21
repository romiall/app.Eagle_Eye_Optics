import { LedgerBalance } from '@/domain/billing/ledger'
import { RuleViolation } from '@/domain/errors'
import { positiveMoney, signedMoney } from '@/domain/values/money'

import {
  type Freshness,
  type LocalRecord,
  mergeFacts,
  type MergedRecord,
  type ServerSnapshot,
} from './merge'
import { isUnconfirmedState } from './syncStates'

/**
 * Solde d'une facture vu par le poste : faits financiers consolidés par le serveur et faits
 * enregistrés sur ce poste, fusionnés par identifiant (jamais un total serveur + un total local),
 * puis calculés par la règle F08 (`LedgerBalance`, jumelle du serveur et de `vw_invoice_balances`).
 *
 * - Comptés : faits consolidés, faits acceptés après l'instantané, faits du poste pas encore
 *   confirmés (encaissement réel enregistré ici, numéro définitif) — signalés « non confirmés ».
 * - En revue, non comptés : un fait en conflit, et tout fait qui en dépend (contre-écriture d'un
 *   paiement en litige, remboursement d'un avoir en litige) ou dont le fait d'origine est inconnu
 *   ou rejeté. Tant qu'un fait est en revue, aucun nouvel encaissement n'est admis sans décision de
 *   la gérante (contrat 23 : jamais un second paiement créé arbitrairement).
 * - Rejetés après revue : non comptés, conservés pour la trace.
 * Une facture n'est « soldée » que si tout est confirmé par le serveur, sans fait en revue, avec des
 * données distantes complètes.
 */
export type LedgerFactKind = 'payment' | 'reversal' | 'credit_note' | 'refund'

export interface LedgerFact {
  id: string
  kind: LedgerFactKind
  /** Montant strictement positif en FCFA (le sens vient du type de fait). */
  amount: number
  /** Fait d'origine : paiement contre-passé, avoir remboursé (null si aucun). */
  parentId?: string | null
}

export interface LedgerProjectionInput {
  /** Total TTC figé de la facture émise (snapshot immuable). */
  totalGross: number
  server: ServerSnapshot<LedgerFact> | null
  local: readonly LocalRecord<LedgerFact>[]
}

export type ReviewReason = 'conflict' | 'parent-in-review' | 'parent-rejected' | 'parent-unknown'

export interface ReviewedFact {
  record: MergedRecord<LedgerFact>
  reason: ReviewReason
}

export interface LedgerProjection {
  /** Solde des faits comptés (règle F08). */
  balance: LedgerBalance
  /** Faits comptés dans le solde, avec leur origine et leur état. */
  counted: MergedRecord<LedgerFact>[]
  /** Somme des faits comptés mais pas encore confirmés par le serveur (par type). */
  unconfirmed: Readonly<Record<LedgerFactKind, number>>
  /** Faits en revue : non comptés, à résoudre par décision explicite. */
  inReview: ReviewedFact[]
  /** Faits rejetés après revue : non comptés, conservés pour la trace. */
  rejected: MergedRecord<LedgerFact>[]
  /** Version du grand livre connue du serveur : 1 + faits confirmés (consolidés ou acceptés). */
  confirmedLedgerVersion: number
  /** Vrai tant qu'un fait est en revue : aucun nouvel encaissement sans décision de la gérante. */
  collectionBlocked: boolean
  /** Montant encaissable maintenant (0 si l'encaissement est bloqué). */
  collectable: number
  /** Vrai seulement si rien n'est dû, tout est confirmé, sans fait en revue, données complètes. */
  settled: boolean
  freshness: Freshness
}

function isConfirmed(record: MergedRecord<LedgerFact>): boolean {
  return record.state === 'consolidated' || record.state === 'acknowledged'
}

function isCountable(record: MergedRecord<LedgerFact>): boolean {
  return (
    isConfirmed(record) || (record.state !== 'consolidated' && isUnconfirmedState(record.state))
  )
}

/** Somme exacte en FCFA (BigInt, bornes F08), jamais en flottant. */
function sumAmounts(records: readonly MergedRecord<LedgerFact>[]): number {
  let total = 0n
  for (const record of records) total += BigInt(positiveMoney(record.value.amount, 'amount'))
  return signedMoney(total, 'amount')
}

export function projectLedger(input: LedgerProjectionInput): LedgerProjection {
  const view = mergeFacts(input.server, input.local)
  const byId = new Map(view.records.map((record) => [record.id, record]))
  const rejected = view.records.filter((record) => record.state === 'rejected')
  const review = new Map<string, ReviewReason>()
  for (const record of view.records) {
    if (record.state === 'conflict') review.set(record.id, 'conflict')
  }

  // Un fait dont l'origine est en revue, rejetée ou inconnue passe lui-même en revue (en chaîne).
  let changed = true
  while (changed) {
    changed = false
    for (const record of view.records) {
      if (review.has(record.id) || record.state === 'rejected') continue
      const parentId = record.value.parentId ?? null
      if (parentId === null) continue
      const parent = byId.get(parentId)
      const reason: ReviewReason | null =
        parent === undefined
          ? 'parent-unknown'
          : parent.state === 'rejected'
            ? 'parent-rejected'
            : review.has(parentId)
              ? 'parent-in-review'
              : null
      if (reason !== null) {
        review.set(record.id, reason)
        changed = true
      }
    }
  }

  const counted = view.records.filter((record) => !review.has(record.id) && isCountable(record))
  const inReview = view.records
    .filter((record) => review.has(record.id))
    .map((record) => ({ record, reason: review.get(record.id) ?? 'conflict' }))

  const amounts = (kind: LedgerFactKind): number[] =>
    counted.filter((record) => record.value.kind === kind).map((record) => record.value.amount)
  const balance = LedgerBalance.of({
    totalGross: input.totalGross,
    payments: amounts('payment'),
    reversals: amounts('reversal'),
    credits: amounts('credit_note'),
    refunds: amounts('refund'),
  })

  const unconfirmedFacts = counted.filter((record) => !isConfirmed(record))
  const unconfirmedOf = (kind: LedgerFactKind): number =>
    sumAmounts(unconfirmedFacts.filter((record) => record.value.kind === kind))
  const collectionBlocked = inReview.length > 0

  return {
    balance,
    counted,
    unconfirmed: {
      payment: unconfirmedOf('payment'),
      reversal: unconfirmedOf('reversal'),
      credit_note: unconfirmedOf('credit_note'),
      refund: unconfirmedOf('refund'),
    },
    inReview,
    rejected,
    confirmedLedgerVersion: 1 + counted.filter(isConfirmed).length,
    collectionBlocked,
    collectable: collectionBlocked ? 0 : balance.amountDue(),
    settled:
      balance.amountDue() === 0 &&
      inReview.length === 0 &&
      unconfirmedFacts.length === 0 &&
      view.freshness.complete,
    freshness: view.freshness,
  }
}

/**
 * Contrôle d'un nouvel encaissement sur ce solde : refusé tant qu'un fait est en revue (le montant
 * contesté a peut-être déjà été encaissé), puis plafonné au reste dû (règle F08).
 */
export function assertCollectable(projection: LedgerProjection, amount: number): void {
  if (projection.collectionBlocked) {
    throw new RuleViolation(
      'PAYMENT_EXCEEDS_BALANCE',
      'payment.review_pending',
      'amount',
      'Un fait financier de cette facture est en litige : aucun encaissement sans décision de la gérante.',
      { collectable: 0 },
    )
  }
  projection.balance.assertPayment(amount)
}
