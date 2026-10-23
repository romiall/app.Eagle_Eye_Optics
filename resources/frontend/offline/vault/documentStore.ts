import { documentCompartment } from '../../documents/access'
import { sha256Hex } from '../../documents/engine/hash'
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
} from '../../documents/store/port'

import type { Vault } from './vault'

/**
 * Conservation CHIFFRÉE des PDF originaux dans le coffre (O01 ; port `DocumentStore` de D01, sans changer son API).
 * Mêmes règles que la version mémoire : original jamais remplacé, empreinte vérifiée à la lecture, quota sans
 * éviction, suppression refusée tant que le serveur n'a pas reçu la pièce. Les métadonnées ET les octets sont scellés
 * dans le compartiment du document (`documentCompartment` : FINANCIAL ou CLINICAL) ; l'index en clair ne garde que la
 * clé opaque de la pièce, son type et l'identifiant opaque de sa source.
 *
 * Exige une session ouverte du coffre : un profil sans le compartiment de la pièce ne la lit ni ne la liste.
 */
export class VaultDocumentStore implements DocumentStore {
  private readonly quotaBytes: number | null

  constructor(
    private readonly vault: Vault,
    options: { quotaBytes?: number } = {},
  ) {
    this.quotaBytes = options.quotaBytes ?? null
  }

  async declare(input: DeclareDocumentInput): Promise<DocumentMeta> {
    const id = documentKeyString(input.key)
    const existing = await this.read(id)
    if (existing !== null) {
      if (existing.meta.documentId !== input.documentId) {
        throw new DocumentStoreError(
          'DOCUMENT_CONFLICT',
          'Cette source a déjà une pièce avec un autre identifiant.',
        )
      }
      return existing.meta
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
    await this.write(meta, null)
    return { ...meta }
  }

  async put(input: PutDocumentInput): Promise<DocumentMeta> {
    const id = documentKeyString(input.key)
    const digest = await sha256Hex(input.bytes)
    const existing = await this.read(id)
    if (existing !== null && existing.meta.state === 'ready') {
      if (existing.meta.sha256 === digest) return existing.meta
      throw new DocumentStoreError(
        'DOCUMENT_CONFLICT',
        'Une pièce différente existe déjà pour cette source : l’original n’est jamais remplacé.',
      )
    }
    if (existing !== null && existing.meta.documentId !== input.documentId) {
      throw new DocumentStoreError(
        'DOCUMENT_CONFLICT',
        'Cette source a déjà une pièce avec un autre identifiant.',
      )
    }
    if (
      this.quotaBytes !== null &&
      (await this.usage()).bytes + input.bytes.byteLength > this.quotaBytes
    ) {
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
    await this.write(meta, input.bytes)
    return { ...meta }
  }

  async fail(key: DocumentKey, failureCode: string): Promise<DocumentMeta> {
    const existing = await this.read(documentKeyString(key))
    if (existing === null)
      throw new DocumentStoreError('DOCUMENT_NOT_FOUND', 'Aucune pièce annoncée pour cette source.')
    if (existing.meta.state === 'ready') {
      throw new DocumentStoreError(
        'DOCUMENT_CONFLICT',
        'Une pièce prête ne passe jamais en échec : elle est conservée.',
      )
    }
    const meta: DocumentMeta = { ...existing.meta, state: 'failed', failureCode }
    await this.write(meta, null)
    return { ...meta }
  }

  async getMeta(key: DocumentKey): Promise<DocumentMeta | null> {
    return (await this.read(documentKeyString(key)))?.meta ?? null
  }

  async get(key: DocumentKey): Promise<StoredDocument | null> {
    const entry = await this.read(documentKeyString(key))
    if (entry === null || entry.meta.state !== 'ready' || entry.bytes === null) return null
    if ((await sha256Hex(entry.bytes)) !== entry.meta.sha256) {
      throw new DocumentStoreError(
        'DOCUMENT_INTEGRITY',
        'L’empreinte de la pièce conservée ne correspond plus : la pièce n’est pas rendue.',
      )
    }
    return { meta: entry.meta, bytes: entry.bytes }
  }

  async list(filter: DocumentFilter = {}): Promise<DocumentMeta[]> {
    const ids = await this.vault.documentIds({
      ...(filter.documentType === undefined ? {} : { kind: filter.documentType }),
      ...(filter.entityId === undefined ? {} : { entityId: filter.entityId }),
    })
    const metas: DocumentMeta[] = []
    for (const id of ids) {
      const entry = await this.read(id)
      if (entry === null) continue
      const meta = entry.meta
      if (filter.state !== undefined && meta.state !== filter.state) continue
      if (filter.upload !== undefined && meta.upload !== filter.upload) continue
      metas.push(meta)
    }
    return metas.sort((left, right) => left.createdAt.localeCompare(right.createdAt))
  }

  async markUploaded(key: DocumentKey): Promise<DocumentMeta> {
    const entry = await this.read(documentKeyString(key))
    if (entry === null || entry.meta.state !== 'ready') {
      throw new DocumentStoreError('DOCUMENT_NOT_FOUND', 'Aucune pièce prête pour cette source.')
    }
    const meta: DocumentMeta = { ...entry.meta, upload: 'uploaded' }
    await this.write(meta, entry.bytes)
    return { ...meta }
  }

  async remove(key: DocumentKey): Promise<void> {
    const id = documentKeyString(key)
    const entry = await this.read(id)
    if (entry === null) return
    if (entry.meta.state === 'ready' && entry.meta.upload !== 'uploaded') {
      throw new DocumentStoreError(
        'DOCUMENT_NOT_UPLOADED',
        'Cette pièce n’est pas encore déposée sur le serveur : elle ne peut pas être supprimée.',
      )
    }
    await this.vault.deleteDocument(id)
  }

  async usage(): Promise<DocumentStoreUsage> {
    const metas = await this.list()
    return {
      count: metas.length,
      bytes: metas.reduce((sum, meta) => sum + meta.sizeBytes, 0),
      quotaBytes: this.quotaBytes,
    }
  }

  private async read(id: string): Promise<{ meta: DocumentMeta; bytes: Uint8Array | null } | null> {
    const entry = await this.vault.getDocument<DocumentMeta>(id)
    return entry === null ? null : { meta: { ...entry.meta }, bytes: entry.bytes }
  }

  /** Une écriture remplace la ligne de la MÊME pièce (version = révision) ; l'original prêt n'est jamais réécrit autrement. */
  private async write(meta: DocumentMeta, bytes: Uint8Array | null): Promise<void> {
    await this.vault.putDocument({
      id: documentKeyString(meta),
      kind: meta.documentType,
      compartment: documentCompartment(meta.documentType),
      entityId: meta.entityId,
      version: meta.revisionNo,
      meta,
      bytes,
    })
  }
}
