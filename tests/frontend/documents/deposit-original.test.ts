import { afterEach, describe, expect, it, vi } from 'vitest'

import { depositOriginal } from '@/app/services/documentDeposit'
import { documentStore, resetDocumentStoreForTests } from '@/app/services/documents'

/**
 * Dépôt de l'original d'un document sur le serveur (D01) : octets bruts en application/pdf, empreinte annoncée,
 * clé d'idempotence dépendant du document et du contenu ; confirmé : la pièce est marquée déposée dans le magasin
 * du poste ; sans réponse : rien n'est conclu. Données fictives.
 */
const DOCUMENT_ID = '01J9ZXK4M2Q8R7S6T5V4W3DOC1'
const KEY = {
  documentType: 'RECEIPT',
  sourceKey: 'receipt:01J9ZXK4M2Q8R7S6T5V4W3REC1',
  revisionNo: 1,
} as const
const BYTES = new TextEncoder().encode('%PDF-1.4 fictif')
const SHA = 'a'.repeat(64)

afterEach(() => {
  vi.unstubAllGlobals()
  resetDocumentStoreForTests()
})

describe('dépôt de l’original', () => {
  it('envoie les octets bruts avec leur empreinte et une clé stable', async () => {
    const fetchMock = vi.fn<typeof fetch>(
      async () => new Response(JSON.stringify({ data: { id: DOCUMENT_ID } }), { status: 200 }),
    )
    vi.stubGlobal('fetch', fetchMock)
    const marked = vi.spyOn(documentStore(), 'markUploaded').mockResolvedValue({} as never)

    await expect(
      depositOriginal({ documentId: DOCUMENT_ID, key: KEY, bytes: BYTES, sha256: SHA }),
    ).resolves.toBe(true)

    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(url).toBe(`/api/v1/documents/${DOCUMENT_ID}/content`)
    expect(init?.method).toBe('POST')
    expect(init?.body).toBe(BYTES)
    const headers = init?.headers as Record<string, string>
    expect(headers['Content-Type']).toBe('application/pdf')
    expect(headers['X-Content-Sha256']).toBe(SHA)
    expect(headers['Idempotency-Key']).toBe(`doc-${DOCUMENT_ID}-${SHA.slice(0, 16)}`)
    expect(marked).toHaveBeenCalledWith(KEY)
  })

  it('un refus ou une coupure ne marque rien comme déposé', async () => {
    const marked = vi.spyOn(documentStore(), 'markUploaded').mockResolvedValue({} as never)
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>(
        async () =>
          new Response(
            JSON.stringify({
              error: { code: 'STATE_TRANSITION_REFUSED', message: 'Déjà conservé.' },
            }),
            { status: 409 },
          ),
      ),
    )
    await expect(
      depositOriginal({ documentId: DOCUMENT_ID, key: KEY, bytes: BYTES, sha256: SHA }),
    ).resolves.toBe(false)

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>(async () => {
        throw new TypeError('réseau coupé')
      }),
    )
    await expect(
      depositOriginal({ documentId: DOCUMENT_ID, key: KEY, bytes: BYTES, sha256: SHA }),
    ).resolves.toBe(false)
    expect(marked).not.toHaveBeenCalled()
  })
})
