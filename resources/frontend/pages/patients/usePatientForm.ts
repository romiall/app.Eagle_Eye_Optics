import { computed, type Ref, ref, watch } from 'vue'

import type { ApiError } from '@/app/api'
import { clinicToday } from '@/app/clinicTime'
import type { FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import {
  FORM_FIELDS,
  formFieldFromServer,
  type PatientFormErrors,
  type PatientFormState,
  type PatientIdentity,
  validatePatientForm,
} from '@/domain/patients/form'

import { FIELD_IDS } from './formFields'

const DATE_REFUSED =
  'La date de naissance saisie n’est pas valide : corrigez-la ou videz le champ pour indiquer la raison de son absence.'

/**
 * État commun au formulaire de création et au formulaire de modification : la saisie, les erreurs
 * par champ (règles locales, puis refus du serveur), le résumé d'erreurs et la lecture de la date de
 * naissance avant l'envoi. L'erreur d'un champ disparaît quand la personne le corrige ; la saisie
 * n'est jamais effacée par une erreur.
 */
export function usePatientForm(
  initial: PatientFormState,
  fields: Ref<{ commit: () => boolean } | null>,
) {
  const form = ref<PatientFormState>({ ...initial })
  const errors = ref<PatientFormErrors>({})
  /** Date de naissance saisie mais illisible : le champ en dit la raison, le résumé la reprend. */
  const dateRefused = ref(false)
  /** Refus du serveur qui ne portent sur aucun champ du formulaire. */
  const notes = ref<string[]>([])
  const general = ref<string | null>(null)
  const summaryBox = ref<{ focus: () => Promise<void> } | null>(null)

  watch(form, (now, before) => {
    for (const field of FORM_FIELDS) {
      if (now[field] === before[field]) continue
      if (errors.value[field] !== undefined) {
        const rest = { ...errors.value }
        delete rest[field]
        errors.value = rest
      }
      // Une date valide résout le refus ; un champ vidé reste en échec jusqu'au prochain envoi.
      if (field === 'birthDate' && now.birthDate !== null) dateRefused.value = false
    }
  })

  const summary = computed<FieldErrorItem[]>(() => {
    const items: FieldErrorItem[] = []
    for (const field of FORM_FIELDS) {
      const message = errors.value[field]
      if (message !== undefined) items.push({ fieldId: FIELD_IDS[field], message })
      if (field === 'birthDate' && dateRefused.value) {
        items.push({ fieldId: FIELD_IDS.birthDate, message: DATE_REFUSED })
      }
    }
    for (const note of notes.value) items.push({ fieldId: FIELD_IDS.lastName, message: note })
    if (items.length === 0 && general.value !== null) {
      items.push({ fieldId: FIELD_IDS.lastName, message: general.value })
    }
    return items
  })

  function clearFeedback(): void {
    errors.value = {}
    notes.value = []
    general.value = null
    dateRefused.value = false
  }

  async function focusSummary(): Promise<void> {
    await summaryBox.value?.focus()
  }

  /**
   * Lit la date de naissance, puis applique les règles. Rend l'identité normalisée, ou null après
   * avoir affiché les erreurs (résumé focalisé, même si ce sont les mêmes qu'à l'envoi précédent).
   */
  function validate(): PatientIdentity | null {
    clearFeedback()
    if (!(fields.value?.commit() ?? true)) {
      dateRefused.value = true
      void focusSummary()
      return null
    }
    const result = validatePatientForm(form.value, clinicToday())
    if (!result.ok) {
      errors.value = result.errors
      void focusSummary()
      return null
    }
    return result.values
  }

  /** Refus 422 du serveur : message sous chaque champ reconnu, le reste dans le résumé. */
  function applyServerFailure(error: ApiError): void {
    clearFeedback()
    const byField: PatientFormErrors = {}
    for (const [key, messages] of Object.entries(error.fields)) {
      const message = messages[0]
      if (message === undefined) continue
      const field = formFieldFromServer(key)
      if (field === null) notes.value.push(message)
      else byField[field] = message
    }
    errors.value = byField
    if (Object.keys(byField).length === 0 && notes.value.length === 0) general.value = error.message
  }

  return {
    form,
    errors,
    summary,
    summaryBox,
    validate,
    applyServerFailure,
    clearFeedback,
    focusSummary,
  }
}
