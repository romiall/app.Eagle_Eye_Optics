<script setup lang="ts">
/**
 * Enregistrer une arrivée (P03 ; fichier 02 : « À l'arrivée, créer une visite horodatée avec motif
 * administratif » ; AT05). Le patient se choisit par recherche (nom, téléphone ou numéro de dossier,
 * 2 caractères au moins) ou arrive déjà choisi depuis sa fiche (`?patient=<identifiant>`) ; le motif
 * est explicite (examen de vue, achat direct, retrait de commande, contrôle de suivi) et la note est
 * facultative et administrative. Un achat ou un retrait n'impose aucun examen.
 *
 * Enregistrement EN LIGNE : l'arrivée n'est dite enregistrée qu'après la réponse du serveur
 * (« Synchronisé avec le serveur »), qui décide du statut. Sans réponse, la saisie reste à l'écran et
 * « Réessayer l'enregistrement » reprend la même commande avec la même clé d'idempotence : jamais deux
 * arrivées pour une seule saisie. L'heure d'arrivée est celle de l'envoi ; une reprise de la même saisie
 * garde l'heure de la première tentative. Le contrôle du paiement de l'examen n'existe pas encore :
 * l'écran ne le laisse pas croire.
 *
 * Une autre visite du même patient le même jour ne bloque pas : un simple avertissement la signale.
 */
import { computed, ref, shallowRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/app/api'
import { clinicTimeZone, clinicToday, formatTimeFr } from '@/app/clinicTime'
import type { MutationOutcome } from '@/app/services/mutations'
import { getPatient, searchPatients } from '@/app/services/patients'
import { useMutation } from '@/app/services/useMutation'
import { type DayVisits, listDayVisits } from '@/app/services/visits'
import { can, enabledModules, examFeeControlledOnScreen, revalidateSession } from '@/app/session'
import { useUnsavedChangesGuard } from '@/app/unsavedChanges'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import SaveStatus from '@/components/feedback/SaveStatus.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import ComboboxField, {
  type ComboboxOption,
  type ComboboxSearchResult,
} from '@/components/form/ComboboxField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import AppButton from '@/components/ui/AppButton.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import type { Patient, PatientSummary } from '@/domain/contracts/generated'
import { formatAge, patientAge, patientDisplayName } from '@/domain/patients/identity'
import {
  type ArrivalErrors,
  arrivalFieldFromServer,
  buildRegisterVisitPayload,
  currentInstant,
  existingVisits,
  isVisitReason,
  NOTE_MAX_LENGTH,
  reasonHint,
  reasonLabel,
  statusLabel,
  validateArrival,
  VISIT_REASONS,
} from '@/domain/patients/visits'
import { isUlid } from '@/domain/values/ulid'
import { failureView } from '@/pages/patients/failureView'
import ReadStateMessage from '@/pages/patients/ReadStateMessage.vue'
import { usePatientRead } from '@/pages/patients/usePatientRead'

import { setArrivalFlash } from './flash'
import { useServerRead } from './useServerRead'

/** Identifiants des contrôles : cibles du résumé d'erreurs (chaque erreur mène à son champ). */
const FIELD_IDS = {
  patient: 'arrivee-patient',
  reason: 'arrivee-motif',
  note: 'arrivee-note',
} as const

const ALREADY_RECORDED =
  'Cet identifiant de visite est déjà utilisé : l’arrivée a peut-être déjà été enregistrée sans que la réponse ne soit arrivée. Vérifiez la file du jour avant de recommencer, pour ne pas créer une seconde arrivée.'

const route = useRoute()
const router = useRouter()
const mutation = useMutation()
const { state, saveState, recordedAt, failureMessage } = mutation
const timeZone = clinicTimeZone()

// --- Saisie ---------------------------------------------------------------------------------------

const patientId = ref<string | null>(null)
const reasonValue = ref<string | null>(null)
const note = ref('')
/** Identité du patient choisi (résultat de recherche ou fiche lue) : montrée avant d'enregistrer. */
const selected = shallowRef<PatientSummary | null>(null)
/** Résultats connus par identifiant : le composant de choix ne rend que la valeur de l'option. */
const known = new Map<string, PatientSummary>()
const errors = ref<ArrivalErrors>({})
const notes = ref<string[]>([])
const general = ref<string | null>(null)
const summaryBox = ref<{ focus: () => Promise<void> } | null>(null)
const saved = ref(false)
/** Le serveur dit l'identifiant déjà pris : l'arrivée existe peut-être, la sortie ne demande rien. */
const alreadyRecorded = ref(false)
const searchForbidden = ref(false)
const preselectedId = ref<string | null>(null)

const reason = computed(() =>
  reasonValue.value !== null && isVisitReason(reasonValue.value) ? reasonValue.value : null,
)
const reasonOptions = VISIT_REASONS.map((value) => ({ value, label: reasonLabel(value) }))
const busy = computed(() => state.value === 'saving')
const dirty = computed(
  () =>
    !saved.value &&
    !alreadyRecorded.value &&
    (reasonValue.value !== null ||
      note.value.trim() !== '' ||
      (patientId.value !== null && patientId.value !== preselectedId.value)),
)
useUnsavedChangesGuard(dirty)

const failure = computed(() => failureView(state.value, 'create'))
const canCreatePatient = computed(
  () => can('patient.create') && enabledModules.value.includes('patients'),
)

// Une saisie corrigée efface l'erreur de son champ ; la saisie n'est jamais effacée par une erreur.
watch(patientId, () => dropError('patient'))
watch(reasonValue, () => dropError('reason'))
watch(note, () => dropError('note'))

function dropError(field: keyof ArrivalErrors): void {
  if (errors.value[field] === undefined) return
  const rest = { ...errors.value }
  delete rest[field]
  errors.value = rest
}

function clearFeedback(): void {
  errors.value = {}
  notes.value = []
  general.value = null
}

const patientProblem = computed<string | null>(() => {
  const patient = selected.value
  if (patient === null) return null
  if (patient.merged_into_patient_id !== null) {
    return 'Cette fiche a été fusionnée dans une autre : cherchez la fiche conservée.'
  }
  if (patient.archived)
    return 'Cette fiche est archivée : une arrivée ne peut pas lui être enregistrée.'
  return null
})
const patientError = computed(() => errors.value.patient ?? patientProblem.value)

const summary = computed<FieldErrorItem[]>(() => {
  const items: FieldErrorItem[] = []
  for (const field of ['patient', 'reason', 'note'] as const) {
    const message = errors.value[field]
    if (message !== undefined) items.push({ fieldId: FIELD_IDS[field], message })
  }
  for (const message of notes.value) items.push({ fieldId: FIELD_IDS.patient, message })
  if (items.length === 0 && general.value !== null) {
    items.push({ fieldId: FIELD_IDS.patient, message: general.value })
  }
  return items
})

// --- Choix du patient -----------------------------------------------------------------------------

function optionOf(patient: PatientSummary): ComboboxOption {
  const parts = [
    `Dossier ${patient.record_number}`,
    formatAge(patientAge(patient.birth_date, clinicToday())),
    patient.phone_primary ?? 'téléphone non renseigné',
  ]
  if (patient.archived) parts.push('Fiche archivée')
  if (patient.merged_into_patient_id !== null) parts.push('Fiche fusionnée')
  return {
    value: patient.id,
    label: patientDisplayName(patient.last_name, patient.first_names),
    description: parts.join(' · '),
  }
}

/** Recherche serveur du composant de choix : indisponible = dit tel quel, jamais « aucun résultat ». */
async function searchForPatients(query: string): Promise<ComboboxSearchResult> {
  try {
    const { data } = await searchPatients(query)
    searchForbidden.value = false
    for (const patient of data.data) known.set(patient.id, patient)
    const options = data.data.map(optionOf)
    return data.meta.total > data.data.length
      ? {
          options,
          notice: `${data.meta.total} fiches correspondent : précisez la recherche pour voir les autres.`,
        }
      : options
  } catch (error) {
    if (error instanceof ApiError) {
      searchForbidden.value = error.status === 403
      if (error.status === 401 || error.status === 419) {
        void revalidateSession().catch(() => undefined)
      }
    }
    throw error
  }
}

const unavailableText = computed(() =>
  searchForbidden.value
    ? 'Ce compte n’a pas le droit de rechercher des patients.'
    : 'Recherche serveur indisponible : cela ne prouve pas que le patient n’existe pas. Réessayez avant de créer une nouvelle fiche.',
)

function onSelect(option: ComboboxOption | null): void {
  selected.value = option === null ? null : (known.get(option.value) ?? null)
}

const selectedLabel = computed(() =>
  selected.value === null
    ? null
    : patientDisplayName(selected.value.last_name, selected.value.first_names),
)

// Patient pré-choisi depuis sa fiche (`?patient=<identifiant>`) : relu sur le serveur, jamais cru sur parole.
const requested = route.query['patient']
const requestedText = typeof requested === 'string' && requested !== '' ? requested : null
const wanted = requestedText !== null && isUlid(requestedText) ? requestedText : null
/** `?patient=` présent mais illisible : dit, et la recherche reste ouverte. */
const requestedInvalid = requested !== undefined && wanted === null
const preRead = wanted === null ? null : usePatientRead<Patient>(() => getPatient(wanted))

if (preRead !== null) {
  watch(
    preRead.data,
    (patient) => {
      if (patient === null || patient.id !== wanted) return
      known.set(patient.id, patient)
      // La personne qui a déjà choisi un autre patient garde son choix.
      if (patientId.value !== null) return
      patientId.value = patient.id
      selected.value = patient
      preselectedId.value = patient.id
    },
    { immediate: true },
  )
}

// --- Autres arrivées du même jour ----------------------------------------------------------------

/** File du jour lue à l'ouverture : sert à signaler une autre visite du même patient, sans rien bloquer. */
const dayRead = useServerRead<DayVisits>(() => listDayVisits(clinicToday().toString()))

const sameDay = computed(() => {
  const patient = selected.value
  const day = dayRead.data.value
  return patient === null || day === null ? [] : existingVisits(day.visits, patient.id)
})

/** Vrai quand la file du jour n'a pas pu être lue en entier : l'absence d'autre visite n'est pas établie. */
const dayUnknown = computed(() => {
  if (selected.value === null || sameDay.value.length > 0) return false
  const readState = dayRead.state.value
  if (readState === 'loading') return false
  return readState !== 'ready' || dayRead.data.value?.truncated === true
})

const selectedAnnouncement = computed(() =>
  selected.value === null ? '' : `Patient choisi : ${selectedLabel.value ?? ''}.`,
)

// --- Enregistrement -------------------------------------------------------------------------------

/** Dernière tentative : une reprise de la même saisie garde son heure d'arrivée (même charge, même clé). */
let lastAttempt: { signature: string; at: string } | null = null
/** Issues où la commande a pu être appliquée ou n'est pas partie : la même saisie se rejoue à l'identique. */
const REPLAYABLE = new Set([
  'uncertain',
  'unavailable',
  'retryable',
  'session-expired',
  'reauthentication-required',
])

function applyServerFailure(error: ApiError): void {
  clearFeedback()
  const byField: ArrivalErrors = {}
  for (const [key, messages] of Object.entries(error.fields)) {
    const message = messages[0]
    if (message === undefined) continue
    const field = arrivalFieldFromServer(key)
    if (field !== null) byField[field] = message
    else if (key === 'visit_id') alreadyRecorded.value = true
    else if (key === 'occurred_at') {
      notes.value.push(`${message} Vérifiez la date et l’heure de cet appareil.`)
    } else notes.value.push(message)
  }
  errors.value = byField
  if (Object.keys(byField).length === 0 && notes.value.length === 0 && !alreadyRecorded.value) {
    general.value = error.message
  }
}

async function afterOutcome(result: MutationOutcome): Promise<void> {
  switch (result.kind) {
    case 'synced': {
      saved.value = true
      const patient = selected.value
      setArrivalFlash(
        patient === null
          ? 'Arrivée enregistrée.'
          : `Arrivée enregistrée : ${patientDisplayName(patient.last_name, patient.first_names)}`,
      )
      await router.push({ name: 'aujourdhui' })
      return
    }
    case 'saved-local':
      // Commit local durable (poste préparé, O04) : rien n'est dit « synchronisé ».
      saved.value = true
      return
    case 'refused':
      applyServerFailure(result.error)
      await summaryBox.value?.focus()
      return
    case 'session-expired':
    case 'reauthentication-required':
      void revalidateSession().catch(() => undefined)
      return
    default:
      return
  }
}

async function submit(): Promise<void> {
  if (busy.value) return
  alreadyRecorded.value = false
  clearFeedback()
  const checked = validateArrival({
    patientId: patientId.value,
    reason: reason.value,
    note: note.value,
  })
  if (!checked.ok || patientProblem.value !== null) {
    const found: ArrivalErrors = checked.ok ? {} : checked.errors
    if (patientProblem.value !== null && found.patient === undefined) {
      found.patient = patientProblem.value
    }
    errors.value = found
    await summaryBox.value?.focus()
    return
  }
  const values = checked.values
  const signature = JSON.stringify([values.patient_id, values.reason, values.administrative_note])
  let at: string
  if (lastAttempt !== null && lastAttempt.signature === signature && REPLAYABLE.has(state.value)) {
    at = lastAttempt.at
  } else {
    try {
      at = currentInstant()
    } catch {
      general.value =
        'L’heure de cet appareil n’est pas valide : corrigez la date et l’heure de l’appareil avant d’enregistrer.'
      await summaryBox.value?.focus()
      return
    }
  }
  lastAttempt = { signature, at }
  const payload = buildRegisterVisitPayload(values, { visitId: mutation.effectId('visit_id') }, at)
  await afterOutcome(await mutation.submit({ name: 'RegisterVisit', payload }))
}

async function retry(): Promise<void> {
  if (busy.value) return
  await afterOutcome(await mutation.retry())
}
</script>

<template>
  <section aria-labelledby="titre-arrivee" class="max-w-3xl space-y-6">
    <div class="space-y-1">
      <h1 id="titre-arrivee" class="text-2xl font-semibold tracking-tight">
        Enregistrer une arrivée
      </h1>
      <p class="max-w-prose text-muted">
        Choisissez le patient et le motif de sa venue. L’heure d’arrivée est celle de
        l’enregistrement. Notez ici des informations d’accueil seulement : aucune information
        médicale.
      </p>
    </div>

    <ErrorSummary ref="summaryBox" :errors="summary" />

    <StateMessage
      v-if="alreadyRecorded"
      state="conflict"
      title="Cette arrivée est peut-être déjà enregistrée"
    >
      {{ ALREADY_RECORDED }}
      <template #action>
        <LinkButton :to="{ name: 'aujourdhui' }" variant="secondary"
          >Ouvrir la file du jour</LinkButton
        >
      </template>
    </StateMessage>

    <StateMessage v-if="failure" :state="failure.state" :title="failure.title">
      {{ failureMessage }}
      <template v-if="failure.retry" #action>
        <AppButton variant="secondary" :pending="busy" @click="retry"
          >Réessayer l’enregistrement</AppButton
        >
      </template>
    </StateMessage>

    <p v-if="requestedInvalid" role="status" class="font-medium text-ink">
      <span aria-hidden="true">⚠ </span>Le patient indiqué dans l’adresse n’est pas valide :
      cherchez-le ci-dessous.
    </p>
    <ReadStateMessage
      v-if="
        preRead !== null && preRead.state.value !== 'ready' && preRead.state.value !== 'loading'
      "
      :state="preRead.state.value"
      subject="record"
      :error="preRead.error.value"
      @retry="preRead.reload()"
    />

    <form class="space-y-6" novalidate @submit.prevent="submit">
      <div class="space-y-3">
        <ComboboxField
          v-model="patientId"
          :control-id="FIELD_IDS.patient"
          label="Patient"
          required
          :selected-label="selectedLabel"
          :search="searchForPatients"
          :unavailable-text="unavailableText"
          :error="patientError"
          :disabled="busy"
          hint="Nom, prénom, téléphone ou numéro de dossier (par exemple EE-2026-0001). Au moins 2 caractères."
          @select="onSelect"
        />
        <p class="sr-only" role="status" aria-live="polite">{{ selectedAnnouncement }}</p>
        <PatientIdentityHeader v-if="selected" :patient="selected" />
        <p v-if="canCreatePatient" class="text-sm text-muted">
          Le patient n’est pas dans la liste ?
          <RouterLink
            to="/patients/nouveau"
            class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
            >Créer une fiche patient</RouterLink
          >
        </p>
        <p
          v-if="sameDay.length > 0"
          role="status"
          class="rounded-lg border-2 border-warning bg-warning-soft p-3 text-ink"
        >
          <span aria-hidden="true">⚠ </span>Ce patient a déjà
          {{
            sameDay.length === 1
              ? 'une arrivée enregistrée'
              : `${sameDay.length} arrivées enregistrées`
          }}
          aujourd’hui :
          <template v-for="(visit, index) in sameDay" :key="visit.id"
            ><template v-if="index > 0">, </template>{{ reasonLabel(visit.reason) }} à
            {{ formatTimeFr(visit.occurred_at) }} ({{ statusLabel(visit.status) }})</template
          >. Vous pouvez enregistrer une autre arrivée, par exemple un achat après l’examen, mais
          vérifiez qu’il ne s’agit pas d’un doublon.
        </p>
        <p v-else-if="dayUnknown" role="status" class="text-sm text-muted">
          <span aria-hidden="true">○ </span>La file du jour n’a pas pu être lue en entier : une
          autre arrivée du même patient ne peut pas être signalée.
        </p>
      </div>

      <div class="space-y-2">
        <SelectField
          v-model="reasonValue"
          :control-id="FIELD_IDS.reason"
          label="Motif de la visite"
          required
          placeholder="Choisir le motif"
          :options="reasonOptions"
          :error="errors.reason ?? null"
          :disabled="busy"
        />
        <p v-if="reason" class="text-sm text-muted">
          {{ reasonHint(reason, examFeeControlledOnScreen()) }}
        </p>
      </div>

      <TextAreaField
        v-model="note"
        :control-id="FIELD_IDS.note"
        label="Note administrative (facultative)"
        :maxlength="NOTE_MAX_LENGTH"
        hint="Information d’accueil seulement, par exemple « accompagné d’un parent ». Aucune information médicale."
        :error="errors.note ?? null"
        :disabled="busy"
      />

      <div class="flex flex-wrap items-center gap-3">
        <AppButton type="submit" :pending="busy" pending-label="Enregistrement en cours…"
          >Enregistrer l’arrivée</AppButton
        >
        <LinkButton :to="{ name: 'aujourdhui' }" variant="secondary">Annuler</LinkButton>
        <SaveStatus :state="saveState" :at="recordedAt" :time-zone="timeZone" />
      </div>
    </form>
  </section>
</template>
