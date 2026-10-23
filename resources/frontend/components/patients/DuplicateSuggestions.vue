<script setup lang="ts">
/**
 * Fiches proches d'une identité en cours de saisie (fichier 02 : « signaler les ressemblances avant
 * création, sans fusion automatique » ; AT04). Chaque fiche dit pourquoi elle ressemble (nom proche,
 * même téléphone, même naissance) et s'ouvre dans un nouvel onglet pour ne pas perdre la saisie.
 * Aucune action de fusion : ni ici, ni automatique (la fusion manuelle est une amélioration L1,
 * réservée à la gérante). Un téléphone partagé est permis : il est signalé, jamais refusé. Identité
 * administrative seulement. Présentation : une carte par fiche, pastille d'initiales, puces des
 * raisons de la ressemblance et de l'état de la fiche.
 */
import { computed } from 'vue'

import { clinicToday } from '@/app/clinicTime'
import StatusChip from '@/components/layout/StatusChip.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { PatientSummary } from '@/domain/contracts/generated'
import { type DuplicateSuggestion, type SuggestionReason } from '@/domain/patients/duplicates'
import { formatAge, patientAge, patientDisplayName } from '@/domain/patients/identity'

import { formatDateFr } from '../form/format'
import PatientInitials from './PatientInitials.vue'

const props = withDefaults(
  defineProps<{ suggestions: readonly DuplicateSuggestion[]; more?: boolean }>(),
  { more: false },
)

const today = computed(() => clinicToday())

const REASON_LABELS: Readonly<Record<SuggestionReason, string>> = {
  name: 'Nom proche',
  phone: 'Même téléphone',
}

function nameOf(patient: PatientSummary): string {
  return patientDisplayName(patient.last_name, patient.first_names)
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
        class="flex gap-3 rounded-xl border border-line bg-surface p-3 sm:p-4"
      >
        <PatientInitials
          :last-name="suggestion.patient.last_name"
          :first-names="suggestion.patient.first_names"
          size="sm"
        />
        <div class="min-w-0 flex-1 space-y-1">
          <p class="font-semibold break-words">{{ nameOf(suggestion.patient) }}</p>
          <p class="text-sm break-words text-muted">{{ identityLine(suggestion.patient) }}</p>
          <p class="flex flex-wrap gap-2 pt-0.5">
            <StatusChip v-for="reason in suggestion.reasons" :key="reason" tone="info">{{
              REASON_LABELS[reason]
            }}</StatusChip>
            <StatusChip v-if="suggestion.sameBirthDate" tone="info"
              >Même date de naissance</StatusChip
            >
            <StatusChip v-if="suggestion.patient.archived" tone="warning" icon="alert"
              >Fiche archivée</StatusChip
            >
            <StatusChip
              v-if="suggestion.patient.merged_into_patient_id !== null"
              tone="warning"
              icon="alert"
              >Fiche fusionnée</StatusChip
            >
          </p>
          <a
            :href="`/patients/${encodeURIComponent(suggestion.patient.id)}`"
            target="_blank"
            rel="noopener noreferrer"
            class="text-link inline-flex min-h-11 items-center gap-1.5 font-semibold"
            >Ouvrir la fiche<span class="sr-only">
              de {{ nameOf(suggestion.patient) }} (nouvel onglet)</span
            ><AppIcon name="external-link" :size="16"
          /></a>
        </div>
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
