import { currentPermissions } from '@/app/session'
import { mayOpenDocument } from '@/documents/access'
import { documentFileName } from '@/documents/output/fileName'
import { downloadPdf, type OpenOutcome, openPdf, printPdf } from '@/documents/output/browser'
import { sha256Hex } from '@/documents/engine/hash'
import type { DocumentKind, DocumentModel } from '@/documents/engine/model'
import type { LayoutPlan } from '@/documents/engine/plan'
import { MemoryDocumentStore } from '@/documents/store/memory'
import type { DocumentKey, DocumentMeta, DocumentStore } from '@/documents/store/port'

/**
 * Service d'application des documents (D01, D02) : l'unique accès des écrans au moteur de documents.
 * Un écran ne construit pas de PDF lui-même et n'importe jamais le dossier `offline/` ; il appelle ce
 * service pour (1) générer le PDF d'un modèle de document, LOCALEMENT (aucune requête réseau, le
 * moteur jsPDF est chargé à la demande), (2) l'ouvrir, le télécharger ou l'imprimer, (3) conserver
 * l'original dans le magasin de pièces.
 *
 * Conservation : tant que le coffre chiffré (O01 à O05) n'est pas livré, le magasin est VOLATILE
 * (mémoire). Un écran ne doit alors jamais dire qu'une pièce est « enregistrée sur ce poste » :
 * `isDocumentStorePersistent()` le dit.
 */
export interface RenderedDocument {
  model: DocumentModel
  bytes: Uint8Array
  /** Empreinte SHA-256 hexadécimale des octets du PDF. */
  sha256: string
  pageCount: number
  /** Nom de fichier proposé (sans donnée sensible). */
  fileName: string
  /** Anomalies de données ou de mise en page à montrer avant d'imprimer. */
  warnings: readonly string[]
  /** Plan de mise en page : alimente l'aperçu à l'écran. */
  plan: LayoutPlan
}

export class DocumentAccessError extends Error {
  constructor(kind: DocumentKind) {
    super(`Ce compte n’a pas le droit d’ouvrir ce type de document (${kind}).`)
    this.name = 'DocumentAccessError'
  }
}

/** Le compte connecté peut-il ouvrir ce type de document (le serveur et le coffre revérifient) ? */
export function mayOpen(kind: DocumentKind): boolean {
  return mayOpenDocument(kind, currentPermissions.value)
}

export function assertMayOpen(kind: DocumentKind): void {
  if (!mayOpen(kind)) throw new DocumentAccessError(kind)
}

/** Génère le PDF d'un modèle, sur ce poste. Le moteur de rendu est chargé à la première demande. */
export async function renderDocument(model: DocumentModel): Promise<RenderedDocument> {
  assertMayOpen(model.kind)
  const { renderDocument: render } = await import('@/documents/engine/pdf')
  const rendered = render(model)
  return {
    model,
    bytes: rendered.bytes,
    sha256: await sha256Hex(rendered.bytes),
    pageCount: rendered.pageCount,
    fileName: documentFileName(model),
    warnings: rendered.warnings,
    plan: rendered.plan,
  }
}

export function openRendered(document: RenderedDocument): OpenOutcome {
  return openPdf(document.bytes, document.fileName)
}

export function printRendered(document: RenderedDocument): OpenOutcome {
  return printPdf(document.bytes, document.fileName)
}

export function downloadRendered(document: RenderedDocument): void {
  downloadPdf(document.bytes, document.fileName)
}

// --- Conservation ---------------------------------------------------------------------------------

let store: DocumentStore = new MemoryDocumentStore()
let persistent = false

export function documentStore(): DocumentStore {
  return store
}

/** Branche le magasin de pièces (le coffre chiffré, une fois livré). */
export function installDocumentStore(next: DocumentStore, options: { persistent: boolean }): void {
  store = next
  persistent = options.persistent
}

/** Vrai seulement si le magasin installé conserve les pièces après la fermeture du navigateur. */
export function isDocumentStorePersistent(): boolean {
  return persistent
}

export function resetDocumentStoreForTests(): void {
  store = new MemoryDocumentStore()
  persistent = false
}

export interface KeepOriginalInput {
  key: DocumentKey
  /** Identifiant de `generated_documents.id` fourni par la commande locale (déterministe). */
  documentId: string
  entityId: string | null
}

/**
 * Conserve le PDF d'une pièce émise comme original (jamais remplacé). Idempotent : redemander la
 * conservation du même PDF rend la même entrée ; un PDF différent pour la même source est refusé.
 */
export async function keepOriginal(
  document: RenderedDocument,
  input: KeepOriginalInput,
): Promise<DocumentMeta> {
  return store.put({
    key: input.key,
    documentId: input.documentId,
    templateVersion: document.model.templateVersion,
    fileName: document.fileName,
    entityId: input.entityId,
    createdAt: document.model.meta.documentAt,
    bytes: document.bytes,
    pageCount: document.pageCount,
  })
}

export { buildDocument } from '@/documents/templates/registry'
export { DEMO_DOCUMENTS, demoDocumentById } from '@/documents/demo/catalog'
export type { DemoDocument } from '@/documents/demo/catalog'
export { type PreviewPage, type PreviewShape, previewPages } from '@/documents/engine/preview'
