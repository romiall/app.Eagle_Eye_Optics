<script setup lang="ts">
/**
 * Fiche d'un patient (P01, P02 ; fichier 02 : identité commune à la clinique et à la vente ;
 * fichier 04 : en-tête d'identité). Identité administrative seulement : aucune donnée clinique
 * (consultations, ordonnances, prix d'achat) n'est lue ni affichée ici. La fraîcheur dit à quelle
 * heure le serveur a répondu ; sans réponse, la fiche est « indisponible », jamais une ancienne copie
 * présentée comme actuelle. « Modifier » n'apparaît qu'avec le droit patient.update ; « Enregistrer
 * une arrivée » (P03) qu'avec visit.register, module « today » ouvert, sur une fiche ni archivée ni
 * fusionnée. L'onglet « Activité » est une autre adresse (`PatientActivityPage`) : cette fiche ne lit
 * jamais l'activité (chargement du seul onglet nécessaire, fichier 04).
 */
import { computed, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { clinicTimeZone, clinicToday, formatInstantFr } from '@/app/clinicTime'
import { getPatient } from '@/app/services/patients'
import { can, enabledModules } from '@/app/session'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import { formatDateFr } from '@/components/form/format'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import type { Patient } from '@/domain/contracts/generated'
import { formatAge, patientAge, sexLabel } from '@/domain/patients/identity'

import { takeFlash } from './flash'
import PatientRecordTabs from './PatientRecordTabs.vue'
import ReadStateMessage from './ReadStateMessage.vue'
import { usePatientRead } from './usePatientRead'

const route = useRoute()
const id = computed(() => String(route.params['id']))
const flash = takeFlash()

const read = usePatientRead<Patient>(() => getPatient(id.value))

// Le même écran sert une autre fiche quand l'adresse change : relire, sans garder la précédente.
watch(id, () => void read.reload())

/** Fiche lue pour CET identifiant : une lecture précédente ne s'affiche jamais à sa place. */
const patient = computed(() => (read.data.value?.id === id.value ? read.data.value : null))
const canEdit = computed(
  () =>
    can('patient.update') &&
    patient.value !== null &&
    !patient.value.archived &&
    patient.value.merged_into_patient_id === null,
)
/**
 * « Enregistrer une arrivée » (P03) : avec le droit visit.register, quand le module « today » est
 * ouvert, et seulement pour une fiche vivante (ni archivée ni fusionnée : le serveur la refuserait).
 */
const canRegisterArrival = computed(
  () =>
    can('visit.register') &&
    enabledModules.value.includes('today') &&
    patient.value !== null &&
    !patient.value.archived &&
    patient.value.merged_into_patient_id === null,
)

/**
 * « Créer une facture » (B02) : avec invoice.create ou exam_fee.collect, module « sales » ouvert, pour
 * une fiche vivante. Le patient est présélectionné par son identifiant opaque : rien n'est ressaisi.
 */
const canInvoice = computed(
  () =>
    (can('invoice.create') || can('exam_fee.collect')) &&
    enabledModules.value.includes('sales') &&
    patient.value !== null &&
    !patient.value.archived &&
    patient.value.merged_into_patient_id === null,
)

const age = computed(() =>
  patient.value === null ? '' : formatAge(patientAge(patient.value.birth_date, clinicToday())),
)
const timeZone = clinicTimeZone()
const NOT_GIVEN = 'Non renseigné'

/** Valeur à afficher : un champ vide ou blanc est « Non renseigné », jamais une case vide. */
function shown(value: string | null): string {
  return value === null || value.trim() === '' ? NOT_GIVEN : value
}

/** Valeur absente accompagnée, quand elle existe, de la raison enregistrée de son absence. */
function absence(value: string | null, reason: string | null): string {
  if (value !== null && value.trim() !== '') return value
  return reason === null || reason.trim() === '' ? NOT_GIVEN : `${NOT_GIVEN} (raison : ${reason})`
}
</script>

<template>
  <section aria-labelledby="titre-fiche" class="max-w-3xl space-y-6">
    <StateMessage v-if="flash" state="success" :title="flash" />

    <template v-if="patient">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <PatientIdentityHeader :patient="patient" as="h1" title-id="titre-fiche" />
        <div v-if="canEdit || canRegisterArrival || canInvoice" class="flex flex-wrap gap-3">
          <LinkButton
            v-if="canRegisterArrival"
            :to="{ name: 'arrivee', query: { patient: patient.id } }"
            variant="secondary"
            >Enregistrer une arrivée</LinkButton
          >
          <LinkButton
            v-if="canInvoice"
            :to="{ name: 'facture-nouvelle', query: { patient: patient.id } }"
            variant="secondary"
            >Créer une facture</LinkButton
          >
          <LinkButton
            v-if="canEdit"
            :to="{ name: 'patient-modifier', params: { id: patient.id } }"
            variant="primary"
            >Modifier</LinkButton
          >
        </div>
      </div>

      <PatientRecordTabs :patient-id="patient.id" current="identite" />

      <ReadStateMessage
        v-if="read.state.value !== 'ready' && read.state.value !== 'loading'"
        :state="read.state.value"
        subject="record"
        :error="read.error.value"
        @retry="read.reload()"
      />

      <StateMessage
        v-if="patient.merged_into_patient_id !== null"
        state="offline"
        title="Cette fiche a été fusionnée dans une autre"
      >
        <RouterLink
          :to="{ name: 'patient', params: { id: patient.merged_into_patient_id } }"
          class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
          >Ouvrir la fiche conservée</RouterLink
        >
      </StateMessage>

      <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
        <div>
          <dt class="text-sm text-muted">Nom</dt>
          <dd class="break-words">{{ patient.last_name }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Prénom(s)</dt>
          <dd class="break-words">{{ shown(patient.first_names) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Sexe</dt>
          <dd>{{ sexLabel(patient.sex) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Date de naissance</dt>
          <dd class="break-words">
            <template v-if="patient.birth_date !== null"
              >{{ formatDateFr(patient.birth_date) }} ({{ age }})</template
            >
            <template v-else
              >{{ absence(null, patient.birth_date_missing_reason) }} ({{ age }})</template
            >
          </dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Téléphone principal</dt>
          <dd class="break-words">
            {{ absence(patient.phone_primary, patient.phone_missing_reason) }}
          </dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Téléphone secondaire</dt>
          <dd class="break-words">{{ shown(patient.phone_secondary) }}</dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-sm text-muted">Adresse ou quartier</dt>
          <dd class="break-words whitespace-pre-line">{{ shown(patient.address) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Profession</dt>
          <dd class="break-words">{{ shown(patient.profession) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Numéro de dossier</dt>
          <dd>{{ patient.record_number }}</dd>
        </div>
        <div v-if="patient.legacy_record">
          <dt class="text-sm text-muted">Ancien dossier</dt>
          <dd class="break-words">
            Repris de l’ancien classeur<template v-if="patient.legacy_record_number">
              (n° {{ patient.legacy_record_number }})</template
            ><template v-if="patient.first_known_visit_on">
              , première visite connue le {{ formatDateFr(patient.first_known_visit_on) }}</template
            >
          </dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Fiche créée le</dt>
          <dd>{{ formatInstantFr(patient.created_at) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">Dernière modification</dt>
          <dd>{{ formatInstantFr(patient.updated_at) }}</dd>
        </div>
      </dl>

      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>

    <template v-else>
      <h1 id="titre-fiche" class="text-2xl font-semibold tracking-tight">Dossier patient</h1>
      <StateMessage v-if="read.state.value === 'ready'" state="error" title="Fiche inattendue">
        Le serveur a répondu avec une autre fiche que celle demandée : rien n’est affiché.
      </StateMessage>
      <ReadStateMessage
        :state="read.state.value"
        subject="record"
        :error="read.error.value"
        @retry="read.reload()"
      />
    </template>

    <LinkButton to="/patients" variant="ghost">Retour aux patients</LinkButton>
  </section>
</template>
