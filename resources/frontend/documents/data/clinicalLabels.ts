import { CORRECTION_TITLES } from '@/domain/clinical/acuity'
import {
  DURATION_UNIT_LABELS,
  HISTORY_GROUP_LABELS,
  type HistoryGroup,
  MEDICATION_FORM_LABELS,
  STAGE_LABELS,
  urgencyLabel,
} from '@/domain/clinical/labels'
import { OTHER_CODE, type TermGroup, termLabel } from '@/domain/clinical/terms'

import { LENS_INDEX_LABELS } from './labels'

/**
 * Libellés français des codes cliniques imprimés (D02). Un adaptateur (`clinicalAdapters.ts`) ne connaît
 * aucun libellé : il appelle ces résolveurs. UNE SEULE source par libellé : les termes du référentiel
 * (`domain/clinical/terms`, copie vérifiée de `clinical_terms`), les stades de réfraction, formes de
 * médicament, unités de durée et niveaux d'urgence de `domain/clinical/labels`, les corrections d'acuité
 * de `domain/clinical/acuity` : l'écran clinique et le document disent la même chose. Un code inconnu est
 * imprimé TEL QUEL, jamais deviné ni traduit au hasard.
 *
 * Les échelles d'acuité (`visual_acuity_scales`) sont propres au cabinet : `scale` rend null par défaut
 * (le document imprime « échelle non précisée ») ; `clinicalLabelsWithScales` nomme les échelles lues
 * sur le serveur (`GET /visual-acuity-scales`), sans aucune conversion entre elles.
 */
export interface ClinicalLabels {
  complaint(code: string, otherText: string | null): string
  history(group: HistoryGroup, code: string, otherText: string | null): string
  historyGroup(group: HistoryGroup): string
  referralReason(code: string, otherText: string | null): string
  urgency(code: string): string
  context(code: string): string
  distance(code: string): string
  correction(code: string): string
  stage(code: string): string
  ametropia(code: string, otherText: string | null): string
  scale(scaleId: string): string | null
  usage(code: string, otherText: string | null): string
  lensType(code: string, otherText: string | null): string
  lensIndex(code: string, otherText: string | null): string
  treatment(code: string, otherText: string | null): string
  /** Forme d'un médicament ; « Autre » imprime la forme précisée par le professionnel. */
  medicationForm(code: string, otherText: string | null): string
  durationUnit(code: string): string
}

/** Contextes et distances d'acuité tels que le document les nomme (aucun équivalent à l'écran). */
const CONTEXT_LABELS: Readonly<Record<string, string>> = {
  PRELIMINARY: 'Préliminaire',
  POST_REFRACTION: 'Après réfraction',
}

const DISTANCE_LABELS: Readonly<Record<string, string>> = {
  FAR: 'De loin',
  NEAR: 'De près',
  UNSPECIFIED: 'Non précisée',
}

function lookup(labels: Readonly<Record<string, string>>, code: string): string {
  return Object.prototype.hasOwnProperty.call(labels, code) ? (labels[code] ?? code) : code
}

function precision(otherText: string | null): string {
  return otherText?.trim() ?? ''
}

/** Terme du référentiel ; « Autre » avec sa précision saisie (sans précision : le libellé du référentiel). */
function term(group: TermGroup, code: string, otherText: string | null): string {
  const other = precision(otherText)
  if (code === OTHER_CODE && other !== '') return `Autre : ${other}`
  return termLabel(group, code)
}

function lensIndex(code: string, otherText: string | null): string {
  const other = precision(otherText)
  if (code === OTHER_CODE && other !== '') return `Autre : ${other}`
  return lookup(LENS_INDEX_LABELS, code)
}

function medicationForm(code: string, otherText: string | null): string {
  const other = precision(otherText)
  if (code === OTHER_CODE && other !== '') return other
  return lookup(MEDICATION_FORM_LABELS, code)
}

export function defaultClinicalLabels(): ClinicalLabels {
  return {
    complaint: (code, otherText) => term('COMPLAINT', code, otherText),
    history: (group, code, otherText) => term(group, code, otherText),
    historyGroup: (group) => HISTORY_GROUP_LABELS[group],
    referralReason: (code, otherText) => term('REFERRAL_REASON', code, otherText),
    urgency: (code) => urgencyLabel(code),
    context: (code) => lookup(CONTEXT_LABELS, code),
    distance: (code) => lookup(DISTANCE_LABELS, code),
    correction: (code) => lookup(CORRECTION_TITLES, code),
    stage: (code) => lookup(STAGE_LABELS, code),
    ametropia: (code, otherText) => term('AMETROPIA', code, otherText),
    scale: () => null,
    usage: (code, otherText) => term('OPTICAL_USAGE', code, otherText),
    lensType: (code, otherText) => term('LENS_TYPE', code, otherText),
    lensIndex,
    treatment: (code, otherText) => term('LENS_TREATMENT', code, otherText),
    medicationForm,
    durationUnit: (code) => lookup(DURATION_UNIT_LABELS, code),
  }
}

/**
 * Libellés avec les échelles d'acuité du cabinet : une échelle inconnue (désactivée puis retirée de la
 * liste) reste « échelle non précisée », jamais une autre échelle.
 */
export function clinicalLabelsWithScales(
  scales: readonly { id: string; label: string }[],
  base: ClinicalLabels = defaultClinicalLabels(),
): ClinicalLabels {
  return {
    ...base,
    scale: (scaleId) => scales.find((scale) => scale.id === scaleId)?.label ?? null,
  }
}
