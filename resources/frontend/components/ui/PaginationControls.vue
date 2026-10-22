<script setup lang="ts">
/**
 * Pagination serveur (fichier 04 : « pagination serveur »). Dit la page et le total en toutes
 * lettres, « Page précédente » et « Page suivante » sont de vrais boutons de 44 px, désactivés aux
 * bornes et pendant une lecture. Rien n'est affiché pour une seule page. Le composant ne lit rien :
 * il demande une page à l'écran, qui la lit.
 */
import { computed } from 'vue'

import AppButton from './AppButton.vue'

const props = withDefaults(
  defineProps<{
    currentPage: number
    lastPage: number
    total: number
    busy?: boolean
    label?: string
    /** Ce que le total dénombre : « résultats » (recherche), « visites » (file du jour). */
    unit?: string
  }>(),
  { busy: false, label: 'Pagination des résultats', unit: 'résultats' },
)

const emit = defineEmits<{ page: [page: number] }>()

const hasPrevious = computed(() => props.currentPage > 1)
const hasNext = computed(() => props.currentPage < props.lastPage)
</script>

<template>
  <nav v-if="props.lastPage > 1" :aria-label="props.label" class="space-y-2">
    <p class="text-sm text-muted">
      Page {{ props.currentPage }} sur {{ props.lastPage }} · {{ props.total }} {{ props.unit }}
    </p>
    <div class="flex flex-wrap gap-3">
      <AppButton
        variant="secondary"
        :disabled="!hasPrevious || props.busy"
        @click="emit('page', props.currentPage - 1)"
        >Page précédente</AppButton
      >
      <AppButton
        variant="secondary"
        :disabled="!hasNext || props.busy"
        @click="emit('page', props.currentPage + 1)"
        >Page suivante</AppButton
      >
    </div>
  </nav>
</template>
