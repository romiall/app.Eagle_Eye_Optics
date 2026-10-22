import { session } from '@/app/session'
import { invoiceFromResource, receiptFromSnapshot } from '@/documents/data/financial'
import { type DocumentKey, DocumentStoreError } from '@/documents/store/port'
import { TEMPLATE_VERSION } from '@/documents/templates/registry'
import { cashSessionReportData } from '@/domain/billing/cashReport'
import type { CashSessionReport, Invoice, Receipt } from '@/domain/contracts/generated'

import { currentDocumentClinic } from './clinicIdentity'
import {
  buildDocument,
  documentStore,
  isDocumentStorePersistent,
  keepOriginal,
  type RenderedDocument,
  renderDocument,
} from './documents'

/**
 * PDF des pièces de vente (facture émise, reçu), générés SUR CE POSTE par le moteur de documents (D01)
 * depuis les snapshots FIGÉS du serveur, jamais depuis l'identité ou les prix actuels (fichier 11).
 *
 * Original et réimpression : le rendu est déterministe (même snapshot, même version de modèle, mêmes
 * octets). La première génération est conservée comme ORIGINAL dans le magasin de pièces ; une
 * réimpression rend la même pièce (même empreinte), jamais une nouvelle pièce ni un nouvel encaissement.
 * La mention « Duplicata » n'est pas apposée : le fichier 11 la réserve au modèle VALIDÉ qui la prévoit,
 * et les modèles actuels (`invoice.v1`, `receipt.v1`) sont des documents de TEST.
 *
 * Le magasin installé est VOLATIL tant que le coffre (O01 à O05) n'est pas livré : rien n'est dit
 * « enregistré sur ce poste » (`isDocumentStorePersistent()`), et le dépôt du PDF sur le serveur
 * (`POST /documents/{id}/content`) n'existe pas encore : l'état du document reste `PENDING` côté serveur.
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
    return rendered
  } catch (error) {
    if (!(error instanceof DocumentStoreError) || error.code !== 'DOCUMENT_CONFLICT') throw error
    const stored = await documentStore().get(key)
    if (stored === null || stored.meta.sha256 === null) throw error
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
  const model = buildDocument('RECEIPT', receiptFromSnapshot(receipt.snapshot))
  const rendered = await renderDocument(model)
  return keepOrReopen(
    rendered,
    { documentType: 'RECEIPT', sourceKey: `receipt:${receipt.id}`, revisionNo: 1 },
    receipt.document?.document_id ?? null,
    receipt.id,
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

/** Vrai seulement si les PDF conservés survivent à la fermeture du navigateur (coffre livré). */
export function salesDocumentsPersistent(): boolean {
  return isDocumentStorePersistent()
}
