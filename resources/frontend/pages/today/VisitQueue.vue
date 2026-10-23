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
 *
 * Présentation (charte du logo) : compteurs par état de la page lue (dits « sur cette page » quand la
 * file en compte plusieurs, jamais extrapolés), cartes au filet coloré par statut avec initiales,
 * heure, motif, puces du statut et du paiement de l'examen, puis les actions (principale pleine,
 * secondaires discrètes). L'ordre des visites reste celui du serveur.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { clinicTimeZone, clinicToday, formatTimeFr } from '@/app/clinicTime'
import { listVisits } from '@/app/services/visits'
import { can, canOnScreen, enabledModules, moduleOpenOnScreen } from '@/app/session'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import StatTile from '@/components/layout/StatTile.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import PatientInitials from '@/components/patients/PatientInitials.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import ExamFeeMarker from '@/components/visits/ExamFeeMarker.vue'
import { reasonIcon, visitStatusView } from '@/components/visits/visitPresentation'
import type { Visit, VisitPage, VisitsStatus } from '@/domain/contracts/generated'
import { formatAge, patientAge, patientDisplayName } from '@/domain/patients/identity'
import { reasonLabel, statusLabel } from '@/domain/patients/visits'

import CancelVisitAction from './CancelVisitAction.vue'
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

/** Statuts d'une visite encore attendue (avant l'examen ou le passage en caisse). */
const WAITING: readonly VisitsStatus[] = ['ARRIVED', 'AWAITING_EXAM_FEE', 'READY_FOR_EXAM']

/**
 * Compteurs par état des visites de la page lue (statuts du serveur, présentation seule). Quand la
 * file compte plusieurs pages, chaque compteur le dit (« Sur cette page ») : rien n'est extrapolé.
 */
const counts = computed(() => {
  const visits = result.value?.data ?? []
  const among = (statuses: readonly VisitsStatus[]): number =>
    visits.filter((visit) => statuses.includes(visit.status)).length
  return {
    waiting: among(WAITING),
    inConsultation: among(['IN_CONSULTATION']),
    completed: among(['COMPLETED']),
    cancelled: among(['CANCELLED']),
  }
})
const multiPage = computed(() => (result.value?.meta.last_page ?? 1) > 1)
const pageHint = computed(() => (multiPage.value ? 'Sur cette page' : undefined))
/** Visites annulées comprises dans le total : dit seulement quand toute la file est lue. */
const totalHint = computed(() => {
  const cancelled = counts.value.cancelled
  if (multiPage.value || cancelled === 0) return undefined
  return cancelled === 1 ? 'Dont 1 annulée' : `Dont ${cancelled} annulées`
})

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

/** Visite annulée (P03) : dite au-dessus de la liste, qui est relue (la visite y reste, marquée annulée). */
const cancelNotice = ref<string | null>(null)

function onCancelled(message: string): void {
  cancelNotice.value = message
  void read.reload()
}

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
  'inline-flex min-h-11 items-center font-semibold break-words text-primary underline underline-offset-4 hover:decoration-gold hover:decoration-2'
</script>

<template>
  <section aria-labelledby="titre-file" class="space-y-4 sm:space-y-5">
    <div class="flex items-center gap-3">
      <div
        aria-hidden="true"
        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gold-soft text-gold-ink"
      >
        <AppIcon name="calendar" :size="20" />
      </div>
      <h2
        id="titre-file"
        ref="heading"
        tabindex="-1"
        class="min-w-0 text-lg font-semibold break-words focus:outline-none sm:text-xl"
      >
        {{ props.title }}
      </h2>
    </div>

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

    <StateMessage v-if="cancelNotice" state="success" title="Visite annulée">
      {{ cancelNotice }}
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

    <EmptyState
      v-else-if="read.state.value === 'empty'"
      title="Aucune visite ce jour"
      icon="arrival"
    >
      Le serveur n’a enregistré aucune arrivée pour ce jour.
    </EmptyState>

    <template v-else-if="result !== null">
      <dl class="stat-grid sm:grid-cols-2 lg:grid-cols-4">
        <StatTile
          label="Visites enregistrées"
          :value="result.meta.total"
          icon="users"
          tone="gold"
          :hint="totalHint"
        />
        <StatTile label="En attente" :value="counts.waiting" icon="clock" :hint="pageHint" />
        <StatTile
          label="En consultation"
          :value="counts.inConsultation"
          icon="stethoscope"
          :hint="pageHint"
        />
        <StatTile
          label="Terminées"
          :value="counts.completed"
          icon="check-circle"
          tone="success"
          :hint="pageHint"
        />
      </dl>

      <ul id="file-cartes" class="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:hidden">
        <li
          v-for="visit in result.data"
          :key="visit.id"
          :class="[
            'card flex min-w-0 flex-col gap-3 border-l-4 p-4',
            visitStatusView(visit.status).accent,
            visitStatusView(visit.status).quiet ? 'bg-canvas shadow-none' : '',
          ]"
        >
          <div class="flex items-start gap-3">
            <PatientInitials
              :last-name="visit.patient.last_name"
              :first-names="visit.patient.first_names"
            />
            <p class="min-w-0 flex-1">
              <RouterLink
                v-if="canOpenPatient"
                :to="{ name: 'patient', params: { id: visit.patient.id } }"
                :class="LINK_CLASS"
                >{{ nameOf(visit) }}</RouterLink
              >
              <span v-else class="block font-semibold break-words text-ink">{{
                nameOf(visit)
              }}</span>
              <span class="block text-sm break-words text-muted">{{ identityLine(visit) }}</span>
            </p>
            <p class="flex shrink-0 items-center gap-1 text-lg font-semibold tabular-nums">
              <AppIcon name="clock" :size="16" class="text-muted" />
              <span class="sr-only">Arrivée à</span> {{ formatTimeFr(visit.occurred_at) }}
            </p>
          </div>

          <p class="flex items-center gap-2 font-medium text-ink">
            <AppIcon :name="reasonIcon(visit.reason)" :size="18" class="text-muted" />
            <span><span class="sr-only">Motif : </span>{{ reasonLabel(visit.reason) }}</span>
          </p>

          <div class="flex flex-wrap items-center gap-2">
            <StatusChip
              :tone="visitStatusView(visit.status).tone"
              :icon="visitStatusView(visit.status).icon"
              ><span class="sr-only">Statut : </span>{{ statusLabel(visit.status) }}</StatusChip
            >
            <ExamFeeMarker
              :control="visit.exam_fee_control"
              :receipt-number="visit.exam_fee_receipt_number ?? null"
            />
          </div>

          <p
            v-if="visit.administrative_note"
            class="rounded-lg bg-canvas px-3 py-2 text-sm break-words whitespace-pre-line text-muted"
          >
            Note : {{ visit.administrative_note }}
          </p>

          <div
            class="mt-auto flex flex-wrap items-start gap-2 border-t border-line pt-3 empty:hidden"
          >
            <ExamFeeWaiverAction :visit="visit" @requested="onRequested" />
            <ExamAction v-if="showExam" :visit="visit" @stale="onStale" />
            <LinkButton
              v-if="canInvoice && visit.status !== 'CANCELLED'"
              :to="invoiceLink(visit)"
              variant="secondary"
              :accessible-name="`Créer une facture pour ${nameOf(visit)}`"
              >Créer une facture</LinkButton
            >
            <CancelVisitAction
              class="ml-auto"
              :visit="visit"
              @cancelled="onCancelled"
              @stale="onStale"
            />
          </div>
        </li>
      </ul>

      <div class="data-table-wrap hidden lg:block">
        <table id="file-tableau" class="data-table hidden lg:table">
          <caption class="sr-only">
            {{
              props.title
            }}
          </caption>
          <thead>
            <tr>
              <th scope="col">Arrivée</th>
              <th scope="col">Patient</th>
              <th scope="col">Motif</th>
              <th scope="col">Statut</th>
              <th scope="col">Paiement de l’examen</th>
              <th v-if="showExam" scope="col">Examen</th>
              <th v-if="canInvoice" scope="col">Facturation</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="visit in result.data" :key="visit.id">
              <td class="font-semibold whitespace-nowrap tabular-nums">
                {{ formatTimeFr(visit.occurred_at) }}
              </td>
              <td class="break-words">
                <div class="flex items-start gap-3">
                  <PatientInitials
                    size="sm"
                    class="mt-0.5"
                    :last-name="visit.patient.last_name"
                    :first-names="visit.patient.first_names"
                  />
                  <div class="min-w-0">
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
                  </div>
                </div>
              </td>
              <td>
                <span class="inline-flex items-center gap-2">
                  <AppIcon :name="reasonIcon(visit.reason)" :size="18" class="text-muted" />
                  {{ reasonLabel(visit.reason) }}
                </span>
              </td>
              <td class="space-y-2">
                <StatusChip
                  as="p"
                  :tone="visitStatusView(visit.status).tone"
                  :icon="visitStatusView(visit.status).icon"
                  >{{ statusLabel(visit.status) }}</StatusChip
                >
                <CancelVisitAction :visit="visit" @cancelled="onCancelled" @stale="onStale" />
              </td>
              <td class="space-y-2">
                <ExamFeeMarker
                  :control="visit.exam_fee_control"
                  :receipt-number="visit.exam_fee_receipt_number ?? null"
                />
                <ExamFeeWaiverAction :visit="visit" @requested="onRequested" />
              </td>
              <td v-if="showExam">
                <ExamAction :visit="visit" @stale="onStale" />
              </td>
              <td v-if="canInvoice">
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
      </div>

      <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
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
      </div>
    </template>
  </section>
</template>
