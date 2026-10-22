import type { PatientRecordsSex } from '../contracts/generated'
import { CivilDate } from '../values/civilDate'

/**
 * Identité d'un patient telle que l'affichent les écrans (fichier 02, « Patients et accueil »).
 * Règles pures : ni Vue, ni réseau, ni stockage. L'âge est calculé à la date civile du cabinet, jamais
 * stocké à la place de la naissance.
 */
export const SEX_CODES: readonly PatientRecordsSex[] = ['F', 'M', 'UNKNOWN']

export const SEX_LABELS: Readonly<Record<PatientRecordsSex, string>> = {
  F: 'Féminin',
  M: 'Masculin',
  UNKNOWN: 'Non renseigné',
}

export function sexLabel(sex: PatientRecordsSex): string {
  return SEX_LABELS[sex]
}

/** « NGONO Marie » : nom puis prénom(s) tels qu'enregistrés ; un prénom peut être absent. */
export function patientDisplayName(lastName: string, firstNames: string | null): string {
  const first = firstNames?.trim() ?? ''
  return first === '' ? lastName : `${lastName} ${first}`
}

export type PatientAge =
  /** Années révolues (2 ans et plus, ou 1 an). */
  | { kind: 'years'; value: number }
  /** Moins d'un an : mois révolus (0 = moins d'un mois). */
  | { kind: 'months'; value: number }
  /** Pas de date de naissance : « âge non renseigné », jamais une date inventée. */
  | { kind: 'unknown' }
  /** Date illisible ou postérieure à aujourd'hui : à vérifier, aucun âge n'est affirmé. */
  | { kind: 'invalid' }

/** Âge à `today` (date civile du cabinet). */
export function patientAge(birthDate: string | null, today: CivilDate): PatientAge {
  if (birthDate === null) return { kind: 'unknown' }
  let birth: CivilDate
  try {
    birth = CivilDate.fromString(birthDate)
  } catch {
    return { kind: 'invalid' }
  }
  if (birth.compare(today) > 0) return { kind: 'invalid' }
  const years = birth.ageOn(today)
  if (years > 0) return { kind: 'years', value: years }
  const months =
    (today.year - birth.year) * 12 + (today.month - birth.month) - (today.day < birth.day ? 1 : 0)
  return { kind: 'months', value: months }
}

export function formatAge(age: PatientAge): string {
  switch (age.kind) {
    case 'years':
      return age.value === 1 ? '1 an' : `${age.value} ans`
    case 'months':
      if (age.value === 0) return 'moins d’un mois'
      return `${age.value} mois`
    case 'unknown':
      return 'âge non renseigné'
    case 'invalid':
      return 'date de naissance à vérifier'
  }
}
