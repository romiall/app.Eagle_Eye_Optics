import { CLINICAL_TERMS, type TermGroup } from '@/domain/clinical/terms'
import type { PaymentsMethod, RefundsMethod } from '@/domain/contracts/generated'

/**
 * Libellés français imprimés pour les codes de référentiel (D01, D02). Les libellés d'unités, d'usage,
 * de type de verre, d'indice et de traitement sont ceux de `database/02_reference_data.sql`
 * (`tests/frontend/documents/reference-labels.test.ts` relit le SQL et exige l'égalité). Les libellés
 * de modes de règlement ne figurent pas dans le référentiel : ce sont des PROPOSITIONS d'affichage
 * (à valider avec la gérante), jamais un identifiant de moyen de paiement.
 */
export const PAYMENT_METHOD_LABELS: Readonly<Record<PaymentsMethod | RefundsMethod, string>> = {
  CASH: 'Espèces',
  MTN_MOMO: 'Mobile money MTN',
  ORANGE_MONEY: 'Orange Money',
  BANK_TRANSFER: 'Virement',
  CHEQUE: 'Chèque',
  OTHER: 'Autre mode',
}

export function paymentMethodLabel(method: string): string {
  return Object.prototype.hasOwnProperty.call(PAYMENT_METHOD_LABELS, method)
    ? PAYMENT_METHOD_LABELS[method as PaymentsMethod]
    : method
}

/** `units` du référentiel. */
export const UNIT_LABELS: Readonly<Record<string, string>> = {
  UNIT: 'Unité',
  PAIR: 'Paire',
  SERVICE: 'Prestation',
  OTHER: 'Autre unité configurée',
}

/** Unité d'une ligne : le libellé du référentiel, ou le code tel quel s'il est inconnu (jamais deviné). */
export function unitLabel(code: string): string {
  return UNIT_LABELS[code] ?? code
}

/**
 * Libellés d'un groupe de `clinical_terms`, tirés de LA copie du référentiel du poste
 * (`domain/clinical/terms`, déjà vérifiée contre le SQL) : aucune seconde liste à maintenir.
 */
function termLabels(group: TermGroup): Readonly<Record<string, string>> {
  return Object.fromEntries(CLINICAL_TERMS[group].map((term) => [term.code, term.label]))
}

/** `clinical_terms` : groupe OPTICAL_USAGE. */
export const OPTICAL_USAGE_LABELS: Readonly<Record<string, string>> = termLabels('OPTICAL_USAGE')

/** `clinical_terms` : groupe LENS_TYPE. */
export const LENS_TYPE_LABELS: Readonly<Record<string, string>> = termLabels('LENS_TYPE')

/** `clinical_terms` : groupe LENS_TREATMENT. */
export const LENS_TREATMENT_LABELS: Readonly<Record<string, string>> = termLabels('LENS_TREATMENT')

/** `clinical_terms` : groupe LENS_INDEX (codes et libellés identiques). */
export const LENS_INDEX_LABELS: Readonly<Record<string, string>> = {
  '1.50': '1.50',
  '1.56': '1.56',
  '1.59': '1.59',
  '1.60': '1.60',
  '1.61': '1.61',
  '1.67': '1.67',
  '1.74': '1.74',
  OTHER: 'Autre',
}
