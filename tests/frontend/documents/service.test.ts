// @vitest-environment node
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  assertMayOpen,
  DocumentAccessError,
  documentStore,
  installDocumentStore,
  isDocumentStorePersistent,
  keepOriginal,
  mayOpen,
  renderDocument,
  resetDocumentStoreForTests,
} from '@/app/services/documents'
import { DEMO_DOCUMENTS } from '@/documents/demo/catalog'
import { demoReceiptDeposit } from '@/documents/demo/fixtures'
import { sha256Hex } from '@/documents/engine/hash'
import { MemoryDocumentStore } from '@/documents/store/memory'
import { DocumentStoreError, type DocumentKey } from '@/documents/store/port'
import { buildReceipt } from '@/documents/templates/financial/receipt'

const session = vi.hoisted(() => ({ currentPermissions: { value: [] as string[] } }))
vi.mock('@/app/session', () => session)

const CASHIER = [
  'patient.read',
  'agenda.read',
  'catalog.read',
  'invoice.read',
  'invoice.create',
  'invoice.issue',
  'payment.collect',
  'cash.own_session',
]
const CLINICIAN = ['patient.read', 'clinical.read', 'clinical.write', 'clinical.export']

function as(permissions: string[]): void {
  session.currentPermissions.value = permissions
}

function demo(id: string) {
  const entry = DEMO_DOCUMENTS.find((candidate) => candidate.id === id)
  if (entry === undefined) throw new Error(id)
  return entry.build()
}

afterEach(() => {
  as([])
  resetDocumentStoreForTests()
})

describe('service des documents : accès', () => {
  it('chaque rôle n’ouvre que ses documents', () => {
    as(CASHIER)
    expect(mayOpen('RECEIPT')).toBe(true)
    expect(mayOpen('INVOICE')).toBe(true)
    expect(mayOpen('OPTICAL_PRESCRIPTION')).toBe(false)
    expect(mayOpen('CLINICAL_RECORD')).toBe(false)

    as(CLINICIAN)
    expect(mayOpen('OPTICAL_PRESCRIPTION')).toBe(true)
    expect(mayOpen('CLINICAL_RECORD')).toBe(true)
    expect(mayOpen('RECEIPT')).toBe(false)
  })

  it('sans droit, assertMayOpen refuse avec une erreur typée', () => {
    as([])

    expect(() => assertMayOpen('RECEIPT')).toThrow(DocumentAccessError)
  })

  it('un document clinique n’est jamais généré pour un compte de caisse', async () => {
    as(CASHIER)

    await expect(renderDocument(demo('prescription'))).rejects.toBeInstanceOf(DocumentAccessError)
    await expect(renderDocument(demo('dossier-clinique'))).rejects.toBeInstanceOf(
      DocumentAccessError,
    )
  })

  it('une pièce financière n’est jamais générée pour un compte clinique', async () => {
    as(CLINICIAN)

    await expect(renderDocument(demo('recu-acompte'))).rejects.toBeInstanceOf(DocumentAccessError)
  })

  it('le refus ne reprend aucune donnée du document', async () => {
    as(CASHIER)
    const error = await renderDocument(demo('prescription')).then(
      () => null,
      (reason: unknown) => reason,
    )

    expect(error).toBeInstanceOf(DocumentAccessError)
    expect((error as Error).message).not.toMatch(/PATIENT|Élodie|TEST-P-/)
  })
})

describe('service des documents : génération locale', () => {
  it('génère le PDF, son empreinte et un nom de fichier sans donnée sensible', async () => {
    as(CASHIER)
    const rendered = await renderDocument(buildReceipt(demoReceiptDeposit()))

    expect(new TextDecoder('latin1').decode(rendered.bytes.slice(0, 5))).toBe('%PDF-')
    expect(rendered.sha256).toBe(await sha256Hex(rendered.bytes))
    expect(rendered.sha256).toMatch(/^[0-9a-f]{64}$/)
    expect(rendered.fileName).toBe('recu-TEST-REC-000001.pdf')
    expect(rendered.pageCount).toBe(1)
    expect(rendered.plan.pages).toHaveLength(1)
    expect(rendered.warnings).toEqual([])
  })

  it('génère la même pièce, octet pour octet, à chaque demande', async () => {
    as(CASHIER)
    const first = await renderDocument(buildReceipt(demoReceiptDeposit()))
    const second = await renderDocument(buildReceipt(demoReceiptDeposit()))

    expect(second.bytes).toEqual(first.bytes)
    expect(second.sha256).toBe(first.sha256)
  })

  it('un compte clinique obtient sa prescription', async () => {
    as(CLINICIAN)
    const rendered = await renderDocument(demo('prescription'))

    expect(rendered.fileName).toBe('prescription-optique-20261014.pdf')
    expect(rendered.model.kind).toBe('OPTICAL_PRESCRIPTION')
  })
})

describe('service des documents : conservation', () => {
  const KEY: DocumentKey = {
    documentType: 'RECEIPT',
    sourceKey: 'receipt:01J9ZXK4M2Q8R7S6T5V4W3002V',
    revisionNo: 1,
  }
  const INPUT = {
    key: KEY,
    documentId: '01J9ZXK4M2Q8R7S6T5V4W30029',
    entityId: '01J9ZXK4M2Q8R7S6T5V4W3002V',
  }

  it('le magasin par défaut est volatil et ne prétend jamais conserver les pièces', () => {
    expect(isDocumentStorePersistent()).toBe(false)
  })

  it('conserve l’original, le rend identique à la réimpression et reste idempotent', async () => {
    as(CASHIER)
    const rendered = await renderDocument(buildReceipt(demoReceiptDeposit()))
    const first = await keepOriginal(rendered, INPUT)
    const again = await keepOriginal(rendered, INPUT)
    const reprint = await documentStore().get(KEY)

    expect(again).toEqual(first)
    expect(first).toMatchObject({
      state: 'ready',
      sha256: rendered.sha256,
      fileName: 'recu-TEST-REC-000001.pdf',
      templateVersion: 'TEST-1',
      createdAt: '2026-10-14T09:30:00.000Z',
    })
    expect(reprint?.bytes).toEqual(rendered.bytes)
  })

  it('refuse de remplacer l’original par un autre rendu de la même source', async () => {
    as(CASHIER)
    const rendered = await renderDocument(buildReceipt(demoReceiptDeposit()))
    await keepOriginal(rendered, INPUT)
    const other = { ...rendered, bytes: new Uint8Array([1, 2, 3]) }

    await expect(keepOriginal(other, INPUT)).rejects.toMatchObject({
      name: 'DocumentStoreError',
      code: 'DOCUMENT_CONFLICT',
    })
    await expect(keepOriginal(other, INPUT)).rejects.toBeInstanceOf(DocumentStoreError)
  })

  it('un magasin persistant installé est annoncé comme tel', () => {
    installDocumentStore(new MemoryDocumentStore(), { persistent: true })

    expect(isDocumentStorePersistent()).toBe(true)
    resetDocumentStoreForTests()
    expect(isDocumentStorePersistent()).toBe(false)
  })
})
