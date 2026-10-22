import { computeLineAmounts } from '@/domain/billing/lineAmounts'
import { FixedDecimal } from '@/domain/values/decimal'

import type { InvoiceDocumentData } from '../../data/financial'
import { invalid } from '../../engine/errors'
import {
  formatCivilDateFr,
  formatDateFr,
  formatDateTimeFr,
  formatXaf,
  sumMoney,
} from '../../engine/format'
import {
  type Block,
  type DocumentModel,
  type FieldRow,
  fields,
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
  requireText,
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

/** Cohérence d'une ligne avec la règle commune de calcul (fichier 03) : écart signalé, jamais caché. */
function lineMismatchWarnings(data: InvoiceDocumentData): string[] {
  const warnings: string[] = []
  for (const line of data.lines) {
    try {
      const computed = computeLineAmounts(
        FixedDecimal.fromString(line.quantity, 3),
        line.unitPriceGross,
        line.discountGross,
        null,
        false,
      )
      if (computed.net !== line.lineTotalGross) {
        warnings.push(
          `Ligne n° ${line.position} : le total imprimé diffère du calcul quantité × prix − remise.`,
        )
      }
    } catch {
      warnings.push(`Ligne n° ${line.position} : montants invalides pour le calcul de contrôle.`)
    }
  }
  return warnings
}

/**
 * Facture (fichier 11) : en-tête du cabinet, numéro et date, identité facturée minimale, référence du
 * dossier, lignes (référence, désignation, quantité, unité, prix, remise, taxe selon la configuration),
 * total en FCFA, conditions et échéance si définies, auteur. JAMAIS d'antécédents, de symptômes ni de
 * réfraction. Un brouillon porte le filigrane BROUILLON et n'a pas de numéro définitif. Une facture
 * émise est figée : son PDF ne dit pas qu'elle est réglée, l'état de paiement postérieur figure sur les
 * reçus et sur l'état de compte daté.
 */
export function buildInvoice(data: InvoiceDocumentData, options?: BuildOptions): DocumentModel {
  const resolved = resolveOptions(options)
  const draft = data.status === 'DRAFT'
  if (draft && data.number !== null) {
    throw invalid(
      'invoice.draft_with_number',
      'number',
      'Un brouillon n’a pas de numéro définitif.',
    )
  }
  if (!draft) {
    requireText(data.number, 'number')
    requireInstant(data.issuedAt, 'issued_at')
    if (data.lines.length === 0) {
      throw invalid('invoice.no_lines', 'lines', 'Une facture émise comporte au moins une ligne.')
    }
  }
  requireMoney(data.totalGross, 'total_gross')
  const lineSum = sumMoney(
    data.lines.map((line) => line.lineTotalGross),
    'line_total',
  )
  if (lineSum !== data.totalGross) {
    throw invalid(
      'invoice.total_mismatch',
      'total_gross',
      'Le total de la facture diffère de la somme de ses lignes.',
    )
  }
  const timeZone = data.clinic.timezone
  const withTax = hasValidatedTax(data.tax)

  const headerFields: FieldRow[] = [
    { label: 'Numéro', value: data.number ?? 'Non attribué (brouillon)' },
    draft
      ? {
          label: 'Date du brouillon',
          value: formatDateFr(requireInstant(resolved.now, 'now'), timeZone),
        }
      : {
          label: 'Date d’émission',
          value: formatDateFr(requireInstant(data.issuedAt, 'issued_at'), timeZone),
        },
  ]
  if (data.dueDate !== null) {
    headerFields.push({ label: 'Échéance', value: formatCivilDateFr(data.dueDate, 'due_date') })
  }

  const body: Block[] = [
    fields([...patientRows(data.patient, 'Facturé à')], 1),
    table(
      'Lignes de la facture',
      invoiceLineColumns(resolved.format, withTax),
      invoiceLineRows(data.lines, resolved.format, withTax),
      { keepLastRows: 1 },
    ),
    totals([
      ...taxTotalRows(data.lines, data.tax),
      totalRow('Total', formatXaf(data.totalGross, 'total_gross'), true),
    ]),
  ]

  if (data.paymentAtIssue !== null) {
    const { paidNet, balance, asOf, receiptNumbers } = data.paymentAtIssue
    const rows: FieldRow[] =
      paidNet > 0
        ? [
            {
              label: 'Versé à l’émission',
              value: `${formatXaf(paidNet, 'paid_net')} (reçus : ${receiptNumbers.join(', ')})`,
            },
            { label: 'Reste à régler à l’émission', value: formatXaf(balance, 'balance') },
          ]
        : [{ label: 'Versements à l’émission', value: 'Aucun versement enregistré' }]
    if (paidNet > 0 && receiptNumbers.length === 0) {
      throw invalid(
        'invoice.payment_without_receipt',
        'payment_at_issue',
        'Un versement mentionné sur la facture doit citer ses reçus.',
      )
    }
    body.push(
      fields(rows, 1),
      paragraph(
        `Situation à l’émission, au ${formatDateTimeFr(asOf, timeZone)}. Les règlements ultérieurs figurent sur leurs reçus et sur l’état de compte daté.`,
        { muted: true, sizePt: 8.5 },
      ),
    )
  }

  if (data.clinic.settings.paymentTerms !== null) {
    body.push(fields([{ label: 'Conditions', value: data.clinic.settings.paymentTerms }], 1))
  }
  body.push(paragraph(taxNote(data.tax), { muted: true, sizePt: 8.5 }))
  if (data.issuedByName !== null) {
    body.push(fields([{ label: 'Établie par', value: data.issuedByName }], 1))
  }

  return assembleModel({
    kind: 'INVOICE',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'financial',
    heading: draft ? 'FACTURE (brouillon)' : 'FACTURE',
    subtitle: null,
    number: data.number,
    documentAt: data.issuedAt,
    draft,
    headerFields,
    pdfTitle: draft ? 'Facture - brouillon' : `Facture ${data.number ?? ''}`.trim(),
    body,
    warnings: lineMismatchWarnings(data),
  })
}
