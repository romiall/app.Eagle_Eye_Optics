import type { InvoiceLineData, InvoiceTaxData, PatientIdentity } from '../../data/financial'
import { formatNumberFr, formatPersonName, formatQuantity, formatRate, MISSING, sumMoney } from '../../engine/format'
import { NBSP } from '../../engine/charset'
import {
  type FieldRow,
  row,
  type TableColumn,
  type TableRow,
  totalRow,
  type TotalRow,
} from '../../engine/model'
import { PAGE_FORMATS, type PageFormatId } from '../../engine/units'
import { unitLabel } from '../../data/labels'

/**
 * Éléments partagés des pièces financières (D01) : identité facturée minimale (fichier 11 : jamais
 * d'antécédents, de symptômes ni de réfraction sur une pièce financière), tableau de lignes,
 * ventilation de taxe et mention du régime fiscal.
 */
export function contentWidthOf(format: PageFormatId): number {
  const page = PAGE_FORMATS[format]
  return page.widthMm - page.marginLeftMm - page.marginRightMm
}

export function patientRows(patient: PatientIdentity, label = 'Patient'): FieldRow[] {
  return [
    { label, value: formatPersonName(patient.lastName, patient.firstNames) },
    { label: 'Dossier n°', value: patient.recordNumber },
  ]
}

/** Largeur au-delà de laquelle le tableau de lignes détaille prix et remise en colonnes séparées. */
const WIDE_TABLE_MM = 150

export function isWideTable(format: PageFormatId): boolean {
  return contentWidthOf(format) >= WIDE_TABLE_MM
}

export function invoiceLineColumns(format: PageFormatId, withTax: boolean): TableColumn[] {
  if (!isWideTable(format)) {
    return [
      { key: 'description', header: 'Désignation', width: { fr: 1 }, align: 'left' },
      { key: 'quantity', header: 'Qté', width: { mm: 14 }, align: 'right' },
      { key: 'total', header: 'Total (FCFA)', width: { mm: 24 }, align: 'right' },
    ]
  }
  const columns: TableColumn[] = [
    { key: 'reference', header: 'Réf.', width: { mm: 20 }, align: 'left' },
    { key: 'description', header: 'Désignation', width: { fr: 1 }, align: 'left' },
    { key: 'quantity', header: 'Quantité', width: { mm: 24 }, align: 'right' },
    { key: 'unitPrice', header: 'Prix unitaire (FCFA)', width: { mm: 26 }, align: 'right' },
    { key: 'discount', header: 'Remise (FCFA)', width: { mm: 21 }, align: 'right' },
  ]
  if (withTax) {
    columns.push({ key: 'tax', header: 'Dont taxe (FCFA)', width: { mm: 22 }, align: 'right' })
  }
  columns.push({ key: 'total', header: 'Total (FCFA)', width: { mm: 27 }, align: 'right' })
  return columns
}

function quantityText(line: InvoiceLineData): string {
  return `${formatQuantity(line.quantity, 'quantity')}${NBSP}${unitLabel(line.unitCode)}`
}

export function invoiceLineRows(
  lines: readonly InvoiceLineData[],
  format: PageFormatId,
  withTax: boolean,
): TableRow[] {
  if (!isWideTable(format)) {
    return lines.map((line) => {
      const detail = `${formatNumberFr(line.unitPriceGross, 'unit_price')} \u{00D7} ${formatQuantity(line.quantity, 'quantity')}`
      const discount =
        line.discountGross > 0 ? `, remise ${formatNumberFr(line.discountGross, 'discount')}` : ''
      const reference = line.reference === null ? '' : `${line.reference} \u{2013} `
      return row([
        `${reference}${line.description}\n${detail}${discount}`,
        formatQuantity(line.quantity, 'quantity'),
        formatNumberFr(line.lineTotalGross, 'line_total'),
      ])
    })
  }
  return lines.map((line) => {
    const cells = [
      line.reference ?? MISSING,
      line.description,
      quantityText(line),
      formatNumberFr(line.unitPriceGross, 'unit_price'),
      formatNumberFr(line.discountGross, 'discount'),
    ]
    if (withTax) {
      cells.push(line.taxAmount === null ? MISSING : formatNumberFr(line.taxAmount, 'tax_amount'))
    }
    cells.push(formatNumberFr(line.lineTotalGross, 'line_total'))
    return row(cells)
  })
}

/** Taxes détaillées seulement si un régime a été VALIDÉ ; sinon aucune taxe n'est inventée. */
export function hasValidatedTax(tax: InvoiceTaxData): boolean {
  return tax.regime === 'VALIDATED_RULES' && tax.rules.length > 0
}

/** Ventilation par règle de taxe : la somme exacte des montants de taxe des lignes concernées. */
export function taxTotalRows(lines: readonly InvoiceLineData[], tax: InvoiceTaxData): TotalRow[] {
  if (!hasValidatedTax(tax)) return []
  const rows: TotalRow[] = []
  for (const rule of tax.rules) {
    const concerned = lines.filter((line) => line.taxCode === rule.code)
    if (concerned.length === 0 || rule.ratePercent === null) continue
    if (concerned.some((line) => line.taxAmount === null)) continue
    const amount = sumMoney(
      concerned.map((line) => line.taxAmount ?? 0),
      'tax_amount',
    )
    rows.push(
      totalRow(
        `dont ${rule.label} (${formatRate(rule.ratePercent, 'rate')})`,
        `${formatNumberFr(amount, 'tax_amount')}${NBSP}FCFA`,
      ),
    )
  }
  return rows
}

/** Mention du régime fiscal imprimée sous les totaux. */
export function taxNote(tax: InvoiceTaxData): string {
  if (!hasValidatedTax(tax)) {
    return 'Régime de taxe non configuré : aucune taxe n’est détaillée sur ce document.'
  }
  const notes = tax.rules
    .filter((rule) => rule.legalNote !== null && rule.legalNote.trim() !== '')
    .map((rule) => `${rule.label} : ${rule.legalNote}`)
  return ['Montants en FCFA, taxes comprises.', ...notes].join('\n')
}
