import { describe, expect, it } from 'vitest'

import { projectLedger, type LedgerFact } from '@/offline/projections/ledger'
import { mergeById, type LocalRecord, type ServerSnapshot } from '@/offline/projections/merge'
import {
  isFinalState,
  isUnconfirmedState,
  SYNC_STATE_LABELS,
  syncStateFromAck,
} from '@/offline/projections/syncStates'

// Identifiants et montants FICTIFS.
const P1 = '01J9ZXK4M2Q8R7S6T5V4W3P001'
const P2 = '01J9ZXK4M2Q8R7S6T5V4W3P002'
const P3 = '01J9ZXK4M2Q8R7S6T5V4W3P003'
const P4 = '01J9ZXK4M2Q8R7S6T5V4W3P004'
const AS_OF = '2026-10-15T09:42:00.000Z'

function payment(id: string, amount: number): LedgerFact {
  return { id, kind: 'payment', amount }
}

function snapshot(items: LedgerFact[], complete = true): ServerSnapshot<LedgerFact> {
  return { items, asOf: AS_OF, complete }
}

describe('états de synchronisation (contrat sync/ack.json)', () => {
  it('traduit chaque ACK en état local', () => {
    expect(syncStateFromAck('ACCEPTED')).toBe('acknowledged')
    expect(syncStateFromAck('ALREADY_ACCEPTED')).toBe('acknowledged')
    expect(syncStateFromAck('WAITING_DEPENDENCY')).toBe('waiting_dependency')
    expect(syncStateFromAck('CONFLICT_REQUIRES_REVIEW')).toBe('conflict')
    expect(syncStateFromAck('REJECTED')).toBe('rejected')
  })

  it('ne retire de la file qu’après un ACK final', () => {
    expect(isFinalState('acknowledged')).toBe(true)
    expect(isFinalState('rejected')).toBe(true)
    for (const state of ['local', 'pending', 'waiting_dependency', 'conflict'] as const) {
      expect(isFinalState(state)).toBe(false)
    }
    expect(isUnconfirmedState('conflict')).toBe(false)
    expect(isUnconfirmedState('rejected')).toBe(false)
  })

  it('distingue « émis localement » et « consolidé sur le serveur »', () => {
    expect(SYNC_STATE_LABELS.local).toBe('Enregistré sur ce poste')
    expect(SYNC_STATE_LABELS.consolidated).toBe('Consolidé sur le serveur')
    expect(new Set(Object.values(SYNC_STATE_LABELS)).size).toBe(6)
  })
})

describe('lecture fusionnée par identifiant (mêmes IDs sur le poste et au serveur)', () => {
  it('fait foi la version du serveur pour un ID connu des deux, sans doublon', () => {
    const view = mergeById(snapshot([payment(P1, 30_000)]), [
      { value: payment(P1, 30_000), state: 'acknowledged', document: 'ready' },
      { value: payment(P2, 20_000), state: 'pending' },
    ])

    expect(view.records.map((record) => [record.id, record.origin, record.state])).toEqual([
      [P1, 'server', 'consolidated'],
      [P2, 'local', 'pending'],
    ])
    expect(view.records[0]?.localState).toBe('acknowledged')
    expect(view.records[0]?.document).toBe('ready')
    expect(view.freshness).toEqual({ serverAsOf: AS_OF, complete: true })
  })

  it('sans instantané serveur : données du poste seulement, fraîcheur qui le dit', () => {
    const view = mergeById(null, [{ value: payment(P1, 10_000), state: 'local' }])

    expect(view.records).toHaveLength(1)
    expect(view.freshness).toEqual({ serverAsOf: null, complete: false })
  })

  it('refuse un identifiant en double plutôt que de compter deux fois', () => {
    const twice: LocalRecord<LedgerFact>[] = [
      { value: payment(P1, 10_000), state: 'local' },
      { value: payment(P1, 10_000), state: 'pending' },
    ]
    expect(() => mergeById(null, twice)).toThrow(/double/)
    expect(() => mergeById(snapshot([payment(P1, 1), payment(P1, 1)]), [])).toThrow(/double/)
  })
})

describe('solde d’une facture vu par le poste (aucun total local + serveur)', () => {
  it('ne compte jamais deux fois un paiement connu du poste et du serveur', () => {
    const projection = projectLedger({
      totalGross: 100_000,
      server: snapshot([payment(P1, 30_000)]),
      local: [
        { value: payment(P1, 30_000), state: 'acknowledged' },
        { value: payment(P2, 20_000), state: 'pending' },
      ],
    })

    // Une addition naïve « total serveur + total local » donnerait 80 000 versés.
    expect(projection.balance.paymentsConfirmed).toBe(50_000)
    expect(projection.balance.amountDue()).toBe(50_000)
    expect(projection.unconfirmed.payment).toBe(20_000)
    expect(projection.counted.map((record) => record.id)).toEqual([P1, P2])
  })

  it('compte une fois un paiement accepté après la date de l’instantané', () => {
    const projection = projectLedger({
      totalGross: 50_000,
      server: snapshot([]),
      local: [{ value: payment(P1, 50_000), state: 'acknowledged' }],
    })

    expect(projection.balance.amountDue()).toBe(0)
    expect(projection.unconfirmed.payment).toBe(0)
    expect(projection.settled).toBe(true)
  })

  it('montre à part un paiement en litige : ni compté, ni oublié, jamais « soldé »', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([payment(P1, 20_000)]),
      local: [{ value: payment(P2, 20_000), state: 'conflict' }],
    })

    expect(projection.balance.amountDue()).toBe(20_000)
    expect(projection.inReview.map((record) => record.id)).toEqual([P2])
    expect(projection.settled).toBe(false)
  })

  it('ne compte pas un fait rejeté après revue, mais le garde pour la trace', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([]),
      local: [{ value: payment(P1, 40_000), state: 'rejected' }],
    })

    expect(projection.balance.amountDue()).toBe(40_000)
    expect(projection.rejected.map((record) => record.id)).toEqual([P1])
  })

  it('applique la règle F08 aux contre-écritures, avoirs et remboursements fusionnés', () => {
    const projection = projectLedger({
      totalGross: 100_000,
      server: snapshot([payment(P1, 100_000), { id: P2, kind: 'reversal', amount: 30_000 }]),
      local: [
        { value: { id: P3, kind: 'credit_note', amount: 30_000 }, state: 'local' },
        { value: { id: P4, kind: 'refund', amount: 1_000 }, state: 'pending' },
      ],
    })

    // 100 000 − 30 000 (avoir) − 100 000 (paiement) + 30 000 (contre-écriture) + 1 000 = 1 000
    expect(projection.balance.signedBalance()).toBe(1_000)
    expect(projection.balance.ledgerVersion).toBe(5)
    expect(projection.unconfirmed).toEqual({
      payment: 0,
      reversal: 0,
      credit_note: 30_000,
      refund: 1_000,
    })
  })

  it('ne dit jamais « soldé » avec des données distantes incomplètes ou absentes', () => {
    const incomplete = projectLedger({
      totalGross: 10_000,
      server: snapshot([payment(P1, 10_000)], false),
      local: [],
    })
    const localOnly = projectLedger({
      totalGross: 10_000,
      server: null,
      local: [{ value: payment(P1, 10_000), state: 'local' }],
    })

    expect(incomplete.balance.amountDue()).toBe(0)
    expect(incomplete.settled).toBe(false)
    expect(localOnly.balance.amountDue()).toBe(0)
    expect(localOnly.settled).toBe(false)
    expect(localOnly.freshness.serverAsOf).toBeNull()
  })

  it('refuse un montant non entier ou négatif (règles F08), jamais arrondi', () => {
    expect(() =>
      projectLedger({
        totalGross: 10_000,
        server: null,
        local: [{ value: payment(P1, 10.5), state: 'local' }],
      }),
    ).toThrow()
    expect(() =>
      projectLedger({ totalGross: 10_000, server: snapshot([payment(P1, -5)]), local: [] }),
    ).toThrow()
  })
})
