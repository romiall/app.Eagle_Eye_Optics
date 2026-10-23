import { session } from '@/app/session'
import {
  creditNoteFromSnapshot,
  invoiceFromResource,
  receiptFromSnapshot,
  refundFromSnapshot,
} from '@/documents/data/financial'
import { quoteFromRevision } from '@/documents/quotes/fromRevision'
import { type DocumentKey, DocumentStoreError } from '@/documents/store/port'
import { TEMPLATE_VERSION } from '@/documents/templates/registry'
import { cashSessionReportData } from '@/domain/billing/cashReport'
import type {
  CashSessionReport,
  CreditNote,
  Invoice,
  QuoteRevision,
  Receipt,
  Refund,
} from '@/domain/contracts/generated'

import { brandLogo } from './brandLogo'
import { currentDocumentClinic } from './clinicIdentity'
import {
  buildDocument,
  documentStore,
  isDocumentStorePersistent,
  keepOriginal,
  type RenderedDocument,
  renderDocument,
} from './documents'
import { depositOriginal } from './documentDeposit'

/**
 * PDF des pièces de vente (facture émise, reçu, avoir, remboursement), générés SUR CE POSTE par le moteur de documents (D01)
 * depuis les snapshots FIGÉS du serveur, jamais depuis l'identité ou les prix actuels (fichier 11).
 *
 * Original et réimpression : le rendu est déterministe (même snapshot, même version de modèle, mêmes
 * octets). La première génération est conservée comme ORIGINAL dans le magasin de pièces ; une
 * réimpression rend la même pièce (même empreinte), jamais une nouvelle pièce ni un nouvel encaissement.
 * La mention « Duplicata » n'est pas apposée : le fichier 11 la réserve au modèle VALIDÉ qui la prévoit,
 * et les modèles actuels (`invoice.v1`, `receipt.v1`) sont des documents de TEST.
 *
 * Le magasin installé est VOLATIL tant que le coffre (O01 à O05) n'est pas livré : rien n'est dit
 * « enregistré sur ce poste » (`isDocumentStorePersistent()`). L'original conservé est déposé sur le serveur
 * (`depositOriginal`, `POST /documents/{id}/content`) qui le garde dans son stockage privé : le document passe à
 * « prêt » ; sans réponse, il reste « en attente » et se redépose à la prochaine impression.
 */

/** Version de modèle que le serveur écrit pour une facture (`PendingDocuments::INVOICE_TEMPLATE`). */
export const INVOICE_TEMPLATE_VERSION = 'invoice.v1'

const REOPENED =
  'Réimpression : l’original conservé pendant cette session est rouvert (le PDF régénéré en différait).'

async function keepOrReopen(
  rendered: RenderedDocument,
  key: DocumentKey,
  documentId: string | null,
  entityId: string,
): Promise<RenderedDocument> {
  if (documentId === null) return rendered
  try {
    await keepOriginal(rendered, { key, documentId, entityId })
    void depositOriginal({ documentId, key, bytes: rendered.bytes, sha256: rendered.sha256 })
    return rendered
  } catch (error) {
    if (!(error instanceof DocumentStoreError) || error.code !== 'DOCUMENT_CONFLICT') throw error
    const stored = await documentStore().get(key)
    if (stored === null || stored.meta.sha256 === null) throw error
    // L'original conservé sur ce poste est celui qui part au serveur (jamais le PDF régénéré qui en diffère).
    void depositOriginal({ documentId, key, bytes: stored.bytes, sha256: stored.meta.sha256 })
    return {
      ...rendered,
      bytes: stored.bytes,
      sha256: stored.meta.sha256,
      warnings: [...rendered.warnings, REOPENED],
    }
  }
}

/** PDF du reçu d'un encaissement, depuis son snapshot figé (version de modèle du snapshot). */
export async function receiptDocument(receipt: Receipt): Promise<RenderedDocument> {
  const model = buildDocument('RECEIPT', receiptFromSnapshot(receipt.snapshot, await brandLogo()))
  const rendered = await renderDocument(model)
  return keepOrReopen(
    rendered,
    { documentType: 'RECEIPT', sourceKey: `receipt:${receipt.id}`, revisionNo: 1 },
    receipt.document?.document_id ?? null,
    receipt.id,
  )
}

/**
 * PDF d'un avoir ÉMIS (B07), depuis son snapshot figé à l'émission (version de modèle du snapshot :
 * `credit_note.v1`). Un avoir sans snapshot (non émis) n'a pas de PDF. Original conservé dans le magasin de
 * pièces sous la clé du serveur (`credit_note:<id>`), réimpression identique.
 */
export async function creditNoteDocument(note: CreditNote): Promise<RenderedDocument> {
  if (note.status !== 'ISSUED' || note.snapshot === null) {
    throw new RangeError(
      'Seul un avoir émis a un PDF : son numéro et son contenu figé n’existent pas encore.',
    )
  }
  const rendered = await renderDocument(
    buildDocument('CREDIT_NOTE', creditNoteFromSnapshot(note.snapshot, await brandLogo())),
  )
  return keepOrReopen(
    rendered,
    { documentType: 'CREDIT_NOTE', sourceKey: `credit_note:${note.id}`, revisionNo: 1 },
    note.document?.document_id ?? null,
    note.id,
  )
}

/**
 * PDF d'un remboursement (B08), depuis son snapshot figé (bénéficiaire = identité figée de la facture,
 * version de modèle `refund.v1`). Original conservé sous la clé du serveur (`refund:<id>`).
 */
export async function refundDocument(refund: Refund): Promise<RenderedDocument> {
  const rendered = await renderDocument(
    buildDocument('REFUND', refundFromSnapshot(refund.snapshot, await brandLogo())),
  )
  return keepOrReopen(
    rendered,
    { documentType: 'REFUND', sourceKey: `refund:${refund.id}`, revisionNo: 1 },
    refund.document?.document_id ?? null,
    refund.id,
  )
}

/**
 * PDF d'une facture ÉMISE, depuis ses snapshots figés (patient, cabinet, taxe) et ses lignes figées.
 * Un brouillon n'a pas de PDF ici : son numéro n'existe pas encore.
 */
export async function invoiceDocument(invoice: Invoice): Promise<RenderedDocument> {
  if (invoice.status !== 'ISSUED') {
    throw new RangeError(
      'Seule une facture émise a un PDF : le brouillon n’a pas encore de numéro.',
    )
  }
  const data = invoiceFromResource({
    invoice,
    // Nom ACTUEL du compte émetteur rendu par le serveur (null : non nommé, jamais inventé).
    issuedByName: invoice.issued_by_name,
    templateVersion: INVOICE_TEMPLATE_VERSION,
    logo: await brandLogo(),
  })
  const rendered = await renderDocument(buildDocument('INVOICE', data))
  return keepOrReopen(
    rendered,
    { documentType: 'INVOICE', sourceKey: `invoice:${invoice.id}`, revisionNo: 1 },
    invoice.document?.document_id ?? null,
    invoice.id,
  )
}

/**
 * PDF du relevé d'une session de caisse (B05), produit sur ce poste depuis le relevé du serveur. Ce n'est pas
 * une pièce numérotée : il n'est ni conservé comme original ni déposé ; une session ouverte donne un relevé
 * PROVISOIRE (le modèle le marque). En-tête : nom du cabinet de la session connectée (document de test tant
 * que les documents de production ne sont pas activés).
 */
export async function cashSessionReportDocument(
  report: CashSessionReport,
): Promise<RenderedDocument> {
  const account = session.account
  if (account === null)
    throw new RangeError('Session non vérifiée : reconnectez-vous pour imprimer le relevé.')
  const data = cashSessionReportData(report, await currentDocumentClinic(), TEMPLATE_VERSION)
  return renderDocument(buildDocument('CASH_SESSION_REPORT', data))
}

/**
 * PDF d'une RÉVISION de devis (L1D07), depuis son snapshot figé (version de modèle du snapshot : `quote.v1`).
 * Chaque révision a sa propre clé (`quote:<id>`, révision n) : une ancienne révision se réimprime à l'identique,
 * jamais remplacée par la plus récente. Original conservé puis déposé sur le serveur comme les pièces de vente.
 */
export async function quoteRevisionDocument(
  quoteId: string,
  revision: QuoteRevision,
): Promise<RenderedDocument> {
  const rendered = await renderDocument(
    buildDocument('QUOTE', quoteFromRevision(revision.snapshot, await brandLogo())),
  )
  return keepOrReopen(
    rendered,
    { documentType: 'QUOTE', sourceKey: `quote:${quoteId}`, revisionNo: revision.revision_no },
    revision.document?.document_id ?? null,
    quoteId,
  )
}

/** Vrai seulement si les PDF conservés survivent à la fermeture du navigateur (coffre livré). */
export function salesDocumentsPersistent(): boolean {
  return isDocumentStorePersistent()
}
