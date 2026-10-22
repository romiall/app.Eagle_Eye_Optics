import { sha256Hex } from '../engine/hash'
import {
  type DeclareDocumentInput,
  type DocumentFilter,
  type DocumentKey,
  documentKeyString,
  type DocumentMeta,
  type DocumentStore,
  DocumentStoreError,
  type DocumentStoreUsage,
  type PutDocumentInput,
  type StoredDocument,
} from './port'

interface Entry {
  meta: DocumentMeta
  bytes: Uint8Array | null
}

function copyOf(meta: DocumentMeta): DocumentMeta {
  return { ...meta }
}

/**
 * Conservation en MÉMOIRE (tests et aperçu) : rien ne survit à la fermeture de l'onglet. Elle n'est PAS
 * une conservation : l'écran qui l'emploie ne doit jamais dire qu'une pièce est « enregistrée sur ce
 * poste ». Elle applique les mêmes règles que le futur coffre chiffré : original jamais remplacé,
 * empreinte vérifiée à la lecture, quota sans éviction, suppression refusée tant que le serveur n'a pas
 * reçu la pièce.
 */
export class MemoryDocumentStore implements DocumentStore {
  private readonly entries = new Map<string, Entry>()
  private readonly quotaBytes: number | null

  constructor(options: { quotaBytes?: number } = {}) {
    this.quotaBytes = options.quotaBytes ?? null
  }

  async declare(input: DeclareDocumentInput): Promise<DocumentMeta> {
    const id = documentKeyString(input.key)
    const existing = this.entries.get(id)
    if (existing !== undefined) {
      if (existing.meta.documentId !== input.documentId) {
        throw new DocumentStoreError(
          'DOCUMENT_CONFLICT',
          'Cette source a déjà une pièce avec un autre identifiant.',
        )
      }
      return copyOf(existing.meta)
    }
    const meta: DocumentMeta = {
      ...input.key,
      documentId: input.documentId,
      templateVersion: input.templateVersion,
      sha256: null,
      sizeBytes: 0,
      pageCount: 0,
      createdAt: input.createdAt,
      fileName: input.fileName,
      state: 'pending',
      failureCode: null,
      upload: 'not_uploaded',
      entityId: input.entityId,
    }
    this.entries.set(id, { meta, bytes: null })
    return copyOf(meta)
  }

  async put(input: PutDocumentInput): Promise<DocumentMeta> {
    const id = documentKeyString(input.key)
    const digest = await sha256Hex(input.bytes)
    const existing = this.entries.get(id)
    if (existing !== undefined && existing.meta.state === 'ready') {
      if (existing.meta.sha256 === digest) return copyOf(existing.meta)
      throw new DocumentStoreError(
        'DOCUMENT_CONFLICT',
        'Une pièce différente existe déjà pour cette source : l’original n’est jamais remplacé.',
      )
    }
    if (existing !== undefined && existing.meta.documentId !== input.documentId) {
      throw new DocumentStoreError(
        'DOCUMENT_CONFLICT',
        'Cette source a déjà une pièce avec un autre identifiant.',
      )
    }
    if (this.quotaBytes !== null && (await this.usage()).bytes + input.bytes.byteLength > this.quotaBytes) {
      throw new DocumentStoreError(
        'DOCUMENT_QUOTA',
        'Place insuffisante pour conserver cette pièce : exportez les pièces déjà déposées avant de continuer.',
      )
    }
    const meta: DocumentMeta = {
      ...input.key,
      documentId: input.documentId,
      templateVersion: input.templateVersion,
      sha256: digest,
      sizeBytes: input.bytes.byteLength,
      pageCount: input.pageCount,
      createdAt: existing?.meta.createdAt ?? input.createdAt,
      fileName: input.fileName,
      state: 'ready',
      failureCode: null,
      upload: existing?.meta.upload ?? 'not_uploaded',
      entityId: input.entityId,
    }
    this.entries.set(id, { meta, bytes: input.bytes.slice() })
    return copyOf(meta)
  }

  async fail(key: DocumentKey, failureCode: string): Promise<DocumentMeta> {
    const entry = this.entries.get(documentKeyString(key))
    if (entry === undefined) {
      throw new DocumentStoreError('DOCUMENT_NOT_FOUND', 'Aucune pièce annoncée pour cette source.')
    }
    if (entry.meta.state === 'ready') {
      throw new DocumentStoreError(
        'DOCUMENT_CONFLICT',
        'Une pièce prête ne passe jamais en échec : elle est conservée.',
      )
    }
    entry.meta = { ...entry.meta, state: 'failed', failureCode }
    return copyOf(entry.meta)
  }

  async getMeta(key: DocumentKey): Promise<DocumentMeta | null> {
    const entry = this.entries.get(documentKeyString(key))
    return entry === undefined ? null : copyOf(entry.meta)
  }

  async get(key: DocumentKey): Promise<StoredDocument | null> {
    const entry = this.entries.get(documentKeyString(key))
    if (entry === undefined || entry.meta.state !== 'ready' || entry.bytes === null) return null
    const digest = await sha256Hex(entry.bytes)
    if (digest !== entry.meta.sha256) {
      throw new DocumentStoreError(
        'DOCUMENT_INTEGRITY',
        'L’empreinte de la pièce conservée ne correspond plus : la pièce n’est pas rendue.',
      )
    }
    return { meta: copyOf(entry.meta), bytes: entry.bytes.slice() }
  }

  async list(filter: DocumentFilter = {}): Promise<DocumentMeta[]> {
    return [...this.entries.values()]
      .map((entry) => entry.meta)
      .filter(
        (meta) =>
          (filter.documentType === undefined || meta.documentType === filter.documentType) &&
          (filter.entityId === undefined || meta.entityId === filter.entityId) &&
          (filter.state === undefined || meta.state === filter.state) &&
          (filter.upload === undefined || meta.upload === filter.upload),
      )
      .map(copyOf)
      .sort((left, right) => left.createdAt.localeCompare(right.createdAt))
  }

  async markUploaded(key: DocumentKey): Promise<DocumentMeta> {
    const entry = this.entries.get(documentKeyString(key))
    if (entry === undefined || entry.meta.state !== 'ready') {
      throw new DocumentStoreError('DOCUMENT_NOT_FOUND', 'Aucune pièce prête pour cette source.')
    }
    entry.meta = { ...entry.meta, upload: 'uploaded' }
    return copyOf(entry.meta)
  }

  async remove(key: DocumentKey): Promise<void> {
    const id = documentKeyString(key)
    const entry = this.entries.get(id)
    if (entry === undefined) return
    if (entry.meta.state === 'ready' && entry.meta.upload !== 'uploaded') {
      throw new DocumentStoreError(
        'DOCUMENT_NOT_UPLOADED',
        'Cette pièce n’est pas encore déposée sur le serveur : elle ne peut pas être supprimée.',
      )
    }
    this.entries.delete(id)
  }

  async usage(): Promise<DocumentStoreUsage> {
    let bytes = 0
    for (const entry of this.entries.values()) bytes += entry.meta.sizeBytes
    return { count: this.entries.size, bytes, quotaBytes: this.quotaBytes }
  }

  /** Altère les octets d'une pièce conservée : réservé aux tests d'intégrité. */
  corruptForTests(key: DocumentKey): void {
    const entry = this.entries.get(documentKeyString(key))
    if (entry?.bytes && entry.bytes.length > 0) entry.bytes[0] = (entry.bytes[0] ?? 0) ^ 0xff
  }
}
