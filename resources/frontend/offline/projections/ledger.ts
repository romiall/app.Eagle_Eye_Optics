import { LedgerBalance } from '@/domain/billing/ledger'
import { positiveMoney, signedMoney } from '@/domain/values/money'

import {
  type Freshness,
  type LocalRecord,
  mergeById,
  type MergedRecord,
  type ServerSnapshot,
} from './merge'
import { isUnconfirmedState } from './syncStates'

/**
 * Solde d'une facture vu par le poste : faits financiers consolidés par le serveur et faits
 * enregistrés sur ce poste, fusionnés par identifiant (jamais un total serveur + un total local),
 * puis calculés par la règle F08 (`LedgerBalance`, jumelle du serveur et de `vw_invoice_balances`).
 *
 * - Comptés : faits consolidés, faits acceptés localement après l'instantané, faits du poste pas
 *   encore confirmés (encaissement réel enregistré ici, numéro définitif) — signalés « non confirmés ».
 * - Non comptés, montrés à part : un fait en conflit (« en litige » : ni compté, ni oublié ; l'écran
 *   doit dire de ne pas réencaisser sans décision de la gérante) et un fait rejeté après revue
 *   (jamais un fait financier accepté).
 * Une facture avec un fait en litige n'est jamais présentée comme soldée (`settled` faux).
 */
export type LedgerFactKind = 'payment' | 'reversal' | 'credit_note' | 'refund'

export interface LedgerFact {
  id: string
  kind: LedgerFactKind
  /** Montant strictement positif en FCFA (le sens vient du type de fait). */
  amount: number
}

export interface LedgerProjectionInput {
  /** Total TTC figé de la facture émise (snapshot immuable). */
  totalGross: number
  server: ServerSnapshot<LedgerFact> | null
  local: readonly LocalRecord<LedgerFact>[]
}

export interface LedgerProjection {
  balance: LedgerBalance
  /** Faits comptés dans le solde, avec leur origine et leur état. */
  counted: MergedRecord<LedgerFact>[]
  /** Somme des faits comptés mais pas encore confirmés par le serveur (par type). */
  unconfirmed: Readonly<Record<LedgerFactKind, number>>
  /** Faits en conflit : non comptés, à résoudre par décision explicite. */
  inReview: MergedRecord<LedgerFact>[]
  /** Faits rejetés après revue : non comptés, conservés pour la trace. */
  rejected: MergedRecord<LedgerFact>[]
  /** Vrai seulement si rien n'est dû, sans fait en litige et avec des données distantes complètes. */
  settled: boolean
  freshness: Freshness
}

function isConfirmed(record: MergedRecord<LedgerFact>): boolean {
  return record.state === 'consolidated' || record.state === 'acknowledged'
}

function isCounted(record: MergedRecord<LedgerFact>): boolean {
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
  const view = mergeById(input.server, input.local)
  const counted = view.records.filter(isCounted)
  const inReview = view.records.filter((record) => record.state === 'conflict')
  const rejected = view.records.filter((record) => record.state === 'rejected')

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
    settled: balance.amountDue() === 0 && inReview.length === 0 && view.freshness.complete,
    freshness: view.freshness,
  }
}
