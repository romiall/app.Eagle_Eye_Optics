// @vitest-environment node
import { describe, expect, it } from 'vitest'

import { sha256Hex } from '@/documents/engine/hash'
import { MemoryDocumentStore } from '@/documents/store/memory'
import {
  documentKeyString,
  type DocumentKey,
  DocumentStoreError,
  type PutDocumentInput,
} from '@/documents/store/port'

const KEY: DocumentKey = {
  documentType: 'RECEIPT',
  sourceKey: 'receipt:01J9ZXK4M2Q8R7S6T5V4W3002V',
  revisionNo: 1,
}

function bytesOf(text: string): Uint8Array {
  return new TextEncoder().encode(text)
}

function input(overrides: Partial<PutDocumentInput> = {}): PutDocumentInput {
  return {
    key: KEY,
    documentId: '01J9ZXK4M2Q8R7S6T5V4W30029',
    templateVersion: 'TEST-1',
    fileName: 'recu-TEST-REC-000001.pdf',
    entityId: '01J9ZXK4M2Q8R7S6T5V4W3002V',
    createdAt: '2026-10-14T09:30:00.000Z',
    bytes: bytesOf('%PDF-1.3 contenu fictif'),
    pageCount: 1,
    ...overrides,
  }
}

async function code(action: Promise<unknown>): Promise<string> {
  try {
    await action
  } catch (error) {
    if (error instanceof DocumentStoreError) return error.code
    throw error
  }
  throw new Error('Une DocumentStoreError était attendue.')
}

describe('conservation des PDF : original jamais remplacé', () => {
  it('conserve les octets avec leur empreinte SHA-256 et rend une copie à la lecture', async () => {
    const store = new MemoryDocumentStore()
    const meta = await store.put(input())

    expect(meta.state).toBe('ready')
    expect(meta.sha256).toBe(await sha256Hex(bytesOf('%PDF-1.3 contenu fictif')))
    expect(meta.sizeBytes).toBe(bytesOf('%PDF-1.3 contenu fictif').byteLength)
    expect(meta.upload).toBe('not_uploaded')

    const stored = await store.get(KEY)
    expect(stored?.bytes).toEqual(bytesOf('%PDF-1.3 contenu fictif'))
    // Modifier la copie rendue n'altère pas la pièce conservée.
    if (stored !== null) stored.bytes[0] = 0
    expect((await store.get(KEY))?.bytes[0]).toBe(0x25)
  })

  it('est idempotent : le même original redemandé rend la même entrée (reprise après coupure)', async () => {
    const store = new MemoryDocumentStore()
    const first = await store.put(input())
    const second = await store.put(input())

    expect(second).toEqual(first)
    expect((await store.usage()).count).toBe(1)
  })

  it('refuse un contenu différent pour la même source : l’original reste intact', async () => {
    const store = new MemoryDocumentStore()
    const original = await store.put(input())

    expect(await code(store.put(input({ bytes: bytesOf('%PDF-1.3 autre rendu') })))).toBe(
      'DOCUMENT_CONFLICT',
    )
    expect((await store.getMeta(KEY))?.sha256).toBe(original.sha256)
    expect((await store.get(KEY))?.bytes).toEqual(bytesOf('%PDF-1.3 contenu fictif'))
  })

  it('refuse deux identifiants de document pour la même source', async () => {
    const store = new MemoryDocumentStore()
    await store.declare({
      key: KEY,
      documentId: '01J9ZXK4M2Q8R7S6T5V4W30029',
      templateVersion: 'TEST-1',
      fileName: 'recu.pdf',
      entityId: null,
      createdAt: '2026-10-14T09:30:00.000Z',
    })

    expect(await code(store.put(input({ documentId: '01J9ZXK4M2Q8R7S6T5V4W3002A' })))).toBe(
      'DOCUMENT_CONFLICT',
    )
  })

  it('vérifie l’empreinte à la lecture : une pièce altérée n’est jamais rendue', async () => {
    const store = new MemoryDocumentStore()
    await store.put(input())
    store.corruptForTests(KEY)

    expect(await code(store.get(KEY))).toBe('DOCUMENT_INTEGRITY')
  })
})

describe('cycle d’une pièce : en préparation, prête, en échec, déposée', () => {
  const declaration = {
    key: KEY,
    documentId: '01J9ZXK4M2Q8R7S6T5V4W30029',
    templateVersion: 'TEST-1',
    fileName: 'recu-TEST-REC-000001.pdf',
    entityId: '01J9ZXK4M2Q8R7S6T5V4W3002V',
    createdAt: '2026-10-14T09:30:00.000Z',
  } as const

  it('annonce une pièce en préparation, sans octets ni empreinte', async () => {
    const store = new MemoryDocumentStore()
    const meta = await store.declare(declaration)

    expect(meta).toMatchObject({ state: 'pending', sha256: null, sizeBytes: 0 })
    expect(await store.get(KEY)).toBeNull()
  })

  it('un échec de génération se note puis se reprend avec le même identifiant, sans doublon', async () => {
    const store = new MemoryDocumentStore()
    await store.declare(declaration)
    const failed = await store.fail(KEY, 'PDF_RENDER_FAILED')

    expect(failed).toMatchObject({ state: 'failed', failureCode: 'PDF_RENDER_FAILED' })
    expect(await store.list({ state: 'failed' })).toHaveLength(1)

    const ready = await store.put(input())
    expect(ready).toMatchObject({ state: 'ready', failureCode: null })
    expect((await store.usage()).count).toBe(1)
  })

  it('une pièce prête ne passe jamais en échec', async () => {
    const store = new MemoryDocumentStore()
    await store.put(input())

    expect(await code(store.fail(KEY, 'PDF_RENDER_FAILED'))).toBe('DOCUMENT_CONFLICT')
    expect((await store.getMeta(KEY))?.state).toBe('ready')
  })

  it('refuse de noter l’échec d’une source inconnue', async () => {
    expect(await code(new MemoryDocumentStore().fail(KEY, 'X'))).toBe('DOCUMENT_NOT_FOUND')
  })

  it('une pièce non déposée sur le serveur ne se supprime pas ; déposée, elle le peut', async () => {
    const store = new MemoryDocumentStore()
    await store.put(input())

    expect(await code(store.remove(KEY))).toBe('DOCUMENT_NOT_UPLOADED')
    expect(await store.getMeta(KEY)).not.toBeNull()

    expect((await store.markUploaded(KEY)).upload).toBe('uploaded')
    await store.remove(KEY)
    expect(await store.getMeta(KEY)).toBeNull()
  })

  it('une entrée sans contenu (en préparation, en échec) peut être retirée', async () => {
    const store = new MemoryDocumentStore()
    await store.declare(declaration)
    await store.remove(KEY)

    expect(await store.getMeta(KEY)).toBeNull()
  })

  it('le dépôt serveur n’est noté que pour une pièce prête', async () => {
    const store = new MemoryDocumentStore()
    await store.declare(declaration)

    expect(await code(store.markUploaded(KEY))).toBe('DOCUMENT_NOT_FOUND')
  })
})

describe('quota et listes', () => {
  it('refuse un dépassement du quota sans rien supprimer', async () => {
    const store = new MemoryDocumentStore({ quotaBytes: 40 })
    await store.put(input())

    const second = input({
      key: { ...KEY, sourceKey: 'receipt:AUTRE', revisionNo: 1 },
      documentId: '01J9ZXK4M2Q8R7S6T5V4W3002B',
    })
    expect(await code(store.put(second))).toBe('DOCUMENT_QUOTA')
    expect((await store.usage()).count).toBe(1)
    expect(await store.getMeta(KEY)).not.toBeNull()
  })

  it('rapporte l’usage et le quota', async () => {
    const store = new MemoryDocumentStore({ quotaBytes: 1000 })
    await store.put(input())

    expect(await store.usage()).toEqual({
      count: 1,
      bytes: bytesOf('%PDF-1.3 contenu fictif').byteLength,
      quotaBytes: 1000,
    })
    expect((await new MemoryDocumentStore().usage()).quotaBytes).toBeNull()
  })

  it('filtre par type, par entité et par état, sans jamais rendre d’octets', async () => {
    const store = new MemoryDocumentStore()
    await store.put(input())
    await store.put(
      input({
        key: { documentType: 'INVOICE', sourceKey: 'invoice:X', revisionNo: 1 },
        documentId: '01J9ZXK4M2Q8R7S6T5V4W3002C',
        entityId: 'X',
        fileName: 'facture.pdf',
      }),
    )

    expect(await store.list()).toHaveLength(2)
    expect(await store.list({ documentType: 'INVOICE' })).toHaveLength(1)
    expect(await store.list({ entityId: '01J9ZXK4M2Q8R7S6T5V4W3002V' })).toHaveLength(1)
    expect(await store.list({ state: 'failed' })).toHaveLength(0)
    for (const meta of await store.list()) {
      expect(Object.keys(meta)).not.toContain('bytes')
    }
  })

  it('les métadonnées ne portent ni nom, ni montant, ni contenu clinique', async () => {
    const store = new MemoryDocumentStore()
    const meta = await store.put(input())

    expect(JSON.stringify(meta)).not.toMatch(/PATIENT|Élodie|FCFA/)
    expect(meta.fileName).toBe('recu-TEST-REC-000001.pdf')
  })

  it('la clé d’une source est stable', () => {
    expect(documentKeyString(KEY)).toBe('RECEIPT|receipt:01J9ZXK4M2Q8R7S6T5V4W3002V|1')
  })
})
