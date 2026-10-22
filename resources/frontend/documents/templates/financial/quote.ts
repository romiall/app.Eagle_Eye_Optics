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

/**
 * Devis / facture proforma (fichier 03 : « Le devis est facultatif en L1 et ne remplace aucun de ces
 * objets »). Ce modèle est PRÉPARATOIRE : le devis n'est livré qu'en L1, ses mentions restent à valider.
 * Il ne dit jamais qu'une somme est due ou réglée : aucun règlement n'est enregistré sur un devis, et il
 * ne crée ni vente, ni réservation de stock, ni numéro de facture.
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
  const timeZone = data.clinic.timezone
  const withTax = hasValidatedTax(data.tax)

  const headerFields: FieldRow[] = [
    { label: 'Numéro', value: data.number ?? 'Non attribué (brouillon)' },
    {
      label: draft ? 'Date du brouillon' : 'Date',
      value: formatDateFr(requireInstant(draft ? resolved.now : data.issuedAt, 'date'), timeZone),
    },
  ]
  if (data.validUntil !== null) {
    headerFields.push({ label: 'Valable jusqu’au', value: formatCivilDateFr(data.validUntil) })
  }

  return assembleModel({
    kind: 'QUOTE',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'financial',
    heading: draft ? 'DEVIS (brouillon)' : 'DEVIS',
    subtitle: 'Proforma',
    number: data.number,
    documentAt: data.issuedAt,
    draft,
    headerFields,
    pdfTitle: draft ? 'Devis - brouillon' : `Devis ${data.number ?? ''}`.trim(),
    body: [
      fields(patientRows(data.patient, 'Devis établi pour'), 1),
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
