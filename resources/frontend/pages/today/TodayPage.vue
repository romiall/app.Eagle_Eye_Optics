<script setup lang="ts">
/**
 * Aujourd'hui (P03 ; fichier 02 : « File patients » ; fichier 04 : écran « Aujourd'hui », AT05). La
 * file du jour des arrivées du cabinet, lue sur le serveur : jour civil du cabinet par défaut (jamais un
 * minuit UTC), jour précédent ou suivant, ou une date saisie. « Enregistrer une arrivée » n'apparaît
 * qu'avec le droit visit.register ; la file elle-même se lit avec visit.register OU agenda.read.
 *
 * Contrôle du paiement de l'examen INACTIF (défaut) : l'écran le dit en toutes lettres et n'affirme
 * jamais qu'un examen est réglé, soldé ou gratuit ; « Démarrer l'examen » (C01) n'est PAS bloqué par le
 * règlement. Contrôle ACTIF (`clinic.exam_fee_control = BILLED`, P03 avec B04 et B06) : l'écran dit que
 * l'examen ne démarre qu'avec la facture de frais de la visite encaissée ou une dispense accordée par la
 * gérante ; chaque visite porte son marqueur (voir `VisitQueue`). Un achat ou un retrait n'impose aucun
 * examen.
 */
import { computed, ref } from 'vue'

import { clinicToday, formatDayFr } from '@/app/clinicTime'
import { can, canOnScreen, examFeeControlledOnScreen, moduleOpenOnScreen } from '@/app/session'
import StateMessage from '@/components/feedback/StateMessage.vue'
import DateField from '@/components/form/DateField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import { CivilDate } from '@/domain/values/civilDate'
import { shiftDay } from '@/domain/patients/visits'

import { takeArrivalFlash } from './flash'
import VisitQueue from './VisitQueue.vue'

const flash = takeArrivalFlash()

/** Jour affiché (`AAAA-MM-JJ`) : le jour civil du cabinet à l'ouverture de l'écran. */
const day = ref(clinicToday().toString())
const page = ref(1)
/** Incrémenté pour relire la même page (actualiser, « Aujourd'hui » déjà affiché). */
const run = ref(0)
/** Remonte le champ de date quand un bouton change le jour : une saisie refusée ne reste pas affichée. */
const fieldKey = ref(0)
const focusResults = ref(false)
const announcement = ref('')

const canRegister = computed(() => can('visit.register'))
/** La mention de « Démarrer l'examen » n'a de sens que pour qui peut le faire, module ouvert (C01). */
const mentionsExamStart = computed(
  () => canOnScreen('clinical.write') && moduleOpenOnScreen('consultations'),
)
/** Le serveur contrôle réellement le paiement de l'examen (`/auth/me`). */
const examFeeControlled = computed(() => examFeeControlledOnScreen())
const queueKey = computed(() => `${day.value}\u{1F}${page.value}\u{1F}${run.value}`)
const isToday = computed(() => day.value === clinicToday().toString())
const title = computed(() => {
  const label = formatDayFr(CivilDate.fromString(day.value))
  return isToday.value ? `File du jour : ${label} (aujourd’hui)` : `File du jour : ${label}`
})

/** Affiche un autre jour (première page) ; `remountField` remet le champ de date à ce jour. */
function show(newDay: string, remountField: boolean): void {
  announcement.value = 'Chargement de la file du jour…'
  focusResults.value = false
  day.value = newDay
  page.value = 1
  run.value += 1
  if (remountField) fieldKey.value += 1
}

function shift(days: number): void {
  const next = shiftDay(day.value, days)
  if (next !== null) show(next, true)
}

function showToday(): void {
  show(clinicToday().toString(), true)
}

function refresh(): void {
  announcement.value = 'Actualisation de la file du jour…'
  focusResults.value = false
  run.value += 1
}

/** Date saisie et lue par le champ : un champ vidé ou refusé (null) ne change pas le jour affiché. */
function chooseDay(chosen: string | null): void {
  if (chosen === null || chosen === day.value) return
  show(chosen, false)
}

function goToPage(next: number): void {
  announcement.value = 'Chargement de la page…'
  focusResults.value = true
  page.value = next
}
</script>

<template>
  <section aria-labelledby="titre-aujourdhui" class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 id="titre-aujourdhui" class="text-2xl font-semibold tracking-tight">Aujourd’hui</h1>
      <LinkButton v-if="canRegister" :to="{ name: 'arrivee' }" variant="primary"
        >Enregistrer une arrivée</LinkButton
      >
    </div>

    <StateMessage v-if="flash" state="success" :title="flash" />

    <p v-if="examFeeControlled" class="max-w-prose text-muted">
      Les frais d’examen sont contrôlés : un examen de vue ou un contrôle de suivi ne démarre
      qu’avec la facture de frais de la visite encaissée, ou une dispense accordée par la gérante.
      Un achat direct ou un retrait de commande n’impose aucun examen.
    </p>
    <p v-else class="max-w-prose text-muted">
      <span aria-hidden="true">⚠ </span>Le contrôle du paiement de l’examen n’est pas encore actif.
      Une arrivée pour un examen de vue ou un contrôle de suivi n’est donc pas bloquée faute de
      règlement, et cet écran ne dit rien de l’état du règlement de l’examen.
      <template v-if="mentionsExamStart">
        Démarrer l’examen n’est pas non plus bloqué par le règlement.
      </template>
      Un achat direct ou un retrait de commande n’impose aucun examen.
    </p>

    <div
      role="group"
      aria-label="Choisir le jour affiché"
      class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end"
    >
      <AppButton variant="secondary" @click="shift(-1)">Jour précédent</AppButton>
      <AppButton variant="secondary" @click="showToday">Aujourd’hui</AppButton>
      <AppButton variant="secondary" @click="shift(1)">Jour suivant</AppButton>
      <div class="sm:w-56">
        <DateField
          :key="fieldKey"
          label="Jour affiché"
          :model-value="day"
          field="date"
          control-id="aujourdhui-jour"
          required
          @update:model-value="chooseDay"
        />
      </div>
      <AppButton variant="ghost" @click="refresh">Actualiser la file</AppButton>
    </div>

    <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

    <VisitQueue
      :key="queueKey"
      :date="day"
      :page="page"
      :title="title"
      :focus-on-load="focusResults"
      @page="goToPage"
      @summary="announcement = $event"
    />
  </section>
</template>
