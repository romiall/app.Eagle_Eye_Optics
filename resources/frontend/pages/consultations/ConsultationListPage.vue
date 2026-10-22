<script setup lang="ts">
/**
 * Examens du cabinet (écran « Consultations », module « consultations », `clinical.read`) : les
 * consultations du jour par défaut, ou de tous les jours, filtrables par état et par « mes examens », de la
 * plus récente à la plus ancienne, 50 par page. La liste ne montre AUCUNE valeur clinique (patient, date,
 * état, professionnel) : le dossier s'ouvre sur la consultation, dont la lecture est tracée. Une liste qui
 * n'a pas pu être lue est « indisponible », jamais « aucun examen ». Cartes plutôt qu'un tableau (360 px).
 *
 * Préréglages par l'adresse (raccourcis de l'accueil) : `?jour=tous`, `?etat=IN_PROGRESS`, `?mes=1` — des
 * filtres, jamais une donnée de santé.
 */
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { clinicTimeZone, clinicToday, formatInstantFr } from '@/app/clinicTime'
import { listConsultations } from '@/app/services/consultationList'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import ReadNotice from '@/components/feedback/ReadNotice.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import CheckboxField from '@/components/form/CheckboxField.vue'
import DateField from '@/components/form/DateField.vue'
import SelectField from '@/components/form/SelectField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import { WORK_STATUS_LABELS, workStatusLabel, workStatusSymbol } from '@/domain/clinical/labels'
import type {
  ConsultationSummary,
  ConsultationSummaryPage,
  ConsultationsStatus,
} from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import { useServerRead } from '@/pages/today/useServerRead'

const STATUSES: readonly ConsultationsStatus[] = ['DRAFT', 'IN_PROGRESS', 'COMPLETED']

const route = useRoute()
const timeZone = clinicTimeZone()
const today = clinicToday().toString()

function queryText(name: string): string | null {
  const value = route.query[name]
  return typeof value === 'string' ? value : null
}

const presetStatus = queryText('etat')
const day = ref<string | null>(queryText('jour') === 'tous' ? null : today)
const status = ref<string | null>(
  presetStatus !== null && (STATUSES as readonly string[]).includes(presetStatus) ? presetStatus : null,
)
const mine = ref(queryText('mes') === '1')
const page = ref(1)

const statusOptions = STATUSES.map((value) => ({ value, label: WORK_STATUS_LABELS[value] }))

const statusFilter = computed<ConsultationsStatus | null>(() =>
  status.value !== null && (STATUSES as readonly string[]).includes(status.value)
    ? (status.value as ConsultationsStatus)
    : null,
)

const read = useServerRead<ConsultationSummaryPage>(
  () =>
    listConsultations({
      day: day.value,
      status: statusFilter.value,
      mine: mine.value,
      page: page.value,
    }),
  { isEmpty: (result) => result.data.length === 0 },
)

watch([day, statusFilter, mine], () => {
  page.value = 1
  void read.reload()
})

function goToPage(next: number): void {
  page.value = next
  void read.reload()
}

const result = computed(() => read.data.value)

function name(consultation: ConsultationSummary): string {
  return patientDisplayName(consultation.patient.last_name, consultation.patient.first_names)
}

const emptyTitle = computed(() =>
  day.value === null ? 'Aucun examen pour ces critères' : 'Aucun examen ce jour-là pour ces critères',
)
</script>

<template>
  <section aria-labelledby="titre-examens" class="max-w-3xl space-y-6">
    <h1 id="titre-examens" class="text-2xl font-semibold tracking-tight">Consultations</h1>

    <form
      role="search"
      aria-label="Filtrer les examens"
      class="grid gap-4 sm:grid-cols-2"
      @submit.prevent
    >
      <div class="space-y-2">
        <DateField
          v-model="day"
          label="Jour"
          hint="Vide : tous les jours."
          control-id="examens-jour"
        />
        <div class="flex flex-wrap gap-2">
          <AppButton variant="ghost" @click="day = today">Aujourd’hui</AppButton>
          <AppButton variant="ghost" @click="day = null">Tous les jours</AppButton>
        </div>
      </div>
      <SelectField
        v-model="status"
        label="État"
        placeholder="Tous les états"
        :options="statusOptions"
        control-id="examens-etat"
      />
      <CheckboxField
        v-model="mine"
        label="Seulement mes examens"
        control-id="examens-miens"
        class="sm:col-span-2"
      />
    </form>

    <ReadNotice
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      subject="la liste des examens"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <StateMessage v-else-if="read.state.value === 'empty'" state="empty" :title="emptyTitle">
      Le serveur n’a aucun examen pour ces critères. Un examen se démarre depuis la file du jour.
    </StateMessage>

    <template v-else-if="result !== null">
      <ul class="space-y-3">
        <li
          v-for="consultation in result.data"
          :key="consultation.id"
          class="space-y-1 rounded-lg border-2 border-line bg-surface p-3"
        >
          <p class="flex flex-wrap items-baseline justify-between gap-x-3">
            <RouterLink
              :to="{ name: 'consultation', params: { id: consultation.id } }"
              class="inline-flex min-h-11 items-center font-semibold break-words text-primary underline underline-offset-4"
              :aria-label="`${name(consultation)}, examen du ${formatInstantFr(consultation.effective_at)}`"
              >{{ name(consultation) }}</RouterLink
            >
            <span class="text-base"
              ><span aria-hidden="true">{{ workStatusSymbol(consultation.status) }} </span
              >{{ workStatusLabel(consultation.status) }}</span
            >
          </p>
          <p class="text-sm text-muted">
            Dossier {{ consultation.patient.record_number }} · examen du
            {{ formatInstantFr(consultation.effective_at) }}
          </p>
          <p class="text-sm break-words">
            Professionnel : {{ consultation.clinician.display_name }}
            <template v-if="consultation.completed_at !== null">
              · terminé le {{ formatInstantFr(consultation.completed_at) }} (révision
              {{ consultation.current_revision }})
            </template>
          </p>
          <p v-if="consultation.status === 'COMPLETED'">
            <RouterLink
              :to="{ name: 'consultation-historique', params: { id: consultation.id } }"
              class="inline-flex min-h-11 items-center text-primary underline underline-offset-4"
              :aria-label="`Révisions de l’examen de ${name(consultation)}`"
              >Révisions</RouterLink
            >
          </p>
        </li>
      </ul>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        label="Pagination des examens"
        unit="examens"
        :busy="read.state.value === 'loading'"
        @page="goToPage"
      />

      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>
  </section>
</template>
