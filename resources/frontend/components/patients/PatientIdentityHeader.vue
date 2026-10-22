<script setup lang="ts">
/**
 * En-tête d'identité d'un patient (fichier 04 : « en-tête patient ») : nom, numéro de dossier, sexe
 * et âge calculé à la date civile du cabinet (« âge non renseigné » sans naissance), avec l'état de
 * la fiche (archivée, fusionnée) en texte et en symbole. Identité administrative seulement : aucune
 * donnée clinique n'est affichée ici, ce composant peut donc servir à la caisse.
 */
import { computed } from 'vue'

import { clinicToday } from '@/app/clinicTime'
import { formatAge, patientAge, patientDisplayName, sexLabel } from '@/domain/patients/identity'
import type { PatientRecordsSex } from '@/domain/contracts/generated'

export interface HeaderPatient {
  last_name: string
  first_names: string | null
  record_number: string
  sex: PatientRecordsSex
  birth_date: string | null
  archived: boolean
  merged_into_patient_id: string | null
}

const props = withDefaults(
  defineProps<{ patient: HeaderPatient; as?: 'h1' | 'h2' | 'p'; titleId?: string | undefined }>(),
  { as: 'p', titleId: undefined },
)

const name = computed(() => patientDisplayName(props.patient.last_name, props.patient.first_names))
const age = computed(() => formatAge(patientAge(props.patient.birth_date, clinicToday())))
</script>

<template>
  <header class="space-y-1">
    <component
      :is="props.as"
      :id="props.titleId"
      class="text-2xl font-semibold tracking-tight break-words"
      >{{ name }}</component
    >
    <p class="text-base text-muted">
      Dossier <strong class="font-semibold text-ink">{{ props.patient.record_number }}</strong>
      <span aria-hidden="true"> · </span><span class="sr-only">, </span
      >{{ sexLabel(props.patient.sex) }}<span aria-hidden="true"> · </span
      ><span class="sr-only">, </span>{{ age }}
    </p>
    <p
      v-if="props.patient.archived"
      class="inline-flex items-center gap-1 rounded-lg border-2 border-warning bg-warning-soft px-2 py-1 text-sm font-medium text-ink"
    >
      <span aria-hidden="true">⚠</span>Fiche archivée
    </p>
    <p
      v-if="props.patient.merged_into_patient_id !== null"
      class="inline-flex items-center gap-1 rounded-lg border-2 border-warning bg-warning-soft px-2 py-1 text-sm font-medium text-ink"
    >
      <span aria-hidden="true">⚠</span>Fiche fusionnée dans une autre fiche
    </p>
  </header>
</template>
