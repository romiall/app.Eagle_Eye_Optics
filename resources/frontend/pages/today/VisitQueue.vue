<script setup lang="ts">
/**
 * File du jour (P03 ; fichier 02 : « File patients » ; fichier 04 : états chargement, vide, hors
 * ligne, erreur). Une instance lit UNE page d'UN jour : l'écran la recrée pour chaque jour ou page, si
 * bien qu'une réponse tardive d'un autre jour ne peut jamais s'afficher à sa place. « Aucune visite ce
 * jour » n'est dit que si le serveur a répondu à CET appel (`useRead` : état « vide ») ; sans réponse,
 * c'est « File du jour indisponible ». Identité administrative seulement : aucune donnée clinique.
 *
 * Cartes sur téléphone, tableau à partir de 1024 px : aucun défilement horizontal. Le marqueur du
 * paiement de l'examen reprend exactement le statut du serveur (`exam_fee_control`) : « non contrôlé »
 * tant que le contrôle est inactif ; contrôle actif, « à encaisser », « dispense demandée », « encaissés :
 * reçu n° … » ou « dispense approuvée », jamais « payé » ni « gratuit ». Frais à encaisser : le compte qui
 * peut demander une approbation propose une dispense à la gérante (`ExamFeeWaiverAction`).
 *
 * Examen (C01) : « Démarrer l'examen », « Reprendre l'examen » ou « Consulter l'examen » par visite,
 * selon les droits clinical.write et clinical.read, le module « consultations » ouvert et l'état de la
 * visite (`ExamAction`). Un refus d'état au démarrage (409) est dit au-dessus de la liste, qui est relue.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { clinicTimeZone, clinicToday, formatTimeFr } from '@/app/clinicTime'
import { listVisits } from '@/app/services/visits'
import { can, canOnScreen, enabledModules, moduleOpenOnScreen } from '@/app/session'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import ExamFeeMarker from '@/components/visits/ExamFeeMarker.vue'
import type { Visit, VisitPage } from '@/domain/contracts/generated'
import { formatAge, patientAge, patientDisplayName } from '@/domain/patients/identity'
import { reasonLabel, statusLabel, statusSymbol } from '@/domain/patients/visits'

import ExamAction from './ExamAction.vue'
import ExamFeeWaiverAction from './ExamFeeWaiverAction.vue'
import QueueStateMessage from './QueueStateMessage.vue'
import { useServerRead } from './useServerRead'

const props = withDefaults(
  defineProps<{
    /** Jour civil du cabinet `AAAA-MM-JJ`. */
    date: string
    page: number
    /** Titre de la liste (« File du jour : jeudi 22 octobre 2026 »). */
    title: string
    /** Vrai après un changement de page : le focus va au titre une fois la page chargée. */
    focusOnLoad?: boolean
  }>(),
  { focusOnLoad: false },
)

const emit = defineEmits<{ page: [page: number]; summary: [text: string] }>()

const read = useServerRead<VisitPage>(() => listVisits(props.date, props.page), {
  isEmpty: (result) => result.data.length === 0,
})

const heading = ref<HTMLElement | null>(null)
const today = clinicToday()
const timeZone = clinicTimeZone()

const result = computed(() => read.data.value)
const total = computed(() => result.value?.meta.total ?? 0)
/** Vide « certain » mais le serveur annonce des visites : la page demandée n'existe plus. */
const pastTheEnd = computed(() => read.state.value === 'empty' && total.value > 0)
/** La fiche s'ouvre pour qui peut lire les patients, quand ce module est ouvert. */
const canOpenPatient = computed(
  () => can('patient.read') && enabledModules.value.includes('patients'),
)

/**
 * La colonne ou la ligne « examen » existe pour qui peut lire les consultations (clinical.read : sans
 * lui, ni reprendre ni démarrer un examen), module « consultations » ouvert.
 */
const showExam = computed(() => moduleOpenOnScreen('consultations') && canOnScreen('clinical.read'))
/**
 * « Créer une facture » (B02) depuis une visite : droit invoice.create ou exam_fee.collect, module
 * « sales » ouvert. Le patient et la visite passent par leurs identifiants opaques (rien n'est
 * ressaisi) ; une visite annulée n'en propose pas.
 */
const canInvoice = computed(
  () =>
    (canOnScreen('invoice.create') || canOnScreen('exam_fee.collect')) &&
    moduleOpenOnScreen('sales'),
)

function invoiceLink(visit: Visit) {
  return { name: 'facture-nouvelle', query: { patient: visit.patient.id, visite: visit.id } }
}
/** Refus d'état au démarrage d'un examen : dit au-dessus de la liste, qui est alors relue. */
const staleNotice = ref<string | null>(null)

function onStale(message: string): void {
  staleNotice.value = message
  void read.reload()
}

/** Demande de dispense envoyée (P03 avec B06) : dite au-dessus de la liste, qui est relue (marqueur à jour). */
const requestNotice = ref<string | null>(null)

function onRequested(message: string): void {
  requestNotice.value = message
  void read.reload()
}

function announcement(): string {
  switch (read.state.value) {
    case 'ready': {
      const meta = result.value?.meta
      const count = meta?.total === 1 ? '1 visite' : `${meta?.total ?? 0} visites`
      return meta !== undefined && meta.last_page > 1
        ? `${count}, page ${meta.current_page} sur ${meta.last_page}`
        : count
    }
    case 'empty':
      return pastTheEnd.value ? 'Cette page ne contient plus de visites' : 'Aucune visite ce jour'
    case 'offline':
      return 'File du jour indisponible'
    case 'forbidden':
      return 'Droits insuffisants'
    case 'session-expired':
      return 'Session expirée'
    case 'error':
      return 'La file du jour n’a pas pu être lue'
    default:
      return ''
  }
}

watch(
  read.state,
  async (state) => {
    if (state === 'loading') return
    emit('summary', announcement())
    if (props.focusOnLoad) {
      await nextTick()
      heading.value?.focus()
    }
  },
  { immediate: true },
)

function nameOf(visit: Visit): string {
  return patientDisplayName(visit.patient.last_name, visit.patient.first_names)
}

function identityLine(visit: Visit): string {
  return `Dossier ${visit.patient.record_number} · ${formatAge(patientAge(visit.patient.birth_date, today))}`
}

const LINK_CLASS =
  'inline-flex min-h-11 items-center font-semibold break-words text-primary underline underline-offset-4'
</script>

<template>
  <section aria-labelledby="titre-file" class="space-y-4">
    <h2
      id="titre-file"
      ref="heading"
      tabindex="-1"
      class="text-lg font-semibold focus:outline-none"
    >
      {{ props.title }}
    </h2>

    <StateMessage
      v-if="staleNotice"
      state="conflict"
      title="La visite a changé depuis l’affichage de la file"
    >
      {{ staleNotice }}
    </StateMessage>

    <StateMessage v-if="requestNotice" state="success" title="Demande envoyée à la gérante">
      {{ requestNotice }}
    </StateMessage>

    <QueueStateMessage
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <StateMessage
      v-else-if="pastTheEnd"
      state="empty"
      title="Cette page ne contient plus de visites"
    >
      La file a changé depuis l’affichage précédent.
      <template #action>
        <AppButton variant="secondary" @click="emit('page', 1)"
          >Revenir à la première page</AppButton
        >
      </template>
    </StateMessage>

    <StateMessage
      v-else-if="read.state.value === 'empty'"
      state="empty"
      title="Aucune visite ce jour"
    >
      Le serveur n’a enregistré aucune arrivée pour ce jour.
    </StateMessage>

    <template v-else-if="result !== null">
      <ul id="file-cartes" class="space-y-3 lg:hidden">
        <li
          v-for="visit in result.data"
          :key="visit.id"
          class="space-y-2 rounded-lg border-2 border-line bg-surface p-3"
        >
          <p class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span class="text-lg font-semibold tabular-nums"
              ><span class="sr-only">Arrivée à </span>{{ formatTimeFr(visit.occurred_at) }}</span
            >
            <span class="text-base font-medium"
              ><span aria-hidden="true">{{ statusSymbol(visit.status) }}</span
              ><span class="sr-only">Statut :</span> {{ statusLabel(visit.status) }}</span
            >
          </p>
          <p class="space-y-0.5">
            <RouterLink
              v-if="canOpenPatient"
              :to="{ name: 'patient', params: { id: visit.patient.id } }"
              :class="LINK_CLASS"
              >{{ nameOf(visit) }}</RouterLink
            >
            <span v-else class="block font-semibold break-words text-ink">{{ nameOf(visit) }}</span>
            <span class="block text-sm break-words text-muted">{{ identityLine(visit) }}</span>
          </p>
          <p class="text-base">
            <span class="sr-only">Motif : </span>{{ reasonLabel(visit.reason) }}
          </p>
          <p>
            <ExamFeeMarker
              :control="visit.exam_fee_control"
              :receipt-number="visit.exam_fee_receipt_number ?? null"
            />
          </p>
          <ExamFeeWaiverAction :visit="visit" @requested="onRequested" />
          <ExamAction v-if="showExam" :visit="visit" @stale="onStale" />
          <LinkButton
            v-if="canInvoice && visit.status !== 'CANCELLED'"
            :to="invoiceLink(visit)"
            variant="secondary"
            :accessible-name="`Créer une facture pour ${nameOf(visit)}`"
            >Créer une facture</LinkButton
          >
          <p
            v-if="visit.administrative_note"
            class="text-sm break-words whitespace-pre-line text-muted"
          >
            Note : {{ visit.administrative_note }}
          </p>
        </li>
      </ul>

      <table id="file-tableau" class="hidden w-full border-collapse text-left lg:table">
        <caption class="sr-only">
          {{
            props.title
          }}
        </caption>
        <thead>
          <tr class="border-b-2 border-line text-sm text-muted">
            <th scope="col" class="px-3 py-2 font-medium">Arrivée</th>
            <th scope="col" class="px-3 py-2 font-medium">Patient</th>
            <th scope="col" class="px-3 py-2 font-medium">Motif</th>
            <th scope="col" class="px-3 py-2 font-medium">Statut</th>
            <th scope="col" class="px-3 py-2 font-medium">Paiement de l’examen</th>
            <th v-if="showExam" scope="col" class="px-3 py-2 font-medium">Examen</th>
            <th v-if="canInvoice" scope="col" class="px-3 py-2 font-medium">Facturation</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="visit in result.data" :key="visit.id" class="border-b border-line align-top">
            <td class="px-3 py-2 font-semibold tabular-nums">
              {{ formatTimeFr(visit.occurred_at) }}
            </td>
            <td class="px-3 py-2 break-words">
              <RouterLink
                v-if="canOpenPatient"
                :to="{ name: 'patient', params: { id: visit.patient.id } }"
                :class="LINK_CLASS"
                >{{ nameOf(visit) }}</RouterLink
              >
              <span v-else class="block font-semibold text-ink">{{ nameOf(visit) }}</span>
              <span class="block text-sm text-muted">{{ identityLine(visit) }}</span>
              <span
                v-if="visit.administrative_note"
                class="block text-sm break-words whitespace-pre-line text-muted"
                >Note : {{ visit.administrative_note }}</span
              >
            </td>
            <td class="px-3 py-2">{{ reasonLabel(visit.reason) }}</td>
            <td class="px-3 py-2">
              <span aria-hidden="true">{{ statusSymbol(visit.status) }}</span>
              {{ statusLabel(visit.status) }}
            </td>
            <td class="space-y-2 px-3 py-2">
              <ExamFeeMarker
                :control="visit.exam_fee_control"
                :receipt-number="visit.exam_fee_receipt_number ?? null"
              />
              <ExamFeeWaiverAction :visit="visit" @requested="onRequested" />
            </td>
            <td v-if="showExam" class="px-3 py-2">
              <ExamAction :visit="visit" @stale="onStale" />
            </td>
            <td v-if="canInvoice" class="px-3 py-2">
              <LinkButton
                v-if="visit.status !== 'CANCELLED'"
                :to="invoiceLink(visit)"
                variant="secondary"
                :accessible-name="`Créer une facture pour ${nameOf(visit)}`"
                >Créer une facture</LinkButton
              >
            </td>
          </tr>
        </tbody>
      </table>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        label="Pagination de la file du jour"
        unit="visites"
        @page="emit('page', $event)"
      />

      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>
  </section>
</template>
