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
/** Fond, bordure et filet gauche de l'état (filet doré pour un état neutre). */
const TONES: Record<ScreenState, string> = {
  loading: 'border-line border-l-gold bg-surface',
  empty: 'border-line border-l-gold bg-surface',
  success: 'border-accent bg-accent-soft',
  error: 'border-danger bg-danger-soft',
  conflict: 'border-warning bg-warning-soft',
  forbidden: 'border-danger bg-danger-soft',
  offline: 'border-warning bg-warning-soft',
}
/** Pastille du symbole de l'état. */
const BADGES: Record<ScreenState, string> = {
  loading: 'border-gold bg-gold-soft text-gold-ink',
  empty: 'border-gold bg-gold-soft text-gold-ink',
  success: 'border-accent bg-surface text-accent',
  error: 'border-danger bg-surface text-danger',
  conflict: 'border-warning bg-surface text-warning',
  forbidden: 'border-danger bg-surface text-danger',
  offline: 'border-warning bg-surface text-warning',
}
const tone = computed(() => TONES[props.state])
const badge = computed(() => BADGES[props.state])
</script>

<template>
  <div
    :role="role"
    :aria-busy="props.state === 'loading' ? 'true' : undefined"
    :class="['rounded-xl border border-l-4 p-4 text-ink shadow-card sm:p-5', tone]"
  >
    <p class="flex items-center gap-3 font-semibold">
      <span
        aria-hidden="true"
        :class="[
          'flex size-7 shrink-0 items-center justify-center rounded-full border text-sm leading-none',
          badge,
        ]"
        >{{ ICONS[props.state] }}</span
      >{{ props.title }}
    </p>
    <div class="mt-2 text-base text-ink sm:pl-10"><slot /></div>
    <div v-if="$slots.action" class="mt-3 sm:pl-10"><slot name="action" /></div>
  </div>
</template>
