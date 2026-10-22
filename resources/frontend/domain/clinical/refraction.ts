import { CONTROL_IDS } from './controls'
import {
  axisData,
  axisError,
  axisText,
  axisUnparsable,
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
import { EYE_SHORT_LABELS, STAGE_LABELS } from './labels'
import { rowsFromData, termErrors, type TermData, type TermRow, termsData } from './termRows'

/**
 * Rubrique REFRACTION (C02 ; fichier 02 ; DP §6 et §7) : mesures de l'autoréfracteur, de la réfraction
 * objective et de la réfraction subjective, séparées par œil, puis observations libres et amétropies choisies
 * par le professionnel.
 *
 * - Les trois stades sont DISTINCTS et ne se fusionnent jamais : une ligne par (stade, œil). Un œil sans
 *   aucune valeur n'a pas de ligne ; zéro est une valeur (« 0,00 »), distincte du vide.
 * - Sphère, cylindre et addition : chaîne décimale exacte à deux décimales, jamais arrondie ; axe : entier de 0 à
 *   180. L'addition n'existe que pour la réfraction SUBJECTIVE.
 * - AUCUNE inférence : le cylindre positif n'est pas transposé, l'axe n'est pas déduit, les amétropies ne
 *   dépendent d'aucun chiffre. Un pas usuel de 0,25 est SIGNALÉ (jamais imposé) par `stepNotices`, selon le
 *   fichier 02 : « sans exclure arbitrairement une mesure plausible ».
 * - Les règles de `refractionErrors` sont celles du serveur (`RefractionStep`).
 *
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
export type RefractionStage = 'AUTOREFRACTOR' | 'OBJECTIVE' | 'SUBJECTIVE'
export type RefractionEye = 'OD' | 'OG'

export const REFRACTION_STAGES: readonly RefractionStage[] = ['AUTOREFRACTOR', 'OBJECTIVE', 'SUBJECTIVE']
export const REFRACTION_EYES: readonly RefractionEye[] = ['OD', 'OG']
export const AMETROPIAS_MAX = 20

/**
 * Pas usuel de 0,25 dioptrie en centièmes (hypothèse de démonstration, à valider avec le professionnel : Q06).
 * Sert uniquement à une indication non bloquante.
 */
export const USUAL_STEP_UNITS = 25n

export interface MeasurementData {
  id: string
  stage: RefractionStage
  eye: RefractionEye
  sphere: string | null
  cylinder: string | null
  axis: number | null
  addition: string | null
}

export interface RefractionData {
  measurements: MeasurementData[]
  refraction_observations: string | null
  ametropias: TermData[]
}

export function measurementKey(stage: RefractionStage, eye: RefractionEye): string {
  return `${stage}|${eye}`
}

/** Une case de mesure : identifiant de ligne (créé sur le poste) et saisies brutes (virgule française admise). */
export interface MeasureCell {
  id: string
  sphere: string
  cylinder: string
  axis: string
  addition: string
}

export interface RefractionDraft {
  cells: Record<string, MeasureCell>
  observations: string
  ametropias: TermRow[]
}

export function blankRefraction(newId: NewId): RefractionDraft {
  const cells: Record<string, MeasureCell> = {}
  for (const stage of REFRACTION_STAGES) {
    for (const eye of REFRACTION_EYES) {
      cells[measurementKey(stage, eye)] = { id: newId(), sphere: '', cylinder: '', axis: '', addition: '' }
    }
  }
  return { cells, observations: '', ametropias: [] }
}

export function refractionDraft(data: RefractionData, newId: NewId): RefractionDraft {
  const draft = blankRefraction(newId)
  for (const row of data.measurements ?? []) {
    const key = measurementKey(row.stage, row.eye)
    if (!(key in draft.cells)) continue
    draft.cells[key] = {
      id: row.id,
      sphere: measureText(row.sphere),
      cylinder: measureText(row.cylinder),
      axis: axisText(row.axis),
      addition: measureText(row.addition),
    }
  }
  return {
    cells: draft.cells,
    observations: data.refraction_observations ?? '',
    ametropias: rowsFromData('AMETROPIA', data.ametropias),
  }
}

/** Vrai si la case porte au moins une saisie (une case vide n'a pas de ligne). */
export function isMeasureCellFilled(cell: MeasureCell, stage: RefractionStage): boolean {
  return (
    cleanText(cell.sphere) !== null ||
    cleanText(cell.cylinder) !== null ||
    cleanText(cell.axis) !== null ||
    (stage === 'SUBJECTIVE' && cleanText(cell.addition) !== null)
  )
}

export function refractionData(draft: RefractionDraft): RefractionData {
  const measurements: MeasurementData[] = []
  for (const stage of REFRACTION_STAGES) {
    for (const eye of REFRACTION_EYES) {
      const cell = draft.cells[measurementKey(stage, eye)]
      if (cell === undefined || !isMeasureCellFilled(cell, stage)) continue
      measurements.push({
        id: cell.id,
        stage,
        eye,
        sphere: measureData(cell.sphere),
        cylinder: measureData(cell.cylinder),
        axis: axisData(cell.axis),
        addition: stage === 'SUBJECTIVE' ? measureData(cell.addition) : null,
      })
    }
  }
  return {
    measurements,
    refraction_observations: cleanText(draft.observations),
    ametropias: termsData(draft.ametropias),
  }
}

export function refractionErrors(draft: RefractionDraft): StepError[] {
  const errors: StepError[] = []
  let index = 0
  for (const stage of REFRACTION_STAGES) {
    for (const eye of REFRACTION_EYES) {
      const cell = draft.cells[measurementKey(stage, eye)]
      if (cell === undefined || !isMeasureCellFilled(cell, stage)) continue
      const path = `measurements.${index}`
      index += 1
      const subject = `${STAGE_LABELS[stage]}, ${EYE_SHORT_LABELS[eye]}`
      const fields = [
        ['sphere', 'Sphère', cell.sphere, CONTROL_IDS.measure(stage, eye, 'sphere')],
        ['cylinder', 'Cylindre', cell.cylinder, CONTROL_IDS.measure(stage, eye, 'cylinder')],
      ] as const
      for (const [field, label, text, controlId] of fields) {
        const message = measureError(text)
        if (message !== null) errors.push({ path: `${path}.${field}`, controlId, message: `${subject} · ${label} : ${message}` })
      }
      const axis = axisError(cell.axis)
      if (axis !== null) {
        errors.push({ path: `${path}.axis`, controlId: CONTROL_IDS.measure(stage, eye, 'axis'), message: `${subject} · Axe : ${axis}` })
      }
      if (stage === 'SUBJECTIVE') {
        const addition = measureError(cell.addition)
        if (addition !== null) {
          errors.push({
            path: `${path}.addition`,
            controlId: CONTROL_IDS.measure(stage, eye, 'addition'),
            message: `${subject} · Addition : ${addition}`,
          })
        }
      }
    }
  }
  const observations = textError(draft.observations, 'Les observations')
  if (observations !== null) {
    errors.push({ path: 'refraction_observations', controlId: CONTROL_IDS.refractionObservations, message: observations })
  }
  errors.push(
    ...termErrors({
      path: 'ametropias',
      group: 'AMETROPIA',
      rows: draft.ametropias,
      max: AMETROPIAS_MAX,
      listControl: CONTROL_IDS.ametropias,
      otherControl: CONTROL_IDS.ametropiaOther,
      otherSubject: 'Amétropie',
    }),
  )
  return errors
}

export function refractionUnparsable(draft: RefractionDraft): boolean {
  return Object.values(draft.cells).some(
    (cell) =>
      measureUnparsable(cell.sphere) ||
      measureUnparsable(cell.cylinder) ||
      measureUnparsable(cell.addition) ||
      axisUnparsable(cell.axis),
  )
}

/**
 * Indications NON BLOQUANTES sur une case (jamais une erreur, la mesure est conservée telle que saisie) :
 *  - valeur qui n'est pas un multiple du pas usuel de 0,25 ;
 *  - cylindre non nul sans axe (l'axe est exigé pour IMPRIMER une prescription, pas pour enregistrer une mesure) ;
 *  - axe sans cylindre.
 */
export function stepNotices(cell: MeasureCell, stage: RefractionStage): string[] {
  const notices: string[] = []
  const entries: [string, string][] = [
    ['Sphère', cell.sphere],
    ['Cylindre', cell.cylinder],
  ]
  if (stage === 'SUBJECTIVE') entries.push(['Addition', cell.addition])
  for (const [label, text] of entries) {
    const units = measureUnits(text)
    if (units !== null && units % USUAL_STEP_UNITS !== 0n) {
      notices.push(`${label} : ce n’est pas un multiple du pas usuel de 0,25. La valeur saisie est conservée.`)
    }
  }
  const cylinder = measureUnits(cell.cylinder)
  const axis = cleanText(cell.axis)
  if (cylinder !== null && cylinder !== 0n && axis === null) {
    notices.push('Cylindre non nul sans axe : l’axe sera exigé pour imprimer une prescription.')
  }
  if (axis !== null && (cylinder === null || cylinder === 0n)) {
    notices.push('Axe renseigné sans cylindre non nul.')
  }
  return notices
}

export const refractionCodec: StepCodec<RefractionDraft, RefractionData> = {
  blank: blankRefraction,
  fromData: refractionDraft,
  toData: refractionData,
  errors: refractionErrors,
  unparsable: refractionUnparsable,
}
