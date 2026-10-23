// @vitest-environment node
import { afterEach, describe, expect, it } from 'vitest'

import {
  localAvailableUnits,
  nextNumber,
  ownsInvoice,
  quantityUnits,
  remainingNumbers,
  renderNumber,
  storePreparation,
  unitsToQuantity,
} from '@/offline/allocations/allocations'
import type { AllocationRow } from '@/offline/vault/db'

import { type PreparedDevice, prepared, samplePreparation } from './support/preparedDevice'

/**
 * Ressources préparées du poste (O03, poste) : quantités en millièmes exacts, rendu des numéros identique au serveur,
 * prochain numéro de la plage active, épuisement annoncé, disponible local d'un quota, propriété de facture, et fusion
 * d'une nouvelle préparation qui ne recule JAMAIS un curseur local et clôt ce que le serveur ne rend plus. Fictif.
 */
let device: PreparedDevice | null = null

afterEach(() => {
  device?.vault.dispose()
  device?.db.close()
  device = null
})

describe('règles pures', () => {
  it('quantités DECIMAL(12,3) en millièmes exacts, aller et retour', () => {
    expect(quantityUnits('3.000')).toBe(3000)
    expect(quantityUnits('2.5')).toBe(2500)
    expect(quantityUnits('0.125')).toBe(125)
    expect(quantityUnits('7')).toBe(7000)
    expect(quantityUnits('-1')).toBeNull()
    expect(quantityUnits('1.2345')).toBeNull()
    expect(unitsToQuantity(2500)).toBe('2.5')
    expect(unitsToQuantity(3000)).toBe('3')
    expect(unitsToQuantity(125)).toBe('0.125')
  })

  it('le numéro est rendu comme le serveur', () => {
    expect(renderNumber('EE', 2026, 41, 4)).toBe('EE-2026-0041')
    expect(renderNumber('TEST-FAC', 2026, 301, 6)).toBe('TEST-FAC-2026-000301')
    expect(renderNumber('EE', 2026, 12345, 4)).toBe('EE-2026-12345')
  })

  it('prochain numéro, épuisement et année de la série', () => {
    const rows: AllocationRow[] = [
      {
        id: 'A1',
        kind: 'NUMBER_RANGE',
        status: 'ACTIVE',
        grantId: 'G',
        documentType: 'PATIENT',
        documentYear: 2026,
        rangeStart: 1,
        rangeEnd: 2,
        nextValue: 3,
        prefix: 'EE',
        width: 4,
        version: 1,
        updatedAt: '',
      },
      {
        id: 'A2',
        kind: 'NUMBER_RANGE',
        status: 'ACTIVE',
        grantId: 'G',
        documentType: 'PATIENT',
        documentYear: 2026,
        rangeStart: 10,
        rangeEnd: 12,
        nextValue: 11,
        prefix: 'EE',
        width: 4,
        version: 1,
        updatedAt: '',
      },
      {
        id: 'A3',
        kind: 'NUMBER_RANGE',
        status: 'CLOSED',
        grantId: 'G',
        documentType: 'PATIENT',
        documentYear: 2026,
        rangeStart: 20,
        rangeEnd: 30,
        nextValue: 20,
        prefix: 'EE',
        width: 4,
        version: 1,
        updatedAt: '',
      },
    ]
    expect(nextNumber(rows, 'PATIENT', 2026)?.rendered).toBe('EE-2026-0011')
    expect(remainingNumbers(rows, 'PATIENT', 2026)).toBe(2)
    expect(nextNumber(rows, 'PATIENT', 2027)).toBeNull()
    expect(nextNumber(rows, 'INVOICE', 2026)).toBeNull()
  })

  it('disponible local d’un quota et propriété de facture', () => {
    const quota: Extract<AllocationRow, { kind: 'STOCK_QUOTA' }> = {
      id: 'Q',
      kind: 'STOCK_QUOTA',
      status: 'ACTIVE',
      itemId: 'I',
      stockPositionId: 'P',
      stockLotId: null,
      allocatedUnits: 3000,
      consumedUnits: 0,
      returnedUnits: 0,
      reservedLocalUnits: 1000,
      consumedLocalUnits: 0,
      version: 1,
      updatedAt: '',
    }
    expect(localAvailableUnits(quota)).toBe(2000)
    expect(localAvailableUnits({ ...quota, status: 'CLOSED' })).toBe(0)
    expect(
      ownsInvoice(
        [
          {
            id: 'O',
            kind: 'WRITE_OWNER',
            status: 'ACTIVE',
            invoiceId: 'F',
            epoch: 1,
            version: 1,
            updatedAt: '',
          },
        ],
        'F',
      ),
    ).toBe(true)
    expect(
      ownsInvoice(
        [
          {
            id: 'O',
            kind: 'WRITE_OWNER',
            status: 'CLOSED',
            invoiceId: 'F',
            epoch: 1,
            version: 1,
            updatedAt: '',
          },
        ],
        'F',
      ),
    ).toBe(false)
  })
})

describe('fusion d’une préparation', () => {
  it('conserve plages, quotas, propriétés et catalogue figé', async () => {
    device = await prepared()
    const rows = await device.db.allocations.toArray()

    expect(rows.filter((row) => row.kind === 'NUMBER_RANGE')).toHaveLength(3)
    const patient = rows.find(
      (row) => row.kind === 'NUMBER_RANGE' && row.documentType === 'PATIENT',
    )
    expect(patient).toMatchObject({ rangeStart: 41, rangeEnd: 43, nextValue: 41 })
    expect(rows.find((row) => row.kind === 'STOCK_QUOTA')).toMatchObject({
      allocatedUnits: 3000,
      reservedLocalUnits: 0,
    })
    expect(ownsInvoice(rows, '01J9ZXK4M2Q8R7S6T5V4W3F001')).toBe(true)
    const catalog = await device.vault.getEntity<{ catalog_version: string }>(
      'catalog_snapshot',
      'current',
    )
    expect(catalog?.value.catalog_version).toBe('catalogue-test')
  })

  it('un curseur local ne recule jamais ; ce que le serveur ne rend plus est clos, jamais supprimé', async () => {
    device = await prepared()
    const patientId = '01J9ZXK4M2Q8R7S6T5V4W3A001'
    const row = await device.db.allocations.get(patientId)
    if (row === undefined || row.kind !== 'NUMBER_RANGE') throw new Error('plage absente')
    // Le poste a consommé 41 et 42 hors ligne ; le serveur ne connaît encore que 41.
    await device.db.allocations.put({ ...row, nextValue: 43 })

    const next = samplePreparation({
      number_allocations: samplePreparation()
        .number_allocations.map((range) =>
          range.allocation_id === patientId ? { ...range, last_consumed_value: '41' } : range,
        )
        .filter((range) => range.document_type !== 'RECEIPT'),
      write_owners: [],
    })
    await storePreparation(device.vault, next, '2026-10-22T10:00:00.000Z')

    const after = await device.db.allocations.toArray()
    expect(after.find((item) => item.id === patientId)).toMatchObject({ nextValue: 43 })
    expect(after.find((item) => item.id === '01J9ZXK4M2Q8R7S6T5V4W3A003')).toMatchObject({
      status: 'CLOSED',
    })
    expect(after.find((item) => item.kind === 'WRITE_OWNER')).toMatchObject({ status: 'CLOSED' })
  })
})
