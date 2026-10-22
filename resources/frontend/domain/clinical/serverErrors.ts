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
