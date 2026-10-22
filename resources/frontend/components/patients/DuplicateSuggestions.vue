<script setup lang="ts">
/**
 * Fiches proches d'une identité en cours de saisie (fichier 02 : « signaler les ressemblances avant
 * création, sans fusion automatique » ; AT04). Chaque fiche dit pourquoi elle ressemble (nom proche,
 * même téléphone, même naissance) et s'ouvre dans un nouvel onglet pour ne pas perdre la saisie.
 * Aucune action de fusion : ni ici, ni automatique (la fusion manuelle est une amélioration L1,
 * réservée à la gérante). Un téléphone partagé est permis : il est signalé, jamais refusé. Identité
 * administrative seulement.
 */
import { computed } from 'vue'

import { clinicToday } from '@/app/clinicTime'
import type { PatientSummary } from '@/domain/contracts/generated'
import { type DuplicateSuggestion, type SuggestionReason } from '@/domain/patients/duplicates'
import { formatAge, patientAge, patientDisplayName } from '@/domain/patients/identity'

import { formatDateFr } from '../form/format'

const props = withDefaults(
  defineProps<{ suggestions: readonly DuplicateSuggestion[]; more?: boolean }>(),
  { more: false },
)

const today = computed(() => clinicToday())

const REASON_LABELS: Readonly<Record<SuggestionReason, string>> = {
  name: 'Nom proche',
  phone: 'Même téléphone',
}

function identityLine(patient: PatientSummary): string {
  const parts = [
    `Dossier ${patient.record_number}`,
    formatAge(patientAge(patient.birth_date, today.value)),
  ]
  if (patient.birth_date !== null) parts.push(`né(e) le ${formatDateFr(patient.birth_date)}`)
  parts.push(patient.phone_primary ?? 'téléphone non renseigné')
  return parts.join(' · ')
}

const sharedPhoneOnly = computed(() =>
  props.suggestions.some(
    (suggestion) => suggestion.reasons.length === 1 && suggestion.reasons[0] === 'phone',
  ),
)
</script>

<template>
  <div class="space-y-3">
    <ul class="space-y-3">
      <li
        v-for="suggestion in props.suggestions"
        :key="suggestion.patient.id"
        class="space-y-1 rounded-lg border-2 border-line bg-surface p-3"
      >
        <p class="font-semibold break-words">
          {{ patientDisplayName(suggestion.patient.last_name, suggestion.patient.first_names) }}
        </p>
        <p class="text-sm break-words text-muted">{{ identityLine(suggestion.patient) }}</p>
        <p class="flex flex-wrap gap-2 text-sm font-medium">
          <span
            v-for="reason in suggestion.reasons"
            :key="reason"
            class="rounded-lg border-2 border-primary bg-primary-soft px-2 py-0.5 text-ink"
            ><span aria-hidden="true">≈ </span>{{ REASON_LABELS[reason] }}</span
          >
          <span
            v-if="suggestion.sameBirthDate"
            class="rounded-lg border-2 border-primary bg-primary-soft px-2 py-0.5 text-ink"
            ><span aria-hidden="true">≈ </span>Même date de naissance</span
          >
          <span
            v-if="suggestion.patient.archived"
            class="rounded-lg border-2 border-warning bg-warning-soft px-2 py-0.5 text-ink"
            ><span aria-hidden="true">⚠ </span>Fiche archivée</span
          >
          <span
            v-if="suggestion.patient.merged_into_patient_id !== null"
            class="rounded-lg border-2 border-warning bg-warning-soft px-2 py-0.5 text-ink"
            ><span aria-hidden="true">⚠ </span>Fiche fusionnée</span
          >
        </p>
        <a
          :href="`/patients/${encodeURIComponent(suggestion.patient.id)}`"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
          >Ouvrir la fiche<span class="sr-only">
            de
            {{ patientDisplayName(suggestion.patient.last_name, suggestion.patient.first_names) }}
            (nouvel onglet)</span
          ></a
        >
      </li>
    </ul>
    <p v-if="props.more" class="text-sm text-muted">
      D’autres fiches proches existent : affinez la recherche dans la liste des patients pour les
      voir.
    </p>
    <p v-if="sharedPhoneOnly" class="text-sm text-muted">
      Un même téléphone peut servir à plusieurs patients (famille, proches) : ce n’est pas un refus.
    </p>
    <p class="text-sm text-muted">
      Aucune fiche n’est fusionnée ni modifiée par cette vérification.
    </p>
  </div>
</template>
