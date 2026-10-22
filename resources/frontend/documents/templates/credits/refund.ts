import type { RefundDocumentData } from '../../data/financial'
import { paymentMethodLabel } from '../../data/labels'
import { formatDateFr, formatDateTimeFr, formatXaf } from '../../engine/format'
import {
  type DocumentModel,
  type FieldRow,
  fields,
  notice,
  signature,
  totalRow,
  totals,
} from '../../engine/model'
import {
  assembleModel,
  type BuildOptions,
  requireInstant,
  requirePositiveMoney,
  requireText,
  resolveOptions,
} from '../common'
import { patientRows } from '../financial/shared'

/**
 * Reçu de remboursement (fichier 11) : numéro, avoir et facture concernés, montant, mode, référence,
 * bénéficiaire. Un remboursement est une sortie d'argent réelle, distincte de l'avoir : il a son propre
 * numéro et son propre reçu, jamais le numéro d'un reçu d'encaissement. La décision d'approbation
 * complète reste privée (elle n'est pas imprimée).
 */
export function buildRefund(data: RefundDocumentData, options?: BuildOptions): DocumentModel {
  const resolved = resolveOptions(options)
  requireText(data.number, 'number')
  requireText(data.creditNoteNumber, 'credit_note_number')
  requireText(data.invoice.number, 'invoice_number')
  requireInstant(data.effectiveAt, 'effective_at')
  requirePositiveMoney(data.amount, 'amount')
  const timeZone = data.clinic.timezone

  const headerFields: FieldRow[] = [
    { label: 'Numéro', value: data.number },
    { label: 'Date', value: formatDateTimeFr(data.effectiveAt, timeZone) },
  ]
  const paymentRows: FieldRow[] = [
    { label: 'Mode de remboursement', value: paymentMethodLabel(data.method) },
  ]
  if (data.reference !== null && data.reference.trim() !== '') {
    paymentRows.push({ label: 'Référence', value: data.reference })
  }

  return assembleModel({
    kind: 'REFUND',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'financial',
    heading: 'REÇU DE REMBOURSEMENT',
    subtitle: null,
    number: data.number,
    documentAt: data.effectiveAt,
    draft: false,
    headerFields,
    pdfTitle: `Remboursement ${data.number}`,
    body: [
      fields(patientRows(data.beneficiary, 'Bénéficiaire'), 1),
      fields(
        [
          { label: 'Avoir n°', value: data.creditNoteNumber },
          { label: 'Facture n°', value: data.invoice.number },
          { label: 'Facture émise le', value: formatDateFr(data.invoice.issuedAt, timeZone) },
        ],
        1,
      ),
      totals([totalRow('Montant remboursé', formatXaf(data.amount, 'amount'), true)]),
      fields(paymentRows, 1),
      fields([{ label: 'Remboursement enregistré par', value: data.createdByName }], 1),
      signature([
        { label: 'Signature du bénéficiaire', heightMm: 18 },
        { label: 'Signature ou cachet du cabinet', heightMm: 18 },
      ]),
      notice(
        'info',
        'Ce reçu atteste la sortie de fonds indiquée au profit du bénéficiaire désigné. Il est distinct de l’avoir et de tout reçu d’encaissement ; la décision d’approbation n’y figure pas.',
      ),
    ],
  })
}
