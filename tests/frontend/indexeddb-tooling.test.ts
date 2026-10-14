import Dexie, { type Table } from 'dexie'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

interface ProbeRecord {
  id: string
  value: string
}

class ProbeDatabase extends Dexie {
  records!: Table<ProbeRecord, string>

  constructor(name: string) {
    super(name)
    this.version(1).stores({ records: 'id' })
  }
}

/**
 * Valide l'outillage des futurs tests du moteur local (Dexie sur fake-indexeddb) : lecture, écriture
 * et atomicité d'une transaction. Aucun test de règle métier ni de chiffrement ici.
 */
describe('outillage IndexedDB de test', () => {
  let database: ProbeDatabase

  beforeEach(() => {
    database = new ProbeDatabase(`probe-${crypto.randomUUID()}`)
  })

  afterEach(async () => {
    database.close()
    await Dexie.delete(database.name)
  })

  it('écrit puis relit un enregistrement après réouverture de la base', async () => {
    await database.records.put({ id: 'a', value: 'Éléonore — 80 000' })
    database.close()

    await database.open()
    expect(await database.records.get('a')).toEqual({ id: 'a', value: 'Éléonore — 80 000' })
  })

  it('annule toute la transaction lorsqu’une écriture échoue (tout ou rien)', async () => {
    await expect(
      database.transaction('rw', database.records, async () => {
        await database.records.put({ id: 'b', value: 'première écriture' })
        throw new Error('panne simulée avant validation')
      }),
    ).rejects.toThrow('panne simulée')

    expect(await database.records.count()).toBe(0)
  })
})
