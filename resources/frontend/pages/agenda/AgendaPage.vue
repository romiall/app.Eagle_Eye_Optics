<script setup lang="ts">
/**
 * Agenda interne (S01 ; fichier 02 « Agenda en vues jour et semaine ; RDV confirmé, arrivé, terminé, annulé,
 * absent »), EN LIGNE : les rendez-vous lus sur le serveur, par jour civil du cabinet, du plus tôt au plus tard, en
 * liste (lisible à 360 px, pas de grille horaire illisible). Filtre par ressource. Un rendez-vous n'est jamais dit
 * confirmé sans la réponse du serveur ; une lecture qui échoue est « indisponible », jamais « aucun rendez-vous ».
 *
 * Présentation : un jour par carte (repère « Aujourd’hui », nombre de rendez-vous), chaque rendez-vous avec sa
 * colonne d'heures et son statut en puce ; la semaine passe sur deux colonnes sur grand écran.
 */
import { computed, ref } from 'vue'

import { clinicTimeZone, clinicToday, formatDayFr } from '@/app/clinicTime'
import { getAgendaConfiguration, listAppointments } from '@/app/services/agenda'
import { canOnScreen } from '@/app/session'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import DateField from '@/components/form/DateField.vue'
import SelectField from '@/components/form/SelectField.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import { groupByDay, shownDays } from '@/domain/agenda/agenda'
import type { AgendaConfiguration, Appointment } from '@/domain/contracts/generated'
import { CivilDate } from '@/domain/values/civilDate'
import SalesReadMessage from '@/pages/sales/SalesReadMessage.vue'
import { useServerRead } from '@/pages/today/useServerRead'

import AppointmentCard from './AppointmentCard.vue'

const timeZone = clinicTimeZone()
const view = ref<'day' | 'week'>('day')
const day = ref<string | null>(clinicToday().toString())
const resourceId = ref<string | null>(null)
const flash = ref<string | null>(null)
const stale = ref<string | null>(null)

/** Jour choisi ; une saisie incomplète ou invalide (dite par le champ) laisse aujourd'hui. */
const anchor = computed(() => {
  try {
    return (day.value === null ? null : CivilDate.parse(day.value)) ?? clinicToday()
  } catch {
    return clinicToday()
  }
})
const days = computed(() => shownDays(anchor.value, view.value))

const configuration = useServerRead<AgendaConfiguration>(() => getAgendaConfiguration())
const resourceOptions = computed(() => [
  { value: '', label: 'Toutes les ressources' },
  ...(configuration.data.value?.resources ?? [])
    .filter((resource) => resource.active)
    .map((resource) => ({ value: resource.id, label: resource.name })),
])

const read = useServerRead<Appointment[]>(() => {
  const list = days.value
  return listAppointments(list[0]!.toString(), list[list.length - 1]!.toString(), {
    resourceId: resourceId.value === '' ? null : resourceId.value,
  })
})
const grouped = computed(() =>
  read.data.value === null ? [] : groupByDay(read.data.value, days.value, timeZone),
)

const title = computed(() => {
  const list = days.value
  if (list.length === 1) return `Agenda du ${formatDayFr(list[0]!)}`
  return `Agenda : semaine du ${formatDayFr(list[0]!)} au ${formatDayFr(list[list.length - 1]!)}`
})

/** Repère « Aujourd’hui » d'une carte de jour (jour civil du cabinet). */
function isToday(shownDay: CivilDate): boolean {
  return shownDay.compare(clinicToday()) === 0
}

function show(): void {
  void read.reload()
}

function shift(direction: -1 | 1): void {
  const step = view.value === 'day' ? 1 : 7
  day.value = anchor.value.addDays(direction * step).toString()
  void read.reload()
}

function today(): void {
  day.value = clinicToday().toString()
  void read.reload()
}

function setView(next: 'day' | 'week'): void {
  view.value = next
  void read.reload()
}

function changed(message: string): void {
  stale.value = null
  flash.value = message
  void read.reload()
}

function staled(message: string): void {
  flash.value = null
  stale.value = message
  void read.reload()
}
</script>

<template>
  <section aria-labelledby="titre-agenda" class="page-stack max-w-5xl">
    <PageHeader title-id="titre-agenda" :title="title" icon="agenda">
      <div class="flex flex-wrap gap-2">
        <LinkButton :to="{ name: 'suivis' }" variant="secondary">
          <AppIcon name="phone" />
          Patients à rappeler
        </LinkButton>
        <LinkButton
          v-if="canOnScreen('users.manage')"
          :to="{ name: 'agenda-configuration' }"
          variant="ghost"
        >
          <AppIcon name="settings" />
          Configuration
        </LinkButton>
      </div>
      <template v-if="canOnScreen('agenda.write')" #actions>
        <LinkButton :to="{ name: 'rendez-vous-nouveau' }" variant="primary">
          <AppIcon name="calendar-plus" />
          Nouveau rendez-vous
        </LinkButton>
      </template>
    </PageHeader>

    <StateMessage v-if="flash" state="success" :title="flash" />
    <StateMessage v-if="stale" state="conflict" :title="stale" />

    <div role="group" aria-label="Choisir les jours affichés" class="card space-y-4 p-4 sm:p-5">
      <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div class="grid grid-cols-2 gap-1 rounded-xl border border-line bg-canvas p-1">
          <AppButton variant="ghost" :pressed="view === 'day'" @click="setView('day')"
            >Jour</AppButton
          >
          <AppButton variant="ghost" :pressed="view === 'week'" @click="setView('week')"
            >Semaine</AppButton
          >
        </div>
        <div class="flex items-center gap-2">
          <AppButton variant="secondary" @click="shift(-1)">
            <AppIcon name="chevron-left" />
            <span class="max-lg:sr-only">{{
              view === 'day' ? 'Jour précédent' : 'Semaine précédente'
            }}</span>
          </AppButton>
          <AppButton variant="secondary" class="grow sm:grow-0" @click="today">
            <AppIcon name="today" />
            Aujourd’hui
          </AppButton>
          <AppButton variant="secondary" @click="shift(1)">
            <span class="max-lg:sr-only">{{
              view === 'day' ? 'Jour suivant' : 'Semaine suivante'
            }}</span>
            <AppIcon name="chevron-right" />
          </AppButton>
        </div>
      </div>
      <form class="toolbar border-t border-line pt-4" @submit.prevent="show">
        <div class="min-w-0 sm:flex-1">
          <DateField v-model="day" label="Jour" control-id="agenda-jour" />
        </div>
        <div class="min-w-0 sm:flex-1">
          <SelectField
            v-model="resourceId"
            label="Ressource"
            :options="resourceOptions"
            control-id="agenda-ressource"
          />
        </div>
        <AppButton type="submit" variant="secondary">Afficher</AppButton>
      </form>
    </div>

    <SalesReadMessage
      v-if="read.state.value !== 'ready'"
      :state="read.state.value"
      subject="l’agenda"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <template v-else>
      <div :class="view === 'week' ? 'grid gap-4 sm:gap-6 xl:grid-cols-2' : 'space-y-6'">
        <SectionCard
          v-for="group in grouped"
          :key="group.day.toString()"
          :title="formatDayFr(group.day)"
          :title-id="`agenda-jour-${group.day.toString()}`"
          icon="calendar"
        >
          <template v-if="isToday(group.day) || group.appointments.length > 0" #actions>
            <StatusChip v-if="isToday(group.day)" tone="gold" icon="today">Aujourd’hui</StatusChip>
            <StatusChip v-if="group.appointments.length > 0" tone="info"
              >{{ group.appointments.length }} rendez-vous</StatusChip
            >
          </template>
          <EmptyState
            v-if="group.appointments.length === 0 && view === 'day'"
            title="Aucun rendez-vous ce jour-là."
            icon="calendar"
            flat
          >
            Choisissez un autre jour ou une autre ressource.
          </EmptyState>
          <p
            v-else-if="group.appointments.length === 0"
            class="flex items-center gap-2 rounded-lg border border-dashed border-line bg-canvas px-4 py-3 text-muted"
          >
            <AppIcon name="calendar" class="text-gold" />
            Aucun rendez-vous ce jour-là.
          </p>
          <div v-else class="space-y-3">
            <AppointmentCard
              v-for="appointment in group.appointments"
              :key="`${appointment.id}-${appointment.version}`"
              :appointment="appointment"
              @changed="changed"
              @stale="staled"
            />
          </div>
        </SectionCard>
      </div>
      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>
  </section>
</template>
