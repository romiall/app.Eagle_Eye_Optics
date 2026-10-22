<script setup lang="ts">
/**
 * Onglet « Activité » du dossier patient (fichier 02 : historique jamais écrasé ; fichier 04 : en-tête
 * d'identité, onglets, chargement du seul onglet nécessaire). L'en-tête relit l'identité de base ; la liste
 * (`PatientActivityList`) lit l'activité, du plus récent au plus ancien, avec les seules sections que le
 * compte peut voir. Lecture en ligne : sans réponse du serveur, l'écran le dit (jamais un dossier vide).
 */
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'

import { getPatient } from '@/app/services/patients'
import StateMessage from '@/components/feedback/StateMessage.vue'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import type { Patient } from '@/domain/contracts/generated'

import PatientActivityList from './PatientActivityList.vue'
import PatientRecordTabs from './PatientRecordTabs.vue'
import ReadStateMessage from './ReadStateMessage.vue'
import { usePatientRead } from './usePatientRead'

const route = useRoute()
const id = computed(() => String(route.params['id']))

const read = usePatientRead<Patient>(() => getPatient(id.value))

// Le même écran sert une autre fiche quand l'adresse change : relire, sans garder la précédente.
watch(id, () => void read.reload())

/** Fiche lue pour CET identifiant : une lecture précédente ne s'affiche jamais à sa place. */
const patient = computed(() => (read.data.value?.id === id.value ? read.data.value : null))
</script>

<template>
  <section aria-labelledby="titre-fiche" class="max-w-3xl space-y-6">
    <template v-if="patient">
      <PatientIdentityHeader :patient="patient" as="h1" title-id="titre-fiche" />
      <PatientRecordTabs :patient-id="patient.id" current="activite" />
      <PatientActivityList :key="patient.id" :patient-id="patient.id" />
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
