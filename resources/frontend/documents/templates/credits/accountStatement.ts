import type { AccountStatementData, StatementInvoiceRow } from '../../data/financial'
import { paymentMethodLabel } from '../../data/labels'
import { invalid } from '../../engine/errors'
import {
  formatDateFr,
  formatDateTimeFr,
  formatNumberFr,
  formatXaf,
  sumMoney,
} from '../../engine/format'
import {
  type Block,
  type DocumentModel,
  type FieldRow,
  fields,
  heading,
  notice,
  paragraph,
  row,
  table,
  type TableColumn,
  totalRow,
  totals,
} from '../../engine/model'
import { assembleModel, type BuildOptions, requireInstant, resolveOptions } from '../common'
import { patientRows } from '../financial/shared'

/**
 * État de compte d'un patient (fichier 11) : date de calcul, factures, paiements, avoirs,
 * remboursements, reste à payer et crédit à rembourser. Document DATÉ, pas une pièce permanente : il
 * ne remplace ni les factures ni les reçus. Chaque encaissement garde la date de son reçu (« aucun
 * encaissement ancien ne doit apparaître comme paiement reçu aujourd'hui ») ; une créance d'ouverture
 * reprise d'un carnet papier est signalée à part. Un crédit n'est jamais présenté comme une dette
 * négative : « reste à payer » et « crédit à rembourser » sont deux colonnes, deux totaux.
 *
 * Format A4 uniquement (tableau à huit colonnes).
 */
function column(
  key: string,
  header: string,
  width: TableColumn['width'],
  align: TableColumn['align'],
): TableColumn {
  return { key, header, width, align }
}

/** Solde comptable (fichier 03) : total − avoirs − paiements nets + remboursements. */
function checkBalance(invoice: StatementInvoiceRow): void {
  const expected = invoice.totalGross - invoice.credited - invoice.paidNet + invoice.refunded
  if (expected !== invoice.balance) {
    throw invalid(
      'statement.balance_mismatch',
      'balance',
      'Le solde d’une facture ne correspond pas à total − avoirs − encaissé + remboursé.',
    )
  }
}

export function buildAccountStatement(
  data: AccountStatementData,
  options?: BuildOptions,
): DocumentModel {
  const resolved = resolveOptions({ ...options, format: 'A4' })
  requireInstant(data.asOf, 'as_of')
  data.invoices.forEach(checkBalance)
  const timeZone = data.clinic.timezone

  const owing = data.invoices.map((invoice) => Math.max(0, invoice.balance))
  const credit = data.invoices.map((invoice) => Math.max(0, -invoice.balance))
  const hasLegacy = data.invoices.some((invoice) => invoice.origin === 'LEGACY_BALANCE')

  const headerFields: FieldRow[] = [
    { label: 'Calculé le', value: formatDateTimeFr(data.asOf, timeZone) },
    { label: 'Établi par', value: data.preparedByName },
  ]

  const body: Block[] = [
    fields(patientRows(data.patient), 1),
    notice(
      'info',
      'État daté : il reflète les pièces enregistrées à la date de calcul indiquée. Il ne remplace ni les factures ni les reçus ; chaque encaissement garde la date de son reçu.',
    ),
    heading('Factures', 2),
    table(
      'Factures du patient',
      [
        column('number', 'Facture', { fr: 1 }, 'left'),
        column('date', 'Date', { mm: 20 }, 'left'),
        column('total', 'Total', { mm: 22 }, 'right'),
        column('credited', 'Avoirs', { mm: 20 }, 'right'),
        column('paid', 'Encaissé net', { mm: 22 }, 'right'),
        column('refunded', 'Remboursé', { mm: 20 }, 'right'),
        column('owing', 'Reste à payer', { mm: 22 }, 'right'),
        column('credit', 'Crédit à rembourser', { mm: 22 }, 'right'),
      ],
      data.invoices.map((invoice) =>
        row([
          invoice.origin === 'LEGACY_BALANCE'
            ? `${invoice.number}\n(créance d’ouverture, carnet papier)`
            : invoice.number,
          formatDateFr(invoice.issuedAt, timeZone),
          formatNumberFr(invoice.totalGross, 'total_gross'),
          formatNumberFr(invoice.credited, 'credited'),
          formatNumberFr(invoice.paidNet, 'paid_net'),
          formatNumberFr(invoice.refunded, 'refunded'),
          formatNumberFr(Math.max(0, invoice.balance), 'owing'),
          formatNumberFr(Math.max(0, -invoice.balance), 'credit'),
        ]),
      ),
      { keepLastRows: 1, sizePt: 8.5 },
    ),
    paragraph('Montants en FCFA.', { muted: true, sizePt: 8 }),
  ]

  if (hasLegacy) {
    body.push(
      paragraph(
        'Une créance d’ouverture est reprise d’un carnet papier : elle est distincte des ventes de la période et son encaissement ancien n’est pas un paiement reçu aujourd’hui.',
        { muted: true, sizePt: 8.5 },
      ),
    )
  }

  if (data.payments.length > 0) {
    body.push(
      heading('Encaissements (reçus)', 2),
      table(
        'Encaissements enregistrés',
        [
          column('receipt', 'Reçu n°', { fr: 1 }, 'left'),
          column('date', 'Date et heure', { mm: 36 }, 'left'),
          column('invoice', 'Facture', { mm: 38 }, 'left'),
          column('method', 'Mode', { mm: 34 }, 'left'),
          column('amount', 'Montant (FCFA)', { mm: 28 }, 'right'),
        ],
        data.payments.map((payment) =>
          row([
            payment.receiptNumber,
            formatDateTimeFr(payment.effectiveAt, timeZone),
            payment.invoiceNumber,
            paymentMethodLabel(payment.method),
            formatNumberFr(payment.amount, 'amount'),
          ]),
        ),
        { sizePt: 8.5 },
      ),
    )
  }

  if (data.creditNotes.length > 0) {
    body.push(
      heading('Avoirs', 2),
      table(
        'Avoirs émis',
        [
          column('number', 'Avoir n°', { fr: 1 }, 'left'),
          column('date', 'Date', { mm: 30 }, 'left'),
          column('invoice', 'Facture', { mm: 40 }, 'left'),
          column('amount', 'Montant (FCFA)', { mm: 32 }, 'right'),
        ],
        data.creditNotes.map((note) =>
          row([
            note.number,
            formatDateFr(note.issuedAt, timeZone),
            note.invoiceNumber,
            formatNumberFr(note.amountGross, 'amount'),
          ]),
        ),
        { sizePt: 8.5 },
      ),
    )
  }

  if (data.refunds.length > 0) {
    body.push(
      heading('Remboursements', 2),
      table(
        'Remboursements effectués',
        [
          column('number', 'Remboursement n°', { fr: 1 }, 'left'),
          column('date', 'Date et heure', { mm: 36 }, 'left'),
          column('invoice', 'Facture', { mm: 38 }, 'left'),
          column('method', 'Mode', { mm: 32 }, 'left'),
          column('amount', 'Montant (FCFA)', { mm: 28 }, 'right'),
        ],
        data.refunds.map((refund) =>
          row([
            refund.number,
            formatDateTimeFr(refund.effectiveAt, timeZone),
            refund.invoiceNumber,
            paymentMethodLabel(refund.method),
            formatNumberFr(refund.amount, 'amount'),
          ]),
        ),
        { sizePt: 8.5 },
      ),
    )
  }

  body.push(
    heading('Totaux', 2),
    totals([
      totalRow('Total facturé', formatXaf(sumMoney(data.invoices.map((i) => i.totalGross)))),
      totalRow('Avoirs émis', formatXaf(sumMoney(data.invoices.map((i) => i.credited)))),
      totalRow('Encaissé net', formatXaf(sumMoney(data.invoices.map((i) => i.paidNet)))),
      totalRow('Remboursé', formatXaf(sumMoney(data.invoices.map((i) => i.refunded)))),
      totalRow('Reste à payer', formatXaf(sumMoney(owing)), true),
      totalRow('Crédit à rembourser', formatXaf(sumMoney(credit)), true),
    ]),
  )

  return assembleModel({
    kind: 'ACCOUNT_STATEMENT',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'financial',
    heading: 'ÉTAT DE COMPTE',
    subtitle: `Arrêté au ${formatDateTimeFr(data.asOf, timeZone)}`,
    number: null,
    documentAt: data.asOf,
    draft: false,
    headerFields,
    pdfTitle: `État de compte - ${formatDateFr(data.asOf, timeZone)}`,
    body,
  })
}
