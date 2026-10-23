<script setup lang="ts">
/**
 * Une tâche de contact interne (S02) : qui rappeler, pourquoi administrativement (contrôle recommandé du …,
 * rendez-vous du …), par quel canal souhaité, et l'historique des tentatives. Aucun message ne part de
 * l'application (L0) : la personne appelle ou écrit elle-même, puis note le résultat (contacté, RDV pris, sans
 * réponse, refus, reporté). Version de la tâche attendue : une tâche traitée ailleurs entre-temps est dite (409).
 *
 * Présentation : statut en puce ; échéance encadrée et mise en valeur (en retard en rouge, aujourd'hui
 * en or, avec le mot écrit) et filet gauche de la même teinte ; tentatives précédentes en frise.
 */
import { computed, ref, useId } from 'vue'

import { clinicTimeZone, clinicToday, formatDayFr, formatInstantFr } from '@/app/clinicTime'
import { listAppointments } from '@/app/services/agenda'
import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { canOnScreen, revalidateSession } from '@/app/session'
import { CONTACT_STATUS_TONES, DUE_LOOKS, taskDueState } from '@/components/agenda/tones'
import DateField from '@/components/form/DateField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import {
  CHANNEL_LABELS,
  CONTACT_RESULT_LABELS,
  CONTACT_STATUS_LABELS,
  dayOf,
  isOpenTask,
  NEXT_STATUSES,
  nextAttemptRequired,
} from '@/domain/agenda/agenda'
import type {
  Appointment,
  ContactAttemptsResult,
  ContactTask,
  ContactTasksStatus,
} from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import { CivilDate } from '@/domain/values/civilDate'
import { localInstant } from '@/pages/agenda/localTime'

const props = defineProps<{ task: ContactTask }>()
const emit = defineEmits<{ recorded: [message: string]; stale: [message: string] }>()

const ids = useId()
const timeZone = clinicTimeZone()
const mutation = useMutation()
const saving = computed(() => mutation.state.value === 'saving')
const error = ref<string | null>(null)
const open = ref(false)
const result = ref<string | null>(null)
const next = ref<string | null>(null)
const nextAttempt = ref('')
const nextDay = ref<string | null>(null)
const note = ref('')
const appointmentId = ref<string | null>(null)
const upcoming = ref<Appointment[]>([])

const name = computed(() =>
  props.task.patient === null
    ? 'Patient'
    : patientDisplayName(props.task.patient.last_name, props.task.patient.first_names),
)
const source = computed(() => {
  if (props.task.source_type === 'FOLLOW_UP' && props.task.source_date !== null) {
    return `Contrôle recommandé du ${formatDayFr(CivilDate.fromString(props.task.source_date))}`
  }
  if (props.task.source_type === 'APPOINTMENT' && props.task.source_starts_at !== null) {
    return `Rendez-vous du ${formatInstantFr(props.task.source_starts_at)}`
  }
  return 'Relance'
})
/** Échéance d'une tâche ouverte : en retard, aujourd'hui ou plus tard (mise en valeur seulement). */
const due = computed(() => taskDueState(props.task, clinicToday(), timeZone))
const writable = computed(() => canOnScreen('agenda.write') && isOpenTask(props.task.status))
const resultOptions = (Object.keys(CONTACT_RESULT_LABELS) as ContactAttemptsResult[]).map(
  (value) => ({
    value,
    label: CONTACT_RESULT_LABELS[value],
  }),
)
const nextOptions = computed(() =>
  result.value === null
    ? []
    : NEXT_STATUSES[result.value as ContactAttemptsResult].map((value) => ({
        value,
        label: CONTACT_STATUS_LABELS[value],
      })),
)
const appointmentOptions = computed(() =>
  upcoming.value.map((appointment) => ({
    value: appointment.id,
    label: `${formatInstantFr(appointment.starts_at)} · ${appointment.service_name}`,
  })),
)

async function chooseResult(value: string | null): Promise<void> {
  result.value = value
  const options = value === null ? [] : NEXT_STATUSES[value as ContactAttemptsResult]
  next.value = options.length === 1 ? options[0]! : null
  if (value === 'BOOKED' && props.task.patient_id !== null && upcoming.value.length === 0) {
    const today = dayOf(new Date().toISOString(), timeZone)
    try {
      upcoming.value = (
        await listAppointments(today.toString(), today.addDays(30).toString(), {
          patientId: props.task.patient_id,
        })
      ).data.filter(
        (appointment) =>
          appointment.status === 'CONFIRMED' || appointment.status === 'PENDING_CONFIRMATION',
      )
    } catch {
      upcoming.value = []
    }
  }
}

async function afterOutcome(outcome: MutationOutcome): Promise<void> {
  switch (outcome.kind) {
    case 'synced':
      open.value = false
      mutation.reset()
      emit('recorded', `Tentative pour ${name.value} enregistrée sur le serveur.`)
      return
    case 'conflict':
      emit('stale', `La tâche de ${name.value} a changé entre-temps : la liste a été relue.`)
      return
    case 'refused':
      if (outcome.error.status === 409) {
        emit('stale', `${outcome.error.message} La liste a été relue.`)
        return
      }
      error.value = Object.values(outcome.error.fields)[0]?.[0] ?? outcome.error.message
      return
    case 'session-expired':
    case 'reauthentication-required':
      void revalidateSession().catch(() => undefined)
      error.value = mutation.failureMessage.value
      return
    default:
      error.value = mutation.failureMessage.value
  }
}

async function save(): Promise<void> {
  if (saving.value) return
  error.value = null
  if (result.value === null || next.value === null) {
    error.value = 'Choisissez le résultat et la suite.'
    return
  }
  if (result.value === 'BOOKED' && appointmentId.value === null) {
    error.value = 'Choisissez le rendez-vous pris (ou programmez-le d’abord dans l’agenda).'
    return
  }
  let nextAt: string | null = null
  if (nextDay.value !== null && nextDay.value !== '') {
    let parsedDay: CivilDate | null
    try {
      parsedDay = CivilDate.parse(nextDay.value)
    } catch {
      parsedDay = null
    }
    nextAt =
      parsedDay === null
        ? null
        : localInstant(parsedDay, nextAttempt.value === '' ? '09:00' : nextAttempt.value)
    if (nextAt === null) {
      error.value = 'Jour ou heure de la nouvelle tentative invalide (heure HH:MM).'
      return
    }
  }
  if (nextAttemptRequired(next.value as ContactTasksStatus) && nextAt === null) {
    error.value = 'Indiquez quand rappeler.'
    return
  }
  const text = note.value.trim()
  await afterOutcome(
    await mutation.submit({
      name: 'RecordContactAttempt',
      path: { id: props.task.id },
      payload: {
        attempt_id: mutation.effectId('attempt_id'),
        task_id: props.task.id,
        occurred_at: new Date().toISOString(),
        result: result.value,
        appointment_id: result.value === 'BOOKED' ? appointmentId.value : null,
        administrative_note: text === '' ? null : text,
        next_status: next.value,
        next_attempt_at: nextAt,
      },
      expectedVersion: props.task.version,
    }),
  )
}
</script>

<template>
  <article
    :aria-labelledby="`${ids}-titre`"
    :class="[
      'flex flex-col gap-4 rounded-xl border border-l-4 border-line bg-surface p-4 shadow-card sm:p-5',
      DUE_LOOKS[due].rail,
    ]"
  >
    <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
      <div class="min-w-0 flex-1 space-y-1">
        <h3 :id="`${ids}-titre`" class="text-lg leading-snug font-semibold break-words">
          {{ name }}
          <span v-if="props.task.patient" class="text-base font-normal text-muted"
            >· Dossier {{ props.task.patient.record_number }}</span
          >
        </h3>
        <p class="text-sm text-muted break-words">{{ source }}</p>
      </div>
      <StatusChip as="p" :tone="CONTACT_STATUS_TONES[props.task.status]">{{
        CONTACT_STATUS_LABELS[props.task.status]
      }}</StatusChip>
    </header>

    <dl class="grid gap-3 sm:grid-cols-2">
      <div :class="['rounded-lg border px-3 py-2', DUE_LOOKS[due].box]">
        <dt :class="['text-sm font-medium', DUE_LOOKS[due].label]">Échéance</dt>
        <dd class="flex flex-wrap items-center gap-2 font-semibold text-ink tabular-nums">
          {{ formatInstantFr(props.task.due_at) }}
          <StatusChip v-if="due === 'overdue'" tone="danger" icon="alert">En retard</StatusChip>
          <StatusChip v-else-if="due === 'today'" tone="gold" icon="today">Aujourd’hui</StatusChip>
        </dd>
      </div>
      <div class="rounded-lg border border-line bg-canvas px-3 py-2">
        <dt class="text-sm font-medium text-muted">Canal souhaité</dt>
        <dd class="font-semibold break-words text-ink">{{ CHANNEL_LABELS[props.task.channel] }}</dd>
      </div>
      <div
        v-if="props.task.next_attempt_at"
        class="rounded-lg border border-line bg-canvas px-3 py-2 sm:col-span-2"
      >
        <dt class="text-sm font-medium text-muted">Nouvelle tentative</dt>
        <dd class="font-semibold text-ink tabular-nums">
          {{ formatInstantFr(props.task.next_attempt_at) }}
        </dd>
      </div>
    </dl>

    <div v-if="props.task.attempts.length > 0" class="space-y-2">
      <h4 class="text-sm font-semibold text-ink">Tentatives précédentes</h4>
      <ul class="space-y-2 border-l-2 border-line pl-4">
        <li
          v-for="attempt in props.task.attempts"
          :key="attempt.id"
          class="relative text-sm break-words"
        >
          <span
            aria-hidden="true"
            class="absolute top-1.25 -left-5.5 size-2.5 rounded-full border-2 border-surface bg-gold"
          ></span>
          <span class="font-medium tabular-nums">{{ formatInstantFr(attempt.occurred_at) }}</span> :
          {{ CONTACT_RESULT_LABELS[attempt.result] }}
          <template v-if="attempt.actor_name"> ({{ attempt.actor_name }})</template>
          <template v-if="attempt.administrative_note">
            · {{ attempt.administrative_note }}</template
          >
        </li>
      </ul>
    </div>

    <div
      v-if="writable && (!open || props.task.patient_id)"
      class="flex flex-wrap gap-2 border-t border-line pt-4"
    >
      <AppButton v-if="!open" variant="secondary" @click="open = true">
        <AppIcon name="edit" />
        Noter un contact
      </AppButton>
      <LinkButton
        v-if="props.task.patient_id"
        :to="{
          name: 'rendez-vous-nouveau',
          query: {
            patient: props.task.patient_id,
            ...(props.task.follow_up_id ? { suivi: props.task.follow_up_id } : {}),
          },
        }"
        variant="ghost"
      >
        <AppIcon name="calendar-plus" />
        Programmer un rendez-vous
      </LinkButton>
    </div>

    <form
      v-if="open"
      class="space-y-4 rounded-xl border border-line bg-canvas p-4"
      novalidate
      @submit.prevent="save"
    >
      <div class="form-grid">
        <SelectField
          :model-value="result"
          label="Résultat"
          placeholder="Choisir…"
          :options="resultOptions"
          :control-id="`${ids}-resultat`"
          @update:model-value="chooseResult"
        />
        <SelectField
          v-if="nextOptions.length > 1"
          v-model="next"
          label="Suite"
          placeholder="Choisir…"
          :options="nextOptions"
          :control-id="`${ids}-suite`"
        />
        <SelectField
          v-if="result === 'BOOKED'"
          v-model="appointmentId"
          class="col-span-full"
          label="Rendez-vous pris"
          placeholder="Choisir le rendez-vous…"
          :options="appointmentOptions"
          hint="Rendez-vous confirmés des 30 prochains jours."
          :control-id="`${ids}-rdv`"
        />
        <template v-if="next === 'POSTPONED' || next === 'NO_RESPONSE' || next === 'PENDING'">
          <DateField
            v-model="nextDay"
            :label="next === 'POSTPONED' ? 'Rappeler le (obligatoire)' : 'Rappeler le (facultatif)'"
            :control-id="`${ids}-quand`"
          />
          <TextField
            v-model="nextAttempt"
            label="Heure"
            placeholder="09:00"
            :control-id="`${ids}-heure`"
          />
        </template>
        <TextAreaField
          v-model="note"
          class="col-span-full"
          label="Note administrative (facultative)"
          hint="Aucune information médicale."
          :control-id="`${ids}-note`"
          :rows="2"
          :maxlength="4000"
        />
      </div>
      <p v-if="error" role="alert" class="text-sm font-medium text-danger">
        <span aria-hidden="true">⚠ </span>{{ error }}
      </p>
      <div class="form-actions">
        <AppButton
          type="submit"
          variant="primary"
          :pending="saving"
          pending-label="Enregistrement…"
        >
          <AppIcon name="check" />
          Enregistrer la tentative
        </AppButton>
        <AppButton variant="ghost" :disabled="saving" @click="open = false">Annuler</AppButton>
      </div>
    </form>
  </article>
</template>
