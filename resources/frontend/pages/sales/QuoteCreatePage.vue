<script setup lang="ts">
/**
 * Nouveau devis (L1D07). Le destinataire est un patient du cabinet (présélectionné depuis sa fiche par
 * `?patient=<identifiant>`, ou choisi par recherche : son identité est relue sur le serveur, jamais ressaisie) OU un
 * prospect qui n'a pas encore de dossier (nom et téléphone seulement ; aucune donnée clinique). Une fiche archivée ou
 * fusionnée ne reçoit pas de devis. Le prospect rejoindra un dossier patient explicitement, à la conversion.
 */
import { computed, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { getPatient, searchPatients } from '@/app/services/patients'
import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import StateMessage from '@/components/feedback/StateMessage.vue'
import ComboboxField, {
  type ComboboxOption,
  type ComboboxSearchResult,
} from '@/components/form/ComboboxField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextField from '@/components/form/TextField.vue'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import AppButton from '@/components/ui/AppButton.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import type { QuoteRecipientInput } from '@/domain/billing/quote'
import type { Patient, Quote } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import { isUlid } from '@/domain/values/ulid'

import QuoteEditor from './QuoteEditor.vue'

const route = useRoute()
const router = useRouter()

const preselected = (() => {
  const value = route.query['patient']
  return typeof value === 'string' && isUlid(value) ? value : null
})()

const canSearch = computed(() => canOnScreen('patient.read') && moduleOpenOnScreen('patients'))
const kind = ref<string | null>(preselected === null ? null : 'PATIENT')
const kindOptions = [
  { value: 'PATIENT', label: 'Un patient du cabinet' },
  { value: 'PROSPECT', label: 'Un prospect sans dossier' },
]

const patientId = ref<string | null>(preselected)
const patient = shallowRef<Patient | null>(null)
const loading = ref(false)
const problem = ref<string | null>(null)

watch(
  patientId,
  async (id) => {
    patient.value = null
    problem.value = null
    if (id === null) return
    loading.value = true
    try {
      const result = await getPatient(id)
      if (result.data.id !== id) {
        problem.value =
          'Le serveur a répondu avec une autre fiche que celle demandée : rien n’est affiché.'
      } else {
        patient.value = result.data
      }
    } catch {
      problem.value =
        'La fiche du patient n’a pas pu être lue (serveur indisponible, droits ou fiche inconnue) : aucun devis ne peut être préparé sans son dossier.'
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

const living = computed(
  () =>
    patient.value !== null &&
    !patient.value.archived &&
    patient.value.merged_into_patient_id === null,
)

async function search(query: string): Promise<ComboboxSearchResult> {
  const result = await searchPatients(query)
  const options: ComboboxOption[] = result.data.data
    .filter((summary) => !summary.archived && summary.merged_into_patient_id === null)
    .map((summary) => ({
      value: summary.id,
      label: patientDisplayName(summary.last_name, summary.first_names),
      description: `Dossier ${summary.record_number}`,
    }))
  return result.data.meta.last_page > 1
    ? { options, notice: 'D’autres patients correspondent : précisez la recherche.' }
    : options
}

// --- Prospect ----------------------------------------------------------------------------------

const prospectName = ref('')
const prospectPhone = ref('')
const prospectConfirmed = ref<{ name: string; phone: string | null } | null>(null)
const prospectError = ref<string | null>(null)

function confirmProspect(): void {
  const name = prospectName.value.trim()
  if (name.length < 1) {
    prospectError.value = 'Le nom du prospect est obligatoire.'
    return
  }
  prospectError.value = null
  const phone = prospectPhone.value.trim()
  prospectConfirmed.value = { name, phone: phone === '' ? null : phone }
}

const recipient = computed<QuoteRecipientInput | null>(() => {
  if (
    kind.value === 'PATIENT' &&
    patient.value !== null &&
    living.value &&
    patientId.value !== null
  ) {
    return { kind: 'PATIENT', patientId: patientId.value }
  }
  if (kind.value === 'PROSPECT' && prospectConfirmed.value !== null) {
    return { kind: 'PROSPECT', ...prospectConfirmed.value }
  }
  return null
})

async function onCreated(quote: Quote): Promise<void> {
  await router.replace({ name: 'devis-detail', params: { id: quote.id } })
}
</script>

<template>
  <section aria-labelledby="titre-nouveau-devis" class="max-w-3xl space-y-6">
    <h1 id="titre-nouveau-devis" class="text-2xl font-semibold tracking-tight">Nouveau devis</h1>
    <p class="text-base text-muted">
      Un devis ne vaut pas facture : il ne crée ni créance, ni encaissement, ni réservation de
      stock.
    </p>

    <SelectField
      v-if="preselected === null"
      v-model="kind"
      label="Destinataire du devis"
      placeholder="Choisir…"
      :options="kindOptions"
      control-id="devis-destinataire"
      required
    />

    <template v-if="kind === 'PATIENT'">
      <template v-if="patientId === null">
        <ComboboxField
          v-if="canSearch"
          v-model="patientId"
          label="Patient"
          hint="Nom, téléphone ou numéro de dossier (2 caractères au moins) : l’identité est reprise de la fiche, jamais ressaisie."
          :search="search"
          control-id="devis-patient"
          required
          unavailable-text="Recherche serveur indisponible : cela ne prouve pas que le patient n’existe pas."
        />
        <StateMessage v-else state="forbidden" title="Choix du patient impossible">
          Ce compte ne peut pas rechercher les patients : ouvrez le devis depuis la fiche du
          patient, ou établissez-le pour un prospect.
        </StateMessage>
      </template>
      <StateMessage v-if="loading" state="loading" title="Lecture de la fiche du patient…" />
      <StateMessage v-if="problem" state="offline" title="Fiche du patient non lue">
        {{ problem }}
      </StateMessage>
      <PatientIdentityHeader v-if="patient" :patient="patient" />
      <StateMessage v-if="patient && !living" state="error" title="Fiche archivée ou fusionnée">
        Cette fiche ne reçoit plus de nouveau devis. Ouvrez la fiche conservée.
      </StateMessage>
    </template>

    <template v-if="kind === 'PROSPECT'">
      <form
        v-if="prospectConfirmed === null"
        novalidate
        aria-label="Prospect"
        class="space-y-4"
        @submit.prevent="confirmProspect"
      >
        <TextField
          v-model="prospectName"
          label="Nom du prospect"
          hint="Personne sans dossier patient : nom seulement, aucune donnée clinique."
          :maxlength="180"
          control-id="devis-prospect-nom"
          :error="prospectError"
          required
        />
        <TextField
          v-model="prospectPhone"
          label="Téléphone (facultatif)"
          type="tel"
          inputmode="tel"
          :maxlength="32"
          control-id="devis-prospect-telephone"
        />
        <AppButton type="submit" variant="secondary">Continuer avec ce prospect</AppButton>
      </form>
      <p v-else class="text-base break-words">
        Prospect : <strong>{{ prospectConfirmed.name }}</strong
        ><template v-if="prospectConfirmed.phone"> · {{ prospectConfirmed.phone }}</template>
        <AppButton variant="ghost" class="ml-2" @click="prospectConfirmed = null"
          >Modifier</AppButton
        >
      </p>
    </template>

    <QuoteEditor
      v-if="recipient"
      :key="JSON.stringify(recipient)"
      :recipient="recipient"
      @created="onCreated"
    />

    <LinkButton :to="{ name: 'devis' }" variant="ghost">Retour aux devis</LinkButton>
  </section>
</template>
