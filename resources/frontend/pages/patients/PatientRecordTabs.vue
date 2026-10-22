<script setup lang="ts">
/**
 * Onglets du dossier patient (fichier 04 : « En-tête identité ; onglets… » ; « chargement du seul onglet
 * nécessaire ») : Identité et Activité. Chaque onglet est une adresse distincte, qui ne lit que ce qu'elle
 * montre : la fiche d'identité ne lit jamais l'activité. Ce sont des liens de navigation (le clavier, le
 * menu contextuel et les lecteurs d'écran les traitent comme tels) ; `RouterLink` pose
 * `aria-current="page"` sur l'onglet affiché. Les libellés ne nomment aucune donnée clinique ni financière.
 */
import { RouterLink } from 'vue-router'

const props = defineProps<{ patientId: string; current: 'identite' | 'activite' }>()

const TABS = [
  { key: 'identite', label: 'Identité', route: 'patient' },
  { key: 'activite', label: 'Activité', route: 'patient-activite' },
] as const

const BASE =
  'inline-flex min-h-11 items-center rounded-t-lg border-2 border-b-0 px-4 py-2 text-base font-semibold'
</script>

<template>
  <nav aria-label="Sections du dossier patient" class="border-b-2 border-line">
    <ul class="flex flex-wrap gap-2">
      <li v-for="tab in TABS" :key="tab.key">
        <RouterLink
          :to="{ name: tab.route, params: { id: props.patientId } }"
          :class="[
            BASE,
            tab.key === props.current
              ? 'border-line bg-surface text-ink'
              : 'border-transparent text-primary underline underline-offset-4 hover:bg-primary-soft',
          ]"
          >{{ tab.label }}</RouterLink
        >
      </li>
    </ul>
  </nav>
</template>
