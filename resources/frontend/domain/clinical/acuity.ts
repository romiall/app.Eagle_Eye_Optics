import { CONTROL_IDS } from './controls'
import { cleanText, type NewId, type StepCodec, type StepError, textError } from './fields'
import { EYE_SHORT_LABELS } from './labels'

/**
 * Rubrique VISUAL_ACUITY (C02 ; fichier 02, « Examens et réfraction » ; DP §6 et §7) : acuité de loin et de près,
 * sans et avec correction, pour OD, OG et OU (douze valeurs), et acuité après correction OD, OG, OU (trois
 * valeurs, sans distinction loin/près). Toutes les valeurs sont facultatives : une valeur non mesurée n'a pas de
 * ligne (jamais zéro) ; « 0 » est une valeur.
 *
 * - L'échelle (`scale_id`) est celle du cabinet, choisie par le professionnel pour chaque groupe (loin, près,
 *   après correction). La valeur lue est conservée TELLE QUE SAISIE (« 10/10 », « 6/6 », « P2 », « 0 »).
 * - AUCUNE conversion entre échelles n'est faite ni suggérée : `normalized_value` n'est jamais calculé (le serveur
 *   le refuse). Des échelles différentes dans un même groupe sont SIGNALÉES (`mixedScaleGroups`) : leurs valeurs ne
 *   sont pas comparables.
 * - Les règles de `acuityErrors` sont celles du serveur (`VisualAcuityStep`).
 *
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
export type AcuityContext = 'PRELIMINARY' | 'POST_REFRACTION'
export type AcuityDistance = 'FAR' | 'NEAR' | 'UNSPECIFIED'
export type AcuityCorrection = 'WITHOUT' | 'WITH'
export type AcuityEye = 'OD' | 'OG' | 'OU'

export const ACUITY_RAW_MAX = 100

export interface AcuityRowData {
  id: string
  context: AcuityContext
  distance: AcuityDistance
  correction: AcuityCorrection
  eye: AcuityEye
  scale_id: string
  raw_value: string
  normalized_value: string | null
}

export interface VisualAcuityData {
  acuities: AcuityRowData[]
}

/** Une case de la grille : un contexte, une distance, une correction et un œil. */
export interface AcuitySlot {
  key: string
  context: AcuityContext
  distance: AcuityDistance
  correction: AcuityCorrection
  eye: AcuityEye
}

const EYES: readonly AcuityEye[] = ['OD', 'OG', 'OU']

export function acuitySlotKey(
  context: AcuityContext,
  distance: AcuityDistance,
  correction: AcuityCorrection,
  eye: AcuityEye,
): string {
  return `${context}|${distance}|${correction}|${eye}`
}

function slotsOf(
  context: AcuityContext,
  distance: AcuityDistance,
  correction: AcuityCorrection,
): AcuitySlot[] {
  return EYES.map((eye) => ({
    key: acuitySlotKey(context, distance, correction, eye),
    context,
    distance,
    correction,
    eye,
  }))
}

/** Les douze valeurs préliminaires (loin puis près, sans puis avec correction) et les trois après correction. */
export const ACUITY_SLOTS: readonly AcuitySlot[] = [
  ...slotsOf('PRELIMINARY', 'FAR', 'WITHOUT'),
  ...slotsOf('PRELIMINARY', 'FAR', 'WITH'),
  ...slotsOf('PRELIMINARY', 'NEAR', 'WITHOUT'),
  ...slotsOf('PRELIMINARY', 'NEAR', 'WITH'),
  ...slotsOf('POST_REFRACTION', 'UNSPECIFIED', 'WITH'),
]

/** Un groupe de valeurs destinées à être comparées entre elles : même contexte et même distance. */
export interface AcuityGroup {
  key: string
  title: string
  context: AcuityContext
  distance: AcuityDistance
  /** Colonnes (corrections) du groupe : « sans » et « avec », ou « avec » seul après réfraction. */
  corrections: readonly AcuityCorrection[]
}

export const ACUITY_GROUPS: readonly AcuityGroup[] = [
  {
    key: 'PRELIMINARY|FAR',
    title: 'Acuité de loin',
    context: 'PRELIMINARY',
    distance: 'FAR',
    corrections: ['WITHOUT', 'WITH'],
  },
  {
    key: 'PRELIMINARY|NEAR',
    title: 'Acuité de près',
    context: 'PRELIMINARY',
    distance: 'NEAR',
    corrections: ['WITHOUT', 'WITH'],
  },
  {
    key: 'POST_REFRACTION|UNSPECIFIED',
    title: 'Acuité après correction',
    context: 'POST_REFRACTION',
    distance: 'UNSPECIFIED',
    corrections: ['WITH'],
  },
]

export const CORRECTION_TITLES: Readonly<Record<AcuityCorrection, string>> = {
  WITHOUT: 'Sans correction',
  WITH: 'Avec correction',
}

export function groupKeyOf(slot: Pick<AcuitySlot, 'context' | 'distance'>): string {
  return `${slot.context}|${slot.distance}`
}

// --- Brouillon -------------------------------------------------------------------------------------

/** Une case : identifiant de la ligne (créé sur le poste), échelle choisie, valeur lue telle que saisie. */
export interface AcuityCell {
  id: string
  scaleId: string | null
  value: string
  /** Valeur normalisée déjà enregistrée (jamais calculée ici) : renvoyée telle quelle pour ne rien perdre. */
  normalized: string | null
}

export type VisualAcuityDraft = Record<string, AcuityCell>

export function blankVisualAcuity(newId: NewId): VisualAcuityDraft {
  const draft: VisualAcuityDraft = {}
  for (const slot of ACUITY_SLOTS) {
    draft[slot.key] = { id: newId(), scaleId: null, value: '', normalized: null }
  }
  return draft
}

export function visualAcuityDraft(data: VisualAcuityData, newId: NewId): VisualAcuityDraft {
  const draft = blankVisualAcuity(newId)
  for (const row of data.acuities ?? []) {
    const key = acuitySlotKey(row.context, row.distance, row.correction, row.eye)
    if (!(key in draft)) continue
    draft[key] = {
      id: row.id,
      scaleId: row.scale_id === '' ? null : row.scale_id,
      value: row.raw_value,
      normalized: row.normalized_value,
    }
  }
  return draft
}

/** Donnée envoyée : une ligne par case renseignée, dans l'ordre de la grille ; une case vide n'a pas de ligne. */
export function visualAcuityData(draft: VisualAcuityDraft): VisualAcuityData {
  const acuities: AcuityRowData[] = []
  for (const slot of ACUITY_SLOTS) {
    const cell = draft[slot.key]
    const value = cell === undefined ? null : cleanText(cell.value)
    if (cell === undefined || value === null) continue
    acuities.push({
      id: cell.id,
      context: slot.context,
      distance: slot.distance,
      correction: slot.correction,
      eye: slot.eye,
      scale_id: cell.scaleId ?? '',
      raw_value: value,
      normalized_value: cell.normalized,
    })
  }
  return { acuities }
}

function slotLabel(slot: AcuitySlot): string {
  const group = ACUITY_GROUPS.find((entry) => entry.key === groupKeyOf(slot))
  const correction =
    slot.context === 'PRELIMINARY' ? `, ${CORRECTION_TITLES[slot.correction].toLowerCase()}` : ''
  return `${group?.title ?? 'Acuité'}${correction}, ${EYE_SHORT_LABELS[slot.eye]}`
}

export function acuityErrors(draft: VisualAcuityDraft): StepError[] {
  const errors: StepError[] = []
  let index = 0
  for (const slot of ACUITY_SLOTS) {
    const cell = draft[slot.key]
    if (cell === undefined || cleanText(cell.value) === null) continue
    const path = `acuities.${index}`
    index += 1
    const message = textError(cell.value, `Valeur lue (${slotLabel(slot)})`, ACUITY_RAW_MAX, true)
    if (message !== null) {
      errors.push({
        path: `${path}.raw_value`,
        controlId: CONTROL_IDS.acuityValue(slot.key),
        message,
      })
    }
    if (cell.scaleId === null) {
      errors.push({
        path: `${path}.scale_id`,
        controlId: CONTROL_IDS.acuityScale(groupKeyOf(slot)),
        message: `${slotLabel(slot)} : choisissez l’échelle utilisée.`,
      })
    }
  }
  return errors
}

/**
 * Groupes (loin, près, après correction) dont les valeurs renseignées n'ont pas toutes la même échelle : leurs
 * valeurs ne sont PAS comparables (aucune conversion validée). Les titres sont ceux de l'écran.
 */
export function mixedScaleGroups(draft: VisualAcuityDraft): AcuityGroup[] {
  return ACUITY_GROUPS.filter((group) => {
    const scales = new Set<string>()
    for (const slot of ACUITY_SLOTS) {
      if (groupKeyOf(slot) !== group.key) continue
      const cell = draft[slot.key]
      if (cell === undefined || cleanText(cell.value) === null || cell.scaleId === null) continue
      scales.add(cell.scaleId)
    }
    return scales.size > 1
  })
}

/** Échelle commune des cases renseignées d'un groupe : l'identifiant, null s'il n'y en a pas ou si elles diffèrent. */
export function groupScale(draft: VisualAcuityDraft, groupKey: string): string | null {
  const scales = new Set<string | null>()
  for (const slot of ACUITY_SLOTS) {
    if (groupKeyOf(slot) !== groupKey) continue
    const cell = draft[slot.key]
    if (cell === undefined) continue
    if (cleanText(cell.value) === null && cell.scaleId === null) continue
    scales.add(cell.scaleId)
  }
  if (scales.size !== 1) return null
  const [only] = [...scales]
  return only ?? null
}

/**
 * Applique l'échelle choisie à TOUTES les cases du groupe (renseignées ou non) : un choix explicite du
 * professionnel, jamais une échelle devinée.
 */
export function setGroupScale(
  draft: VisualAcuityDraft,
  groupKey: string,
  scaleId: string | null,
): VisualAcuityDraft {
  const next: VisualAcuityDraft = { ...draft }
  for (const slot of ACUITY_SLOTS) {
    if (groupKeyOf(slot) !== groupKey) continue
    const cell = next[slot.key]
    if (cell !== undefined) next[slot.key] = { ...cell, scaleId }
  }
  return next
}

export function setAcuityValue(
  draft: VisualAcuityDraft,
  slotKey: string,
  value: string,
): VisualAcuityDraft {
  const cell = draft[slotKey]
  if (cell === undefined) return draft
  return { ...draft, [slotKey]: { ...cell, value } }
}

export const visualAcuityCodec: StepCodec<VisualAcuityDraft, VisualAcuityData> = {
  blank: blankVisualAcuity,
  fromData: visualAcuityDraft,
  toData: visualAcuityData,
  errors: acuityErrors,
}
