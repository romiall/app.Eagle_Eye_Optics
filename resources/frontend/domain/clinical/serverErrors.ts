import { acuitySlotKey, groupKeyOf } from './acuity'
import { CONTROL_IDS } from './controls'
import type { DeliveredStep } from './labels'
import type { StepDataByName } from './steps'

/**
 * Refus 422 du serveur (`error.fields`) rattachés aux contrôles de l'écran. Les clés du serveur sont
 * des chemins pointés de la charge envoyée : `step.data.complaints.0.other_text`. L'indice désigne la
 * ligne de la donnée ENVOYÉE (celle de `buildStepData`) : on retrouve donc son code, puis le contrôle
 * de ce code. Une clé inconnue mène au titre de l'étape : le message n'est jamais perdu.
 */
export interface ServerFieldError {
  controlId: string
  message: string
}

const PREFIX = /^(?:step\.)?(?:data\.)?/

/** Chemin de la donnée de l'étape (`complaints.0.other_text`) à partir d'une clé du serveur. */
export function dataPathOf(key: string): string {
  return key.replace(PREFIX, '')
}

function index(segment: string | undefined): number | null {
  return segment !== undefined && /^[0-9]+$/.test(segment) ? Number(segment) : null
}

function anamnesisTarget(data: StepDataByName['ANAMNESIS'], path: string[]): string | null {
  const [head, position, field] = path
  switch (head) {
    case 'symptoms':
      return CONTROL_IDS.symptoms
    case 'wears_glasses':
      return CONTROL_IDS.wearsGlasses
    case 'glasses_duration':
      return CONTROL_IDS.glassesDuration
    case 'previous_correction_available':
      return CONTROL_IDS.previousCorrection
    case 'complaints': {
      const row = data.complaints[index(position) ?? -1]
      if (row === undefined) return CONTROL_IDS.complaints
      return field === 'other_text'
        ? CONTROL_IDS.complaintOther
        : CONTROL_IDS.complaint(row.term_code)
    }
    default:
      return null
  }
}

function historiesTarget(data: StepDataByName['HISTORIES'], path: string[]): string | null {
  if (path[0] !== 'histories') return null
  const history = data.histories[index(path[1]) ?? -1]
  if (history === undefined) return null
  const group = history.history_group
  switch (path[2]) {
    case 'known_none':
      return CONTROL_IDS.historyNone(group)
    case 'comments':
      return CONTROL_IDS.historyComments(group)
    case 'terms': {
      const term = history.terms[index(path[3]) ?? -1]
      if (term === undefined) return CONTROL_IDS.historyGroup(group)
      return path[4] === 'other_text'
        ? CONTROL_IDS.historyOther(group)
        : CONTROL_IDS.historyTerm(group, term.term_code)
    }
    default:
      return CONTROL_IDS.historyGroup(group)
  }
}

function conclusionTarget(data: StepDataByName['CONCLUSION'], path: string[]): string | null {
  const [head, position, field] = path
  switch (head) {
    case 'conclusion':
      return CONTROL_IDS.conclusion
    case 'referral':
      return CONTROL_IDS.referral
    case 'referral_urgency':
      return CONTROL_IDS.urgency
    case 'referral_reasons': {
      const row = data.referral_reasons[index(position) ?? -1]
      if (row === undefined) return CONTROL_IDS.reasons
      return field === 'other_text' ? CONTROL_IDS.reasonOther : CONTROL_IDS.reason(row.term_code)
    }
    default:
      return null
  }
}

function acuityTarget(data: StepDataByName['VISUAL_ACUITY'], path: string[]): string | null {
  if (path[0] !== 'acuities') return null
  const row = data.acuities[index(path[1]) ?? -1]
  if (row === undefined) return CONTROL_IDS.step
  const slot = { context: row.context, distance: row.distance }
  return path[2] === 'scale_id'
    ? CONTROL_IDS.acuityScale(groupKeyOf(slot))
    : CONTROL_IDS.acuityValue(acuitySlotKey(row.context, row.distance, row.correction, row.eye))
}

function refractionTarget(data: StepDataByName['REFRACTION'], path: string[]): string | null {
  const [head, position, field] = path
  switch (head) {
    case 'refraction_observations':
      return CONTROL_IDS.refractionObservations
    case 'measurements': {
      const row = data.measurements[index(position) ?? -1]
      if (row === undefined) return CONTROL_IDS.step
      const control = field === 'cylinder' || field === 'axis' || field === 'addition' ? field : 'sphere'
      return CONTROL_IDS.measure(row.stage, row.eye, control)
    }
    case 'ametropias':
      return field === 'other_text' ? CONTROL_IDS.ametropiaOther : CONTROL_IDS.ametropias
    default:
      return null
  }
}

function centeringTarget(path: string[]): string | null {
  switch (path[0]) {
    case 'pd_total':
    case 'centering_id':
      return CONTROL_IDS.pdTotal
    case 'pd_od':
      return CONTROL_IDS.pdOd
    case 'pd_og':
      return CONTROL_IDS.pdOg
    case 'inconsistency_reason':
      return CONTROL_IDS.pdReason
    default:
      return null
  }
}

/** Nom du champ d'une ligne de médicament dans la donnée → fragment de l'identifiant de son contrôle. */
const ORDER_CONTROLS: Readonly<Record<string, string>> = {
  name: 'nom',
  form: 'forme',
  form_other: 'forme-precision',
  strength: 'dosage',
  dosage: 'posologie',
  frequency: 'frequence',
  duration_text: 'duree',
  duration_value: 'duree-nombre',
  duration_unit: 'duree-unite',
  instructions: 'instructions',
}

function medicationsTarget(data: StepDataByName['MEDICATIONS'], path: string[]): string | null {
  if (path[0] !== 'orders') return null
  const position = index(path[1])
  if (position === null || data.orders[position] === undefined) return CONTROL_IDS.orders
  return CONTROL_IDS.order(position, ORDER_CONTROLS[path[2] ?? ''] ?? 'nom')
}

/**
 * Contrôle d'une clé d'erreur du serveur pour la donnée `data` qui vient d'être envoyée ; le titre de
 * l'étape si la clé n'est pas reconnue.
 */
export function serverErrorTarget<S extends DeliveredStep>(
  step: S,
  data: StepDataByName[S],
  key: string,
): string {
  const path = dataPathOf(key).split('.')
  let target: string | null
  switch (step) {
    case 'ANAMNESIS':
      target = anamnesisTarget(data as StepDataByName['ANAMNESIS'], path)
      break
    case 'HISTORIES':
      target = historiesTarget(data as StepDataByName['HISTORIES'], path)
      break
    case 'VISUAL_ACUITY':
      target = acuityTarget(data as StepDataByName['VISUAL_ACUITY'], path)
      break
    case 'REFRACTION':
      target = refractionTarget(data as StepDataByName['REFRACTION'], path)
      break
    case 'CENTERING':
      target = centeringTarget(path)
      break
    case 'MEDICATIONS':
      target = medicationsTarget(data as StepDataByName['MEDICATIONS'], path)
      break
    default:
      target = conclusionTarget(data as StepDataByName['CONCLUSION'], path)
  }
  return target ?? CONTROL_IDS.step
}

/** Toutes les erreurs du serveur rattachées à leur contrôle, dans l'ordre reçu. */
export function serverFieldErrors<S extends DeliveredStep>(
  step: S,
  data: StepDataByName[S],
  fields: Readonly<Record<string, readonly string[]>>,
): ServerFieldError[] {
  const errors: ServerFieldError[] = []
  for (const [key, messages] of Object.entries(fields)) {
    const controlId = serverErrorTarget(step, data, key)
    for (const message of messages) errors.push({ controlId, message })
  }
  return errors
}
