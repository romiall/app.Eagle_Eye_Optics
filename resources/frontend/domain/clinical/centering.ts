import { CONTROL_IDS } from './controls'
import {
  cleanText,
  measureData,
  measureError,
  measureText,
  measureUnits,
  measureUnparsable,
  type NewId,
  type StepCodec,
  type StepError,
  textError,
} from './fields'
import { FixedDecimal } from '../values/decimal'

/**
 * Rubrique CENTERING (C02 ; fichier 02 ; DP §6) : écart pupillaire OBJECTIF mesuré (en millimètres), total et/ou
 * monoculaire OD / OG. Une seule ligne par consultation ; sans valeur, pas de ligne (`centering_id` null, G6) ; dès
 * qu'une valeur est saisie, l'identifiant de ligne est créé sur le poste.
 *
 * - Chaque écart est strictement positif, à deux décimales, jamais arrondi.
 * - Le système NE FABRIQUE PAS une DP monoculaire à partir du total ni l'inverse : `suggestedSum` propose la somme
 *   OD + OG, que la personne reporte elle-même si elle le veut.
 * - Si le total ET les deux écarts monoculaires sont saisis et que OD + OG diffère du total au-delà de la tolérance
 *   (`TOLERANCE_UNITS`, hypothèse : aucune, à valider Q06), une justification est obligatoire, comme au serveur.
 *
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
export const TOLERANCE_UNITS = 0n

export interface CenteringData {
  centering_id: string | null
  pd_total: string | null
  pd_od: string | null
  pd_og: string | null
  inconsistency_reason: string | null
}

export interface CenteringDraft {
  /** Identifiant de la ligne : celui de la ligne enregistrée, ou un identifiant créé sur le poste. */
  id: string
  pdTotal: string
  pdOd: string
  pdOg: string
  reason: string
}

export function blankCentering(newId: NewId): CenteringDraft {
  return { id: newId(), pdTotal: '', pdOd: '', pdOg: '', reason: '' }
}

export function centeringDraft(data: CenteringData, newId: NewId): CenteringDraft {
  return {
    id: data.centering_id ?? newId(),
    pdTotal: measureText(data.pd_total),
    pdOd: measureText(data.pd_od),
    pdOg: measureText(data.pd_og),
    reason: data.inconsistency_reason ?? '',
  }
}

function hasAnyInput(draft: CenteringDraft): boolean {
  return (
    cleanText(draft.pdTotal) !== null ||
    cleanText(draft.pdOd) !== null ||
    cleanText(draft.pdOg) !== null ||
    cleanText(draft.reason) !== null
  )
}

export function centeringData(draft: CenteringDraft): CenteringData {
  return {
    centering_id: hasAnyInput(draft) ? draft.id : null,
    pd_total: measureData(draft.pdTotal),
    pd_od: measureData(draft.pdOd),
    pd_og: measureData(draft.pdOg),
    inconsistency_reason: cleanText(draft.reason),
  }
}

/** Somme OD + OG en forme canonique, ou null si l'un des deux écarts manque ou est invalide. Jamais écrite d'office. */
export function suggestedSum(draft: Pick<CenteringDraft, 'pdOd' | 'pdOg'>): string | null {
  const od = measureUnits(draft.pdOd)
  const og = measureUnits(draft.pdOg)
  if (od === null || og === null || od <= 0n || og <= 0n) return null
  return FixedDecimal.fromUnits(od + og, 2).canonical()
}

/** Vrai si total, OD et OG sont saisis (textes) et que OD + OG diffère du total au-delà de la tolérance. */
export function pdTextsMismatch(totalText: string, odText: string, ogText: string): boolean {
  const total = measureUnits(totalText)
  const od = measureUnits(odText)
  const og = measureUnits(ogText)
  if (total === null || od === null || og === null) return false
  const gap = od + og - total
  return (gap < 0n ? -gap : gap) > TOLERANCE_UNITS
}

export function pdMismatch(draft: CenteringDraft): boolean {
  return pdTextsMismatch(draft.pdTotal, draft.pdOd, draft.pdOg)
}

export function centeringErrors(draft: CenteringDraft): StepError[] {
  const errors: StepError[] = []
  const fields = [
    ['pd_total', 'Écart pupillaire total', draft.pdTotal, CONTROL_IDS.pdTotal],
    ['pd_od', 'Écart pupillaire OD', draft.pdOd, CONTROL_IDS.pdOd],
    ['pd_og', 'Écart pupillaire OG', draft.pdOg, CONTROL_IDS.pdOg],
  ] as const
  for (const [field, label, text, controlId] of fields) {
    const message = measureError(text)
    if (message !== null) {
      errors.push({ path: field, controlId, message: `${label} : ${message}` })
      continue
    }
    const units = measureUnits(text)
    if (units !== null && units <= 0n) {
      errors.push({
        path: field,
        controlId,
        message: `${label} : doit être strictement positif (en millimètres).`,
      })
    }
  }
  const reason = textError(draft.reason, 'La justification')
  if (reason !== null) {
    errors.push({ path: 'inconsistency_reason', controlId: CONTROL_IDS.pdReason, message: reason })
  }
  const anyValue =
    cleanText(draft.pdTotal) !== null ||
    cleanText(draft.pdOd) !== null ||
    cleanText(draft.pdOg) !== null
  if (errors.length === 0 && pdMismatch(draft) && cleanText(draft.reason) === null) {
    errors.push({
      path: 'inconsistency_reason',
      controlId: CONTROL_IDS.pdReason,
      message:
        'La somme des écarts OD et OG diffère de l’écart total : corrigez une valeur ou justifiez l’écart.',
    })
  }
  if (errors.length === 0 && !anyValue && cleanText(draft.reason) !== null) {
    errors.push({
      path: 'inconsistency_reason',
      controlId: CONTROL_IDS.pdReason,
      message:
        'Une justification n’a de sens qu’avec un écart pupillaire saisi : effacez-la ou saisissez la mesure.',
    })
  }
  return errors
}

export const centeringCodec: StepCodec<CenteringDraft, CenteringData> = {
  blank: blankCentering,
  fromData: centeringDraft,
  toData: centeringData,
  errors: centeringErrors,
  unparsable: (draft) =>
    measureUnparsable(draft.pdTotal) ||
    measureUnparsable(draft.pdOd) ||
    measureUnparsable(draft.pdOg),
}
