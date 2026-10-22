import { paymentMethodLabel, unitLabel } from '@/documents/data/labels'
import { formatQuantity, formatRate } from '@/documents/engine/format'
import type { InvoicesStatus } from '@/domain/contracts/generated'
import { formatMoney } from '@/domain/values/money'

/**
 * Libellés et mises en forme des écrans de vente : montants XAF (`formatMoney`, espaces fines
 * insécables), quantités et taux exacts (mêmes fonctions que les PDF), libellés du référentiel. Aucun
 * état de règlement n'est déduit ici : « Total encaissé » et « Reste à encaisser » viennent de la
 * projection du serveur.
 */
export { formatMoney, paymentMethodLabel, unitLabel }

export function quantityLabel(canonical: string): string {
  return formatQuantity(canonical)
}

export function rateLabel(canonical: string | null): string {
  return canonical === null ? 'Aucun taux' : formatRate(canonical)
}

const STATUS_LABELS: Readonly<Record<InvoicesStatus, string>> = {
  DRAFT: 'Brouillon',
  ISSUED: 'Émise',
  ABANDONED: 'Abandonnée',
}

const STATUS_SYMBOLS: Readonly<Record<InvoicesStatus, string>> = {
  DRAFT: '✎',
  ISSUED: '✓',
  ABANDONED: '–',
}

export function invoiceStatusLabel(status: InvoicesStatus): string {
  return STATUS_LABELS[status]
}

export function invoiceStatusSymbol(status: InvoicesStatus): string {
  return STATUS_SYMBOLS[status]
}

/** Identifiant de facture dans un titre tant qu'aucun numéro n'existe (brouillon). */
export function invoiceTitle(number: string | null): string {
  return number === null ? 'Facture en brouillon (sans numéro)' : `Facture ${number}`
}
