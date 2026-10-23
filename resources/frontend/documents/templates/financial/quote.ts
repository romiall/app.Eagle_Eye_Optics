import type { QuoteDocumentData } from '../../data/financial'
import { invalid } from '../../engine/errors'
import { formatCivilDateFr, formatDateFr, formatXaf, sumMoney } from '../../engine/format'
import {
  type DocumentModel,
  type FieldRow,
  fields,
  notice,
  paragraph,
  table,
  totalRow,
  totals,
} from '../../engine/model'
import {
  assembleModel,
  type BuildOptions,
  requireInstant,
  requireMoney,
  resolveOptions,
} from '../common'
import {
  hasValidatedTax,
  invoiceLineColumns,
  invoiceLineRows,
  patientRows,
  taxNote,
  taxTotalRows,
} from './shared'

/** Mention imposée en tête de chaque devis (L1D07) : un devis n'est jamais une pièce de vente. */
export const QUOTE_NOT_INVOICE = 'Devis — ne vaut pas facture.'

/**
 * Devis / facture proforma (fichier 03 : « Le devis est facultatif en L1 et ne remplace aucun de ces
 * objets » ; L1D07). Le document d'un devis émis est produit de la RÉVISION figée qu'il imprime (numéro,
 * révision, validité, destinataire, lignes, taxe comprise, cabinet) : une ancienne révision se réimprime à
 * l'identique. Il ne dit jamais qu'une somme est due ou réglée : aucun règlement n'est enregistré sur un
 * devis, et il ne crée ni vente, ni réservation de stock, ni numéro de facture.
 */
export function buildQuote(data: QuoteDocumentData, options?: BuildOptions): DocumentModel {
  const resolved = resolveOptions(options)
  const draft = data.number === null
  requireMoney(data.totalGross, 'total_gross')
  const lineSum = sumMoney(
    data.lines.map((line) => line.lineTotalGross),
    'line_total',
  )
  if (lineSum !== data.totalGross) {
    throw invalid(
      'quote.total_mismatch',
      'total_gross',
      'Le total du devis diffère de la somme de ses lignes.',
    )
  }
  if (!draft) requireInstant(data.issuedAt, 'issued_at')
  const revised = !draft && data.revisionNo !== null && data.revisionNo > 1
  if (revised) requireInstant(data.revisedAt, 'revised_at')
  const timeZone = data.clinic.timezone
  const withTax = hasValidatedTax(data.tax)

  const headerFields: FieldRow[] = [
    { label: 'Numéro', value: data.number ?? 'Non attribué (brouillon)' },
  ]
  if (!draft && data.revisionNo !== null) {
    headerFields.push({ label: 'Révision', value: `n° ${data.revisionNo}` })
  }
  headerFields.push({
    label: draft ? 'Date du brouillon' : 'Date',
    value: formatDateFr(requireInstant(draft ? resolved.now : data.issuedAt, 'date'), timeZone),
  })
  if (revised && data.revisedAt !== null) {
    headerFields.push({ label: 'Révisé le', value: formatDateFr(data.revisedAt, timeZone) })
  }
  if (data.validUntil !== null) {
    headerFields.push({ label: 'Valable jusqu’au', value: formatCivilDateFr(data.validUntil) })
  }

  const recipientRows: FieldRow[] =
    data.recipient.kind === 'PATIENT'
      ? patientRows(data.recipient.patient, 'Devis établi pour')
      : [
          { label: 'Devis établi pour', value: data.recipient.name },
          ...(data.recipient.phone === null
            ? []
            : [{ label: 'Téléphone', value: data.recipient.phone }]),
        ]

  // L'instant propre de la pièce : celui de la révision imprimée (même PDF à chaque réimpression).
  const documentAt = revised ? data.revisedAt : data.issuedAt
  const pdfTitle = draft
    ? 'Devis - brouillon'
    : `Devis ${data.number ?? ''}${data.revisionNo === null ? '' : ` - révision ${data.revisionNo}`}`

  return assembleModel({
    kind: 'QUOTE',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'financial',
    heading: draft ? 'DEVIS (brouillon)' : 'DEVIS',
    subtitle: 'Proforma — ne vaut pas facture',
    number: data.number,
    documentAt,
    draft,
    headerFields,
    pdfTitle: pdfTitle.trim(),
    body: [
      notice('warning', QUOTE_NOT_INVOICE),
      fields(recipientRows, 1),
      ...(revised && data.revisionReason !== null
        ? [fields([{ label: 'Motif de la révision', value: data.revisionReason }], 1)]
        : []),
      table(
        'Lignes du devis',
        invoiceLineColumns(resolved.format, withTax),
        invoiceLineRows(data.lines, resolved.format, withTax),
        { keepLastRows: 1 },
      ),
      totals([
        ...taxTotalRows(data.lines, data.tax),
        totalRow('Total du devis', formatXaf(data.totalGross, 'total_gross'), true),
      ]),
      paragraph(taxNote(data.tax), { muted: true, sizePt: 8.5 }),
      ...(data.createdByName === null
        ? []
        : [fields([{ label: 'Établi par', value: data.createdByName }], 1)]),
      notice(
        'info',
        'Ce devis n’est ni une facture ni un reçu : aucun règlement n’y est enregistré et il ne réserve aucun article.',
      ),
    ],
  })
}
