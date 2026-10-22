import { describe, expect, it } from 'vitest'

import { RuleViolation } from '@/domain/errors'
import { assertCollectable, projectLedger, type LedgerFact } from '@/offline/projections/ledger'
import {
  mergeFacts,
  mergeVersioned,
  type LocalRecord,
  type ServerSnapshot,
} from '@/offline/projections/merge'
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

function fact(
  id: string,
  kind: LedgerFact['kind'],
  amount: number,
  parentId: string | null = null,
): LedgerFact {
  return { id, kind, amount, parentId }
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
  })

  it('compte comme non confirmés exactement local, envoyé et pièce liée attendue', () => {
    expect(
      (['local', 'pending', 'waiting_dependency', 'conflict', 'acknowledged', 'rejected'] as const)
        .filter(isUnconfirmedState)
        .sort(),
    ).toEqual(['local', 'pending', 'waiting_dependency'])
  })

  it('distingue « émis localement » et « consolidé sur le serveur »', () => {
    expect(SYNC_STATE_LABELS.local).toBe('Enregistré sur ce poste')
    expect(SYNC_STATE_LABELS.consolidated).toBe('Consolidé sur le serveur')
    expect(new Set(Object.values(SYNC_STATE_LABELS)).size).toBe(6)
  })
})

describe('faits en ajout seul : la version du serveur fait foi, sans doublon', () => {
  it('ne compte qu’une fois un identifiant connu du poste et du serveur', () => {
    const view = mergeFacts(snapshot([payment(P1, 30_000)]), [
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
    const view = mergeFacts(null, [{ value: payment(P1, 10_000), state: 'local' }])

    expect(view.records).toHaveLength(1)
    expect(view.freshness).toEqual({ serverAsOf: null, complete: false })
  })

  it('refuse un identifiant en double plutôt que de compter deux fois', () => {
    const twice: LocalRecord<LedgerFact>[] = [
      { value: payment(P1, 10_000), state: 'local' },
      { value: payment(P1, 10_000), state: 'pending' },
    ]
    expect(() => mergeFacts(null, twice)).toThrow(/double/)
    expect(() => mergeFacts(snapshot([payment(P1, 1), payment(P1, 1)]), [])).toThrow(/double/)
  })
})

interface Patient {
  id: string
  version: number
  phone: string
}

function patient(version: number, phone: string): Patient {
  return { id: P1, version, phone }
}

function patientSnapshot(version: number, phone: string): ServerSnapshot<Patient> {
  return { items: [patient(version, phone)], asOf: AS_OF, complete: true }
}

describe('entités modifiables : une proposition du poste n’est jamais masquée (AT42)', () => {
  const OLD = '+237 600 00 00 01'
  const NEW = '+237 600 00 00 02'

  it('modification du poste en attente : affichée avec son état, version du serveur gardée', () => {
    const [record] = mergeVersioned(patientSnapshot(3, OLD), [
      { value: patient(4, NEW), state: 'pending' },
    ]).records

    expect(record?.value.phone).toBe(NEW)
    expect(record?.state).toBe('pending')
    expect(record?.origin).toBe('local')
    expect(record?.serverValue).toEqual(patient(3, OLD))
    expect(record?.localValue).toEqual(patient(4, NEW))
  })

  it.each(['local', 'pending', 'waiting_dependency', 'conflict'] as const)(
    'état local « %s » : jamais « consolidé »',
    (state) => {
      const [record] = mergeVersioned(patientSnapshot(3, OLD), [
        { value: patient(4, NEW), state },
      ]).records
      expect(record?.state).toBe(state)
      expect(record?.value.phone).toBe(NEW)
    },
  )

  it('proposition en conflit : conservée à côté de la version du serveur, aucun écrasement', () => {
    const [record] = mergeVersioned(patientSnapshot(5, OLD), [
      { value: patient(4, NEW), state: 'conflict' },
    ]).records

    expect(record?.state).toBe('conflict')
    expect(record?.serverValue?.version).toBe(5)
    expect(record?.localValue?.phone).toBe(NEW)
  })

  it('modification acceptée après l’instantané : la plus récente est affichée', () => {
    const [record] = mergeVersioned(patientSnapshot(3, OLD), [
      { value: patient(4, NEW), state: 'acknowledged' },
    ]).records

    expect(record?.value.phone).toBe(NEW)
    expect(record?.state).toBe('acknowledged')
  })

  it('modification acceptée déjà comprise dans l’instantané : le serveur fait foi', () => {
    const [record] = mergeVersioned(patientSnapshot(4, NEW), [
      { value: patient(4, NEW), state: 'acknowledged' },
    ]).records

    expect(record?.state).toBe('consolidated')
    expect(record?.origin).toBe('server')
  })

  it('modification rejetée après revue : le serveur fait foi, la proposition reste lisible', () => {
    const [record] = mergeVersioned(patientSnapshot(3, OLD), [
      { value: patient(4, NEW), state: 'rejected' },
    ]).records

    expect(record?.state).toBe('consolidated')
    expect(record?.value.phone).toBe(OLD)
    expect(record?.localValue?.phone).toBe(NEW)
    expect(record?.localState).toBe('rejected')
  })

  it('fiche créée sur le poste, absente du serveur : reprise avec son état', () => {
    const [record] = mergeVersioned<Patient>({ items: [], asOf: AS_OF, complete: true }, [
      { value: patient(1, NEW), state: 'local' },
    ]).records
    expect(record?.origin).toBe('local')
    expect(record?.serverValue).toBeNull()
  })

  it('sans modification du poste : valeur consolidée', () => {
    const [record] = mergeVersioned(patientSnapshot(3, OLD), []).records
    expect(record?.state).toBe('consolidated')
    expect(record?.localValue).toBeNull()
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
    expect(projection.confirmedLedgerVersion).toBe(2)
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

  it.each(['local', 'pending', 'waiting_dependency'] as const)(
    'un encaissement du poste « %s » est compté et signalé non confirmé',
    (state) => {
      const projection = projectLedger({
        totalGross: 40_000,
        server: snapshot([payment(P1, 10_000)]),
        local: [{ value: payment(P2, 30_000), state }],
      })

      expect(projection.balance.amountDue()).toBe(0)
      expect(projection.unconfirmed.payment).toBe(30_000)
      // Rien n'est dit soldé tant que le serveur n'a pas tout confirmé.
      expect(projection.settled).toBe(false)
    },
  )

  it('paiement en litige : non compté, montré à part, encaissement bloqué, jamais « soldé »', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([payment(P1, 20_000)]),
      local: [{ value: payment(P2, 20_000), state: 'conflict' }],
    })

    expect(projection.balance.amountDue()).toBe(20_000)
    expect(projection.inReview.map((item) => [item.record.id, item.reason])).toEqual([
      [P2, 'conflict'],
    ])
    expect(projection.collectionBlocked).toBe(true)
    expect(projection.collectable).toBe(0)
    expect(projection.settled).toBe(false)
  })

  it('même montant dû à zéro, un litige empêche encore « soldé » (aucun faux soldé)', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([payment(P1, 40_000)]),
      local: [{ value: payment(P2, 20_000), state: 'conflict' }],
    })

    expect(projection.balance.amountDue()).toBe(0)
    expect(projection.inReview).toHaveLength(1)
    expect(projection.settled).toBe(false)
  })

  it('refuse un second encaissement tant qu’un paiement est en litige', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([payment(P1, 20_000)]),
      local: [{ value: payment(P2, 20_000), state: 'conflict' }],
    })

    let refusal: unknown
    try {
      assertCollectable(projection, 20_000)
    } catch (error) {
      refusal = error
    }
    expect(refusal).toBeInstanceOf(RuleViolation)
    expect((refusal as RuleViolation).rule).toBe('payment.review_pending')
    expect((refusal as RuleViolation).details).toEqual({ collectable: 0 })
  })

  it('sans litige, l’encaissement est plafonné au reste dû (règle F08)', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([payment(P1, 20_000)]),
      local: [],
    })

    expect(projection.collectable).toBe(20_000)
    expect(() => assertCollectable(projection, 20_000)).not.toThrow()
    expect(() => assertCollectable(projection, 20_001)).toThrow(RuleViolation)
  })

  it('contre-écriture d’un paiement en litige : elle passe en revue avec lui (reste dû cohérent)', () => {
    // P2 (20 000) en litige ; sa contre-écriture en attente ne doit pas être comptée seule :
    // 40 000 − 20 000 + 20 000 donnerait un reste dû de 40 000 au lieu de 20 000.
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([payment(P1, 20_000)]),
      local: [
        { value: payment(P2, 20_000), state: 'conflict' },
        { value: fact(P3, 'reversal', 20_000, P2), state: 'waiting_dependency' },
      ],
    })

    expect(projection.balance.amountDue()).toBe(20_000)
    expect(projection.inReview.map((item) => [item.record.id, item.reason])).toEqual([
      [P2, 'conflict'],
      [P3, 'parent-in-review'],
    ])
    expect(projection.collectionBlocked).toBe(true)
  })

  it('remboursement d’un avoir en litige : en revue avec lui, le reste dû reste exact', () => {
    const projection = projectLedger({
      totalGross: 80_000,
      server: snapshot([payment(P1, 80_000)]),
      local: [
        { value: fact(P2, 'credit_note', 10_000), state: 'conflict' },
        { value: fact(P3, 'refund', 10_000, P2), state: 'pending' },
      ],
    })

    // Le remboursement seul ferait afficher 10 000 dû alors que rien n'est dû.
    expect(projection.balance.amountDue()).toBe(0)
    expect(projection.inReview.map((item) => item.record.id)).toEqual([P2, P3])
    expect(projection.settled).toBe(false)
  })

  it('fait dont l’origine est rejetée ou inconnue : mis en revue, jamais compté seul', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([]),
      local: [
        { value: payment(P1, 40_000), state: 'rejected' },
        { value: fact(P2, 'reversal', 10_000, P1), state: 'pending' },
        { value: fact(P3, 'refund', 5_000, P4), state: 'pending' },
      ],
    })

    expect(projection.inReview.map((item) => [item.record.id, item.reason])).toEqual([
      [P2, 'parent-rejected'],
      [P3, 'parent-unknown'],
    ])
    expect(projection.rejected.map((record) => record.id)).toEqual([P1])
    expect(projection.balance.amountDue()).toBe(40_000)
  })

  it('propage la revue en chaîne le long des origines', () => {
    const projection = projectLedger({
      totalGross: 50_000,
      server: snapshot([]),
      local: [
        { value: fact(P1, 'credit_note', 10_000), state: 'conflict' },
        { value: fact(P2, 'refund', 4_000, P1), state: 'pending' },
        { value: fact(P3, 'reversal', 1_000, P2), state: 'pending' },
      ],
    })

    expect(projection.inReview.map((item) => item.record.id)).toEqual([P1, P2, P3])
    expect(projection.counted).toEqual([])
  })

  it('ne compte pas un fait rejeté après revue, mais le garde pour la trace', () => {
    const projection = projectLedger({
      totalGross: 40_000,
      server: snapshot([]),
      local: [{ value: payment(P1, 40_000), state: 'rejected' }],
    })

    expect(projection.balance.amountDue()).toBe(40_000)
    expect(projection.rejected.map((record) => record.id)).toEqual([P1])
    expect(projection.collectionBlocked).toBe(false)
  })

  it('applique la règle F08 aux contre-écritures, avoirs et remboursements fusionnés', () => {
    const projection = projectLedger({
      totalGross: 100_000,
      server: snapshot([payment(P1, 100_000), fact(P2, 'reversal', 30_000, P1)]),
      local: [
        { value: fact(P3, 'credit_note', 30_000), state: 'local' },
        { value: fact(P4, 'refund', 1_000, P3), state: 'pending' },
      ],
    })

    // 100 000 − 30 000 (avoir) − 100 000 (paiement) + 30 000 (contre-écriture) + 1 000 = 1 000
    expect(projection.balance.signedBalance()).toBe(1_000)
    expect(projection.balance.ledgerVersion).toBe(5)
    expect(projection.confirmedLedgerVersion).toBe(3)
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

  it('un avoir qui dépasse l’encaissé laisse un crédit à rembourser : jamais « soldé »', () => {
    const projection = projectLedger({
      totalGross: 100_000,
      server: snapshot([payment(P1, 60_000), fact(P2, 'credit_note', 100_000)]),
      local: [],
    })

    expect(projection.balance.amountDue()).toBe(0)
    expect(projection.creditDue).toBe(60_000)
    expect(projection.settled).toBe(false)
    // L'encaissement reste fermé : rien n'est dû, un remboursement est à faire.
    expect(projection.collectable).toBe(0)
  })

  it('un avoir et un paiement qui soldent exactement la facture : soldée, sans crédit', () => {
    const projection = projectLedger({
      totalGross: 100_000,
      server: snapshot([payment(P1, 60_000), fact(P2, 'credit_note', 40_000)]),
      local: [],
    })

    expect(projection.balance.signedBalance()).toBe(0)
    expect(projection.creditDue).toBe(0)
    expect(projection.settled).toBe(true)
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
