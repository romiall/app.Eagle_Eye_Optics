import type { CreditNoteDocumentData } from '../../data/financial'
import { invalid } from '../../engine/errors'
import {
  formatDateFr,
  formatNumberFr,
  formatQuantity,
  formatXaf,
  MISSING,
  shortReference,
  sumMoney,
} from '../../engine/format'
import {
  type DocumentModel,
  type FieldRow,
  fields,
  notice,
  paragraph,
  row,
  table,
  type TableColumn,
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
import { hasValidatedTax, isWideTable, patientRows, taxNote } from '../financial/shared'

/**
 * Avoir (fichier 11) : numéro, facture source, lignes et montants corrigés, motif commercial lisible,
 * taxes selon le régime FIGÉ, auteur et référence de l'autorisation de la gérante, date. La décision
 * d'approbation complète reste privée : seule une référence courte est imprimée. Un avoir réduit la
 * créance ; il n'est pas un remboursement (autre pièce, avec son propre reçu).
 */
export function buildCreditNote(
  data: CreditNoteDocumentData,
  options?: BuildOptions,
): DocumentModel {
  const resolved = resolveOptions(options)
  requireText(data.number, 'number')
  requireText(data.invoice.number, 'invoice_number')
  requireText(data.reason, 'reason')
  requireInstant(data.issuedAt, 'issued_at')
  requirePositiveMoney(data.amountGross, 'amount_gross')
  if (data.lines.length === 0) {
    throw invalid('credit_note.no_lines', 'lines', 'Un avoir comporte au moins une ligne.')
  }
  const lineSum = sumMoney(
    data.lines.map((line) => line.amountGross),
    'amount_gross',
  )
  if (lineSum !== data.amountGross) {
    throw invalid(
      'credit_note.total_mismatch',
      'amount_gross',
      'Le montant de l’avoir diffère de la somme de ses lignes.',
    )
  }
  const timeZone = data.clinic.timezone
  const wide = isWideTable(resolved.format)
  const withTax = hasValidatedTax(data.tax) && data.lines.every((line) => line.taxAmount !== null)

  const descriptionColumn: TableColumn = {
    key: 'description',
    header: 'Désignation corrigée',
    width: { fr: 1 },
    align: 'left',
  }
  const quantityColumn: TableColumn = {
    key: 'quantity',
    header: 'Quantité',
    width: { mm: 24 },
    align: 'right',
  }
  const taxColumn: TableColumn = {
    key: 'tax',
    header: 'Dont taxe (FCFA)',
    width: { mm: 26 },
    align: 'right',
  }
  const amountColumn: TableColumn = {
    key: 'amount',
    header: wide ? 'Montant crédité (FCFA)' : 'Montant (FCFA)',
    width: { mm: wide ? 34 : 26 },
    align: 'right',
  }
  const columns: TableColumn[] = wide
    ? [descriptionColumn, quantityColumn, ...(withTax ? [taxColumn] : []), amountColumn]
    : [descriptionColumn, amountColumn]

  const rows = data.lines.map((line) =>
    wide
      ? row([
          line.description,
          line.quantity === null ? MISSING : formatQuantity(line.quantity, 'quantity'),
          ...(withTax ? [formatNumberFr(line.taxAmount ?? 0, 'tax_amount')] : []),
          formatNumberFr(line.amountGross, 'amount_gross'),
        ])
      : row([
          line.quantity === null
            ? line.description
            : `${line.description}\nQuantité : ${formatQuantity(line.quantity, 'quantity')}`,
          formatNumberFr(line.amountGross, 'amount_gross'),
        ]),
  )

  const headerFields: FieldRow[] = [
    { label: 'Numéro', value: data.number },
    { label: 'Date d’émission', value: formatDateFr(data.issuedAt, timeZone) },
    { label: 'Facture d’origine', value: data.invoice.number },
  ]

  return assembleModel({
    kind: 'CREDIT_NOTE',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'financial',
    heading: 'AVOIR',
    subtitle: null,
    number: data.number,
    documentAt: data.issuedAt,
    draft: false,
    headerFields,
    pdfTitle: `Avoir ${data.number}`,
    body: [
      fields(patientRows(data.patient, 'Avoir établi pour'), 1),
      fields(
        [
          { label: 'Facture n°', value: data.invoice.number },
          { label: 'Émise le', value: formatDateFr(data.invoice.issuedAt, timeZone) },
          {
            label: 'Total de la facture',
            value: formatXaf(data.invoice.totalGross, 'total_gross'),
          },
          { label: 'Motif', value: data.reason },
        ],
        1,
      ),
      table('Lignes de l’avoir', columns, rows, { keepLastRows: 1 }),
      totals([totalRow('Montant de l’avoir', formatXaf(data.amountGross, 'amount_gross'), true)]),
      fields(
        [
          { label: 'Établi par', value: data.issuedByName },
          {
            label: 'Autorisation',
            value: `Autorisation de la gérante enregistrée (réf. ${shortReference(data.approvalId)})`,
          },
        ],
        1,
      ),
      paragraph(taxNote(data.tax), { muted: true, sizePt: 8.5 }),
      notice(
        'info',
        'Cet avoir réduit la créance de la facture indiquée ; il ne constitue pas un remboursement. Tout remboursement fait l’objet d’un reçu de remboursement distinct.',
      ),
    ],
  })
}
