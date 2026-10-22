import type { DocumentKind } from '../engine/model'

/**
 * PORT de conservation des PDF (D01). Le moteur de documents ne sait pas OÙ les pièces sont gardées :
 * il parle à ce port. En production, l'implémentation sera le coffre local chiffré (tâches O01 à O05) ;
 * `memory.ts` en est la version volatile pour les tests et l'aperçu. Ce fichier ne dépend ni de Dexie
 * ni du dossier `offline/` (règle ESLint d'architecture).
 *
 * Correspondance avec le coffre (`docs/implementation/vault.md`, façade `putDocument` / `getDocument`) :
 *   compartiment   = `documentCompartment(kind)` (FINANCIAL pour les pièces financières, CLINICAL pour
 *                    les documents cliniques) — la clé de compartiment chiffre les octets du PDF ;
 *   genre (`kind`) = `documentType` ;
 *   identifiant    = `documentId` (ULID de `generated_documents.id`, fourni par la commande locale) ;
 *   version        = `revisionNo`.
 * L'index en clair du coffre ne reçoit que des identifiants opaques et ces classifications ; le nom du
 * patient, un montant ou un contenu clinique ne figurent JAMAIS dans les métadonnées d'une pièce.
 *
 * Règles de conservation (fichiers 11, 19, 23) :
 *  - l'original d'une pièce n'est jamais remplacé : même clé et mêmes octets = rien ne change (retry
 *    idempotent) ; même clé et octets différents = conflit, l'original reste intact ;
 *  - la lecture vérifie l'empreinte SHA-256 des octets ; une pièce altérée n'est jamais rendue ;
 *  - une pièce non déposée sur le serveur ne s'efface pas ; la place disponible est bornée (quota) et
 *    jamais récupérée en supprimant une pièce ;
 *  - un échec de génération se reprend (`fail` puis nouveau `put`) sans jamais refaire l'opération
 *    financière qui l'a produit.
 */
export type DocumentLocalState = 'pending' | 'ready' | 'failed'
export type DocumentUploadState = 'not_uploaded' | 'uploaded'

export interface DocumentKey {
  documentType: DocumentKind
  /** Clé stable de la source (`generated_documents.source_key`) : reçu, facture, prescription… */
  sourceKey: string
  revisionNo: number
}

export function documentKeyString(key: DocumentKey): string {
  return `${key.documentType}|${key.sourceKey}|${key.revisionNo}`
}

/** Métadonnées d'une pièce : aucune donnée personnelle ni clinique en clair. */
export interface DocumentMeta extends DocumentKey {
  documentId: string
  templateVersion: string
  /** SHA-256 hexadécimal des octets du PDF ; null tant que le PDF n'est pas généré. */
  sha256: string | null
  sizeBytes: number
  pageCount: number
  createdAt: string
  /** Nom de fichier proposé : numéro de pièce ou type et date, jamais un nom de personne. */
  fileName: string
  state: DocumentLocalState
  failureCode: string | null
  upload: DocumentUploadState
  /** Identifiant opaque de la facture, du reçu, de la prescription… à laquelle la pièce se rattache. */
  entityId: string | null
}

export interface StoredDocument {
  meta: DocumentMeta
  bytes: Uint8Array
}

export interface DeclareDocumentInput {
  key: DocumentKey
  documentId: string
  templateVersion: string
  fileName: string
  entityId: string | null
  createdAt: string
}

export interface PutDocumentInput extends DeclareDocumentInput {
  bytes: Uint8Array
  pageCount: number
}

export interface DocumentFilter {
  documentType?: DocumentKind
  entityId?: string
  state?: DocumentLocalState
  upload?: DocumentUploadState
}

export interface DocumentStoreUsage {
  count: number
  bytes: number
  /** Place maximale réservée aux PDF, ou null si aucune borne n'est configurée. */
  quotaBytes: number | null
}

export type DocumentStoreErrorCode =
  | 'DOCUMENT_CONFLICT'
  | 'DOCUMENT_INTEGRITY'
  | 'DOCUMENT_QUOTA'
  | 'DOCUMENT_NOT_FOUND'
  | 'DOCUMENT_NOT_UPLOADED'

export class DocumentStoreError extends Error {
  readonly code: DocumentStoreErrorCode

  constructor(code: DocumentStoreErrorCode, message: string) {
    super(message)
    this.name = 'DocumentStoreError'
    this.code = code
  }
}

export interface DocumentStore {
  /** Annonce une pièce dont le PDF sera généré après le commit (« PDF en préparation »). */
  declare(input: DeclareDocumentInput): Promise<DocumentMeta>
  /**
   * Conserve l'original. Idempotent : même clé et mêmes octets rendent l'entrée existante. Refuse
   * (DOCUMENT_CONFLICT) un contenu différent pour une clé déjà prête, (DOCUMENT_QUOTA) un dépassement.
   */
  put(input: PutDocumentInput): Promise<DocumentMeta>
  /** Note l'échec de génération ; refuse de toucher à une pièce déjà prête. */
  fail(key: DocumentKey, failureCode: string): Promise<DocumentMeta>
  getMeta(key: DocumentKey): Promise<DocumentMeta | null>
  /** Octets et métadonnées ; l'empreinte est vérifiée (DOCUMENT_INTEGRITY sinon). Null si absente ou non prête. */
  get(key: DocumentKey): Promise<StoredDocument | null>
  list(filter?: DocumentFilter): Promise<DocumentMeta[]>
  /** Note le dépôt sur le serveur (après l'ACK de la source) ; seul état qui autorise `remove`. */
  markUploaded(key: DocumentKey): Promise<DocumentMeta>
  /** Supprime une pièce déjà déposée sur le serveur, ou une entrée sans contenu (DOCUMENT_NOT_UPLOADED sinon). */
  remove(key: DocumentKey): Promise<void>
  usage(): Promise<DocumentStoreUsage>
}
