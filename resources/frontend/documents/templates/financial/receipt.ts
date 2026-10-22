import type { ReceiptDocumentData } from '../../data/financial'
import { paymentMethodLabel } from '../../data/labels'
import { invalid } from '../../engine/errors'
import { formatDateFr, formatDateTimeFr, formatXaf } from '../../engine/format'
import {
  type DocumentModel,
  type FieldRow,
  fields,
  notice,
  paragraph,
  totalRow,
  totals,
} from '../../engine/model'
import {
  assembleModel,
  type BuildOptions,
  requireInstant,
  requireMoney,
  requirePositiveMoney,
  requireText,
  resolveOptions,
} from '../common'
import { patientRows } from './shared'

/** Nature imprimée, DÉRIVÉE du calcul du serveur : jamais saisie librement (fichier 11). */
export const RECEIPT_KIND_LABELS = {
  DEPOSIT: 'Acompte',
  BALANCE_SETTLEMENT: 'Règlement du solde',
} as const

/**
 * Reçu de paiement (fichier 11) : numéro unique, date et heure effectives du versement, date
 * d'émission si elle diffère, référence de la facture, patient, montant du versement, mode et
 * référence non sensible, total facturé, cumul encaissé net après ce versement, reste après ce
 * paiement, utilisateur. Chaque encaissement a son reçu ; un reçu n'est jamais modifié ni renuméroté
 * (une erreur se corrige par pièces liées). Le modèle refuse une pièce incohérente : cumul qui ne suit
 * pas le versement, nature qui contredit le reste.
 */
export function buildReceipt(data: ReceiptDocumentData, options?: BuildOptions): DocumentModel {
  const resolved = resolveOptions(options)
  requireText(data.receiptNumber, 'receipt_number')
  requireText(data.invoice.number, 'invoice_number')
  requireInstant(data.issuedAt, 'issued_at')
  requireInstant(data.effectiveAt, 'effective_at')
  requirePositiveMoney(data.payment.amount, 'payment_amount')
  requireMoney(data.invoice.totalGross, 'total_gross')
  requireMoney(data.paidNetBefore, 'paid_net_before')
  requireMoney(data.paidNetAfter, 'paid_net_after')
  if (data.paidNetAfter - data.paidNetBefore !== data.payment.amount) {
    throw invalid(
      'receipt.cumulative_mismatch',
      'paid_net_after',
      'Le cumul encaissé après ce versement ne correspond pas au versement.',
    )
  }
  const expectedKind = data.balanceAfter === 0 ? 'BALANCE_SETTLEMENT' : 'DEPOSIT'
  if (data.kind !== expectedKind) {
    throw invalid(
      'receipt.kind_mismatch',
      'kind',
      'La nature du reçu (acompte ou règlement du solde) contredit le reste après versement.',
    )
  }
  if (data.balanceAfter < 0) {
    throw invalid(
      'receipt.negative_balance',
      'balance_after',
      'Un versement ne dépasse jamais le solde.',
    )
  }

  const timeZone = data.clinic.timezone
  const kindLabel = RECEIPT_KIND_LABELS[data.kind]
  const sameMoment = data.issuedAt === data.effectiveAt

  const headerFields: FieldRow[] = [
    { label: 'Numéro', value: data.receiptNumber },
    sameMoment
      ? { label: 'Date', value: formatDateTimeFr(data.effectiveAt, timeZone) }
      : { label: 'Versement du', value: formatDateTimeFr(data.effectiveAt, timeZone) },
  ]
  if (!sameMoment) {
    headerFields.push({ label: 'Émis le', value: formatDateTimeFr(data.issuedAt, timeZone) })
  }

  const paymentRows: FieldRow[] = [
    { label: 'Nature', value: kindLabel },
    { label: 'Mode de règlement', value: paymentMethodLabel(data.payment.method) },
  ]
  if (data.payment.reference !== null && data.payment.reference.trim() !== '') {
    paymentRows.push({ label: 'Référence', value: data.payment.reference })
  }

  return assembleModel({
    kind: 'RECEIPT',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'financial',
    heading: 'REÇU DE PAIEMENT',
    subtitle: kindLabel,
    number: data.receiptNumber,
    documentAt: data.issuedAt,
    draft: false,
    headerFields,
    pdfTitle: `Reçu ${data.receiptNumber}`,
    body: [
      fields(patientRows(data.patient, 'Reçu de'), 1),
      fields(
        [
          { label: 'Facture n°', value: data.invoice.number },
          { label: 'Émise le', value: formatDateFr(data.invoice.issuedAt, timeZone) },
        ],
        1,
      ),
      totals([totalRow('Montant du versement', formatXaf(data.payment.amount, 'amount'), true)]),
      fields(paymentRows, 1),
      totals([
        totalRow('Total facturé', formatXaf(data.invoice.totalGross, 'total_gross')),
        totalRow('Cumul encaissé net avant ce versement', formatXaf(data.paidNetBefore, 'before')),
        totalRow('Cumul encaissé net après ce versement', formatXaf(data.paidNetAfter, 'after')),
        totalRow('Reste après ce versement', formatXaf(data.balanceAfter, 'balance_after'), true),
      ]),
      fields([{ label: 'Encaissé par', value: data.cashierName }], 1),
      notice(
        'info',
        'Ce reçu atteste l’encaissement du versement indiqué ci-dessus. Il ne remplace pas la facture. Un reçu émis n’est jamais modifié : toute correction passe par des pièces liées.',
      ),
      paragraph(
        'Une réimpression restitue ce même reçu, avec le même numéro ; elle n’enregistre aucun nouvel encaissement.',
        { muted: true, sizePt: 8.5 },
      ),
    ],
  })
}
