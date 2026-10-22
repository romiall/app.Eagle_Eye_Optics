import { ACUITY_SLOTS, acuitySlotKey, type AcuitySlot, CORRECTION_TITLES } from './acuity'
import { axisText, cleanText, measureText } from './fields'
import {
  DURATION_UNIT_LABELS,
  type DeliveredStep,
  DELIVERED_STEPS,
  EYE_SHORT_LABELS,
  HISTORY_GROUP_LABELS,
  HISTORY_GROUPS,
  MEDICATION_FORM_LABELS,
  STAGE_LABELS,
  urgencyLabel,
  YES_NO_LABELS,
} from './labels'
import { REFRACTION_EYES, REFRACTION_STAGES, measurementKey } from './refraction'
import type { StepDataByName } from './steps'
import { OTHER_CODE, termLabel, type TermGroup } from './terms'

/**
 * Comparaison de deux révisions d'une consultation (C03 ; fichier 02, « Historique » : « comparaison de deux
 * visites : mesures OD/OG, échelle, valeurs non mesurées et prescriptions ; jamais agréger des acuités d'échelles
 * incompatibles »).
 *
 * - Chaque rubrique est décrite en « faits » (un libellé et une valeur en clair) ; deux révisions se comparent fait
 *   par fait. Une valeur absente est dite « non renseigné » (ou « non mesuré » pour une mesure), jamais « aucun »
 *   ni zéro.
 * - Le changement est dit en TEXTE et par un symbole (« ≠ Modifié », « + Ajouté », « − Retiré », « = Identique ») :
 *   la couleur n'est jamais le seul signal.
 * - Une acuité est toujours rendue avec son échelle ; si les deux révisions n'ont pas la même échelle pour la même
 *   case, les valeurs sont déclarées NON COMPARABLES (aucune conversion, aucune différence chiffrée).
 *
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
export type StepsData = { [S in DeliveredStep]: StepDataByName[S] }

export type ChangeKind = 'same' | 'changed' | 'added' | 'removed'

export const CHANGE_LABELS: Readonly<Record<ChangeKind, string>> = {
  same: 'Identique',
  changed: 'Modifié',
  added: 'Ajouté',
  removed: 'Retiré',
}

export const CHANGE_SYMBOLS: Readonly<Record<ChangeKind, string>> = {
  same: '=',
  changed: '≠',
  added: '+',
  removed: '−',
}

export const NOT_FILLED = 'Non renseigné'
export const NOT_MEASURED = 'Non mesuré'

/** Un fait d'une rubrique : identifiant stable, libellé, valeur en clair, et échelle éventuelle (acuités). */
export interface Fact {
  step: DeliveredStep
  key: string
  label: string
  value: string
  /** Échelle d'une acuité : deux valeurs d'échelles différentes ne sont pas comparables. */
  scale?: string
}

export interface ComparisonRow {
  step: DeliveredStep
  key: string
  label: string
  left: string
  right: string
  kind: ChangeKind
  /** Précision : « échelles différentes : valeurs non comparables ». */
  note: string | null
}

export interface Comparison {
  rows: ComparisonRow[]
  /** Nombre de lignes non identiques. */
  differences: number
}

/** Libellé de l'échelle d'une acuité à partir de son identifiant ; inconnue : dite telle quelle. */
export type ScaleLabeller = (scaleId: string) => string

function yesNo(value: boolean | null): string {
  return value === null ? YES_NO_LABELS.unknown : value ? YES_NO_LABELS.yes : YES_NO_LABELS.no
}

function text(value: string | null): string {
  return cleanText(value ?? '') ?? NOT_FILLED
}

function terms(
  group: TermGroup,
  list: readonly { term_code: string; other_text: string | null }[],
): string {
  if (list.length === 0) return NOT_FILLED
  return list
    .map((term) =>
      term.term_code === OTHER_CODE && term.other_text !== null
        ? `${termLabel(group, term.term_code)} (${term.other_text})`
        : termLabel(group, term.term_code),
    )
    .join(', ')
}

function measure(value: string | null): string {
  return value === null ? NOT_MEASURED : measureText(value)
}

function slotTitle(slot: AcuitySlot): string {
  const distance =
    slot.context === 'POST_REFRACTION'
      ? 'Acuité après correction'
      : slot.distance === 'FAR'
        ? 'Acuité de loin'
        : 'Acuité de près'
  const correction =
    slot.context === 'POST_REFRACTION' ? '' : `, ${CORRECTION_TITLES[slot.correction].toLowerCase()}`
  return `${distance}${correction}, ${EYE_SHORT_LABELS[slot.eye]}`
}

function anamnesisFacts(data: StepDataByName['ANAMNESIS']): Fact[] {
  const step = 'ANAMNESIS'
  const facts: Fact[] = [
    { step, key: 'complaints', label: 'Motifs de la consultation', value: terms('COMPLAINT', data.complaints) },
    { step, key: 'symptoms', label: 'Plaintes et symptômes', value: text(data.symptoms) },
    { step, key: 'wears_glasses', label: 'Porte actuellement des lunettes', value: yesNo(data.wears_glasses) },
  ]
  if (data.wears_glasses === true) {
    facts.push(
      { step, key: 'glasses_duration', label: 'Depuis combien de temps', value: text(data.glasses_duration) },
      { step, key: 'previous_correction', label: 'Ancienne correction disponible', value: yesNo(data.previous_correction_available) },
    )
  }
  return facts
}

function historiesFacts(data: StepDataByName['HISTORIES']): Fact[] {
  return HISTORY_GROUPS.map((group) => {
    const history = data.histories.find((entry) => entry.history_group === group)
    let value = NOT_FILLED
    if (history !== undefined) {
      const parts: string[] = []
      if (history.known_none) parts.push('Aucun connu')
      if (history.terms.length > 0) parts.push(terms(group, history.terms))
      const comments = cleanText(history.comments ?? '')
      if (comments !== null) parts.push(`Commentaires : ${comments}`)
      value = parts.length > 0 ? parts.join(' ; ') : NOT_FILLED
    }
    return { step: 'HISTORIES' as const, key: group, label: HISTORY_GROUP_LABELS[group], value }
  })
}

function acuityFacts(data: StepDataByName['VISUAL_ACUITY'], scaleLabel: ScaleLabeller): Fact[] {
  return ACUITY_SLOTS.map((slot) => {
    const row = data.acuities.find(
      (entry) => acuitySlotKey(entry.context, entry.distance, entry.correction, entry.eye) === slot.key,
    )
    const fact: Fact = {
      step: 'VISUAL_ACUITY',
      key: slot.key,
      label: slotTitle(slot),
      value: row === undefined ? NOT_MEASURED : `${row.raw_value} (échelle : ${scaleLabel(row.scale_id)})`,
    }
    if (row !== undefined) fact.scale = row.scale_id
    return fact
  })
}

function refractionFacts(data: StepDataByName['REFRACTION']): Fact[] {
  const facts: Fact[] = []
  for (const stage of REFRACTION_STAGES) {
    for (const eye of REFRACTION_EYES) {
      const row = data.measurements.find((entry) => measurementKey(entry.stage, entry.eye) === measurementKey(stage, eye))
      let value = NOT_MEASURED
      if (row !== undefined) {
        const parts = [
          `Sphère ${measure(row.sphere)}`,
          `Cylindre ${measure(row.cylinder)}`,
          `Axe ${row.axis === null ? NOT_MEASURED.toLowerCase() : `${axisText(row.axis)}°`}`,
        ]
        if (stage === 'SUBJECTIVE') parts.push(`Addition ${measure(row.addition)}`)
        value = parts.join(' · ')
      }
      facts.push({
        step: 'REFRACTION',
        key: measurementKey(stage, eye),
        label: `${STAGE_LABELS[stage]}, ${EYE_SHORT_LABELS[eye]}`,
        value,
      })
    }
  }
  facts.push(
    { step: 'REFRACTION', key: 'ametropias', label: 'Type d’amétropie', value: terms('AMETROPIA', data.ametropias) },
    { step: 'REFRACTION', key: 'observations', label: 'Observations de réfraction', value: text(data.refraction_observations) },
  )
  return facts
}

function centeringFacts(data: StepDataByName['CENTERING']): Fact[] {
  const step = 'CENTERING'
  const mm = (value: string | null): string => (value === null ? NOT_MEASURED : `${measureText(value)} mm`)
  return [
    { step, key: 'pd_total', label: 'Écart pupillaire total', value: mm(data.pd_total) },
    { step, key: 'pd_od', label: 'Écart pupillaire OD', value: mm(data.pd_od) },
    { step, key: 'pd_og', label: 'Écart pupillaire OG', value: mm(data.pd_og) },
    { step, key: 'reason', label: 'Justification de l’écart', value: text(data.inconsistency_reason) },
  ]
}

function medicationFacts(data: StepDataByName['MEDICATIONS']): Fact[] {
  if (data.orders.length === 0) {
    return [{ step: 'MEDICATIONS', key: 'none', label: 'Médicaments', value: NOT_FILLED }]
  }
  return data.orders.map((order) => {
    const parts = [
      order.form === 'OTHER' && order.form_other !== null
        ? `Forme : ${order.form_other}`
        : `Forme : ${MEDICATION_FORM_LABELS[order.form]}`,
    ]
    if (order.strength !== null) parts.push(`Dosage : ${order.strength}`)
    if (order.dosage !== null) parts.push(`Posologie : ${order.dosage}`)
    if (order.frequency !== null) parts.push(`Fréquence : ${order.frequency}`)
    if (order.duration_text !== null) parts.push(`Durée : ${order.duration_text}`)
    if (order.duration_value !== null && order.duration_unit !== null) {
      parts.push(`Durée structurée : ${order.duration_value} ${DURATION_UNIT_LABELS[order.duration_unit]}`)
    }
    if (order.instructions !== null) parts.push(`Instructions : ${order.instructions}`)
    return { step: 'MEDICATIONS' as const, key: `order:${order.id}`, label: `Médicament : ${order.name}`, value: parts.join(' ; ') }
  })
}

function conclusionFacts(data: StepDataByName['CONCLUSION']): Fact[] {
  const step = 'CONCLUSION'
  const facts: Fact[] = [
    { step, key: 'conclusion', label: 'Conclusion', value: text(data.conclusion) },
    { step, key: 'referral', label: 'Orientation vers un ophtalmologiste', value: yesNo(data.referral) },
  ]
  if (data.referral === true) {
    facts.push(
      {
        step,
        key: 'urgency',
        label: 'Niveau d’urgence',
        value: data.referral_urgency === null ? NOT_FILLED : urgencyLabel(data.referral_urgency),
      },
      { step, key: 'reasons', label: 'Motifs de l’orientation', value: terms('REFERRAL_REASON', data.referral_reasons) },
    )
  }
  return facts
}

/** Décrit les sept rubriques d'une révision en faits, dans l'ordre du dossier. */
export function describeSteps(steps: StepsData, scaleLabel: ScaleLabeller): Fact[] {
  return [
    ...anamnesisFacts(steps.ANAMNESIS),
    ...historiesFacts(steps.HISTORIES),
    ...acuityFacts(steps.VISUAL_ACUITY, scaleLabel),
    ...refractionFacts(steps.REFRACTION),
    ...centeringFacts(steps.CENTERING),
    ...medicationFacts(steps.MEDICATIONS),
    ...conclusionFacts(steps.CONCLUSION),
  ]
}

/** Valeur « vide » d'un fait absent d'un côté de la comparaison. */
function absentValue(fact: Fact): string {
  return fact.step === 'VISUAL_ACUITY' || fact.step === 'REFRACTION' || fact.step === 'CENTERING'
    ? NOT_MEASURED
    : NOT_FILLED
}

/**
 * Compare deux révisions fait par fait. L'ordre est celui de la révision la plus récente (`right`), puis les
 * faits qui n'existent que dans l'ancienne (`left`).
 */
export function compareSteps(left: StepsData, right: StepsData, scaleLabel: ScaleLabeller): Comparison {
  const before = describeSteps(left, scaleLabel)
  const after = describeSteps(right, scaleLabel)
  const beforeByKey = new Map(before.map((fact) => [`${fact.step}|${fact.key}`, fact]))
  const afterKeys = new Set(after.map((fact) => `${fact.step}|${fact.key}`))

  const rows: ComparisonRow[] = []
  const isEmpty = (value: string): boolean => value === NOT_FILLED || value === NOT_MEASURED
  const build = (fact: Fact, other: Fact | undefined, mirrored: boolean): ComparisonRow => {
    const mine = fact.value
    const theirs = other?.value ?? absentValue(fact)
    const leftValue = mirrored ? mine : theirs
    const rightValue = mirrored ? theirs : mine
    const incomparable =
      fact.scale !== undefined && other?.scale !== undefined && fact.scale !== other.scale
    let kind: ChangeKind = 'same'
    if (leftValue !== rightValue) {
      if (isEmpty(leftValue) && !isEmpty(rightValue)) kind = 'added'
      else if (!isEmpty(leftValue) && isEmpty(rightValue)) kind = 'removed'
      else kind = 'changed'
    }
    return {
      step: fact.step,
      key: fact.key,
      label: fact.label,
      left: leftValue,
      right: rightValue,
      kind,
      note: incomparable ? 'Échelles différentes : ces valeurs ne sont pas comparables.' : null,
    }
  }

  for (const fact of after) {
    const other = beforeByKey.get(`${fact.step}|${fact.key}`)
    rows.push(build(fact, other, false))
  }
  for (const fact of before) {
    if (!afterKeys.has(`${fact.step}|${fact.key}`)) rows.push(build(fact, undefined, true))
  }
  // Ordre du dossier (les faits retirés reprennent leur rubrique), stable à l'intérieur d'une rubrique.
  const order = new Map(DELIVERED_STEPS.map((step, index) => [step, index]))
  const sorted = rows
    .map((row, index) => ({ row, index }))
    .sort((a, b) => (order.get(a.row.step) ?? 0) - (order.get(b.row.step) ?? 0) || a.index - b.index)
    .map((entry) => entry.row)

  return { rows: sorted, differences: sorted.filter((row) => row.kind !== 'same').length }
}
