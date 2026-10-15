<script setup lang="ts">
/**
 * États d'écran du fichier 04 : chargement, vide, succès, erreur, conflit de version, droits
 * insuffisants et connexion indisponible. Un résultat absent hors ligne est « Recherche serveur
 * indisponible », jamais « aucun dossier » : le message dit ce qui est su, pas davantage. Chaque
 * état a un symbole et un titre : la couleur n'est jamais le seul signal.
 */
import { computed } from 'vue'

export type ScreenState =
  'loading' | 'empty' | 'success' | 'error' | 'conflict' | 'forbidden' | 'offline'

const props = defineProps<{ state: ScreenState; title: string }>()

const ICONS: Record<ScreenState, string> = {
  loading: '…',
  empty: '○',
  success: '✓',
  error: '⚠',
  conflict: '⇄',
  forbidden: '⛔',
  offline: '○',
}

const role = computed(() =>
  props.state === 'error' || props.state === 'conflict' ? 'alert' : 'status',
)
const TONES: Record<ScreenState, string> = {
  loading: 'border-line bg-surface',
  empty: 'border-line bg-surface',
  success: 'border-accent bg-accent-soft',
  error: 'border-danger bg-danger-soft',
  conflict: 'border-warning bg-warning-soft',
  forbidden: 'border-danger bg-danger-soft',
  offline: 'border-warning bg-warning-soft',
}
const tone = computed(() => TONES[props.state])
</script>

<template>
  <div
    :role="role"
    :aria-busy="props.state === 'loading' ? 'true' : undefined"
    :class="['rounded-lg border-2 p-4 text-ink', tone]"
  >
    <p class="flex items-center gap-2 font-semibold">
      <span aria-hidden="true">{{ ICONS[props.state] }}</span
      >{{ props.title }}
    </p>
    <div class="mt-1 text-base text-ink"><slot /></div>
    <div v-if="$slots.action" class="mt-3"><slot name="action" /></div>
  </div>
</template>
