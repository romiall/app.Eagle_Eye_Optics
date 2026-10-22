import type { Patient } from '../contracts/generated'
import { trimBlanks } from '../values/text'
import { FORM_FIELDS, formFromPatient, type PatientFormField, type PatientFormState } from './form'

/**
 * Reprise d'une modification après un conflit de version (AT42 : aucune proposition écrasée en
 * silence). Trois fiches entrent en jeu : celle que la personne a ouverte (`base`), celle qui est
 * maintenant sur le serveur (`latest`) et sa saisie (`current`).
 *
 * - Un champ que la personne n'a pas touché reprend la valeur actuelle du serveur : le travail d'un
 *   autre poste n'est pas annulé.
 * - Un champ qu'elle a modifié garde sa saisie.
 * - Si ce champ a AUSSI été modifié sur le serveur (autrement que vers la même valeur), il est
 *   signalé : la personne voit la valeur actuelle et décide avant d'enregistrer de nouveau.
 */
export interface RebasedEdits {
  form: PatientFormState
  /** Champs modifiés des deux côtés, avec des valeurs différentes. */
  conflicts: PatientFormField[]
}

function same(field: PatientFormField, left: PatientFormState, right: PatientFormState): boolean {
  const a = left[field]
  const b = right[field]
  return typeof a === 'string' && typeof b === 'string' ? trimBlanks(a) === trimBlanks(b) : a === b
}

function copyField<K extends PatientFormField>(
  to: PatientFormState,
  from: PatientFormState,
  field: K,
): void {
  to[field] = from[field]
}

export function rebaseEdits(
  base: Patient,
  latest: Patient,
  current: PatientFormState,
): RebasedEdits {
  const baseForm = formFromPatient(base)
  const latestForm = formFromPatient(latest)
  const form = { ...latestForm }
  const conflicts: PatientFormField[] = []
  for (const field of FORM_FIELDS) {
    if (same(field, current, baseForm)) continue
    copyField(form, current, field)
    if (!same(field, latestForm, baseForm) && !same(field, latestForm, current)) {
      conflicts.push(field)
    }
  }
  return { form, conflicts }
}
