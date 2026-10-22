<script setup lang="ts">
/**
 * Liste des factures (B02, B03) : les plus récentes d'abord, 25 par page, avec le reste à encaisser
 * projeté par le serveur et l'identité de base du patient de chaque facture (dossier, nom, prénoms).
 * Filtres, tous appliqués PAR LE SERVEUR sur toutes les pages : état (brouillons, émises, abandonnés),
 * patient (`?patient=<identifiant>` depuis sa fiche) et jour d'émission (jour civil du cabinet, jamais un
 * minuit UTC). Une liste qui n'a pas pu être lue est « indisponible », jamais « aucune facture ». Cartes
 * plutôt qu'un tableau large : rien ne déborde à 360 px.
 */
import { computed, ref, shallowRef, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { clinicTimeZone, formatInstantFr } from '@/app/clinicTime'
import { listInvoices } from '@/app/services/billing'
import { getPatient } from '@/app/services/patients'
import { canOnScreen } from '@/app/session'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import DateField from '@/components/form/DateField.vue'
import SelectField from '@/components/form/SelectField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import type { InvoicePage, InvoiceSummary, Patient } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import { isUlid } from '@/domain/values/ulid'
import { useServerRead } from '@/pages/today/useServerRead'

import { formatMoney, invoiceStatusLabel, invoiceStatusSymbol } from './format'
import SalesReadMessage from './SalesReadMessage.vue'

type StatusFilter = 'DRAFT' | 'ISSUED' | 'ABANDONED' | null

const route = useRoute()
const timeZone = clinicTimeZone()

const presetPatient = (() => {
  const value = route.query['patient']
  return typeof value === 'string' && isUlid(value) ? value : null
})()

const status = ref<string | null>(null)
const page = ref(1)
const patientId = ref<string | null>(presetPatient)
const issuedOn = ref<string | null>(null)
const patient = shallowRef<Patient | null>(null)

const statusFilter = computed<StatusFilter>(() =>
  status.value === 'DRAFT' || status.value === 'ISSUED' || status.value === 'ABANDONED'
    ? status.value
    : null,
)
const statusOptions = [
  { value: 'DRAFT', label: 'Brouillons' },
  { value: 'ISSUED', label: 'Émises' },
  { value: 'ABANDONED', label: 'Brouillons abandonnés' },
]

/** Jour d'émission : un jour civil complet, sinon aucun filtre (le champ dit lui-même une saisie invalide). */
const dayFilter = computed(() =>
  issuedOn.value !== null && /^\d{4}-\d{2}-\d{2}$/.test(issuedOn.value) ? issuedOn.value : null,
)

const read = useServerRead<InvoicePage>(
  () =>
    listInvoices({
      status: statusFilter.value,
      patientId: patientId.value,
      issuedOn: dayFilter.value,
      page: page.value,
    }),
  { isEmpty: (result) => result.data.length === 0 },
)

watch([statusFilter, patientId, dayFilter], () => {
  page.value = 1
  void read.reload()
})

watch(
  patientId,
  async (id) => {
    patient.value = null
    if (id === null || !canOnScreen('patient.read')) return
    try {
      const result = await getPatient(id)
      if (result.data.id === id) patient.value = result.data
    } catch {
      patient.value = null
    }
  },
  { immediate: true },
)

function goToPage(next: number): void {
  page.value = next
  void read.reload()
}

const canCreate = computed(() => canOnScreen('invoice.create') || canOnScreen('exam_fee.collect'))
const result = computed(() => read.data.value)
const shown = computed(() => result.value?.data ?? [])

/** Patient d'une ligne : identité de base rendue par le serveur (jamais une donnée clinique). */
function rowPatient(invoice: InvoiceSummary): string | null {
  if (invoice.patient === null) return null
  return `${patientDisplayName(invoice.patient.last_name, invoice.patient.first_names)} · dossier ${invoice.patient.record_number}`
}

const newInvoiceLink = computed(() =>
  patientId.value === null
    ? { name: 'facture-nouvelle' }
    : { name: 'facture-nouvelle', query: { patient: patientId.value } },
)

function clearPatient(): void {
  patientId.value = null
}
</script>

<template>
  <section aria-labelledby="titre-factures" class="max-w-3xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <h1 id="titre-factures" class="text-2xl font-semibold tracking-tight">Factures</h1>
      <LinkButton v-if="canCreate" :to="newInvoiceLink" variant="primary"
        >Nouvelle facture</LinkButton
      >
    </div>

    <form
      role="search"
      aria-label="Filtrer les factures"
      class="grid gap-4 sm:grid-cols-2"
      @submit.prevent
    >
      <SelectField
        v-model="status"
        label="État"
        placeholder="Tous les états"
        :options="statusOptions"
        control-id="factures-etat"
      />
      <DateField
        v-model="issuedOn"
        label="Jour d’émission"
        hint="Factures émises ce jour-là (jour du cabinet), sur toutes les pages."
        control-id="factures-date"
      />
      <div v-if="patientId" class="space-y-2 sm:col-span-2">
        <p class="text-base break-words">
          Patient :
          <strong>{{
            patient === null
              ? 'dossier sélectionné'
              : `${patientDisplayName(patient.last_name, patient.first_names)} (dossier ${patient.record_number})`
          }}</strong>
        </p>
        <AppButton variant="ghost" @click="clearPatient">Tous les patients</AppButton>
      </div>
    </form>

    <SalesReadMessage
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      subject="les factures"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <StateMessage v-else-if="read.state.value === 'empty'" state="empty" title="Aucune facture">
      Le serveur n’a aucune facture pour ces critères.
    </StateMessage>

    <template v-else-if="result !== null">
      <ul class="space-y-3">
        <li
          v-for="invoice in shown"
          :key="invoice.id"
          class="space-y-1 rounded-lg border-2 border-line bg-surface p-3"
        >
          <p class="flex flex-wrap items-baseline justify-between gap-x-3">
            <RouterLink
              :to="{ name: 'facture', params: { id: invoice.id } }"
              class="inline-flex min-h-11 items-center font-semibold break-words text-primary underline underline-offset-4"
              >{{ invoice.number ?? 'Brouillon sans numéro' }}</RouterLink
            >
            <span class="text-base"
              ><span aria-hidden="true">{{ invoiceStatusSymbol(invoice.status) }}</span>
              {{ invoiceStatusLabel(invoice.status) }}</span
            >
          </p>
          <p v-if="rowPatient(invoice)" class="text-base break-words">{{ rowPatient(invoice) }}</p>
          <p class="text-sm text-muted">
            {{
              invoice.issued_at === null
                ? 'Non émise'
                : `Émise le ${formatInstantFr(invoice.issued_at)}`
            }}
          </p>
          <p class="text-base">
            Total : <span class="tabular-nums">{{ formatMoney(invoice.total_gross) }}</span>
            <template v-if="invoice.status === 'ISSUED'">
              · reste à encaisser :
              <strong class="tabular-nums">{{
                formatMoney(Math.max(0, invoice.balance_due))
              }}</strong>
            </template>
          </p>
        </li>
      </ul>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        label="Pagination des factures"
        unit="factures"
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
