<script setup lang="ts">
/**
 * État d'une pièce ou d'une fiche (fichier 19 : « L'interface distingue émis localement et consolidé
 * sur le serveur ») : enregistré sur ce poste, en attente, consolidé, conflit, rejeté, et l'état de
 * son PDF local. Libellé et symbole, jamais la seule couleur.
 */
import { computed } from 'vue'

import {
  DOCUMENT_STATE_LABELS,
  type DocumentState,
  type LocalSyncState,
  SYNC_STATE_LABELS,
} from '@/app/services/sync'

const props = withDefaults(
  defineProps<{ state: LocalSyncState | 'consolidated'; document?: DocumentState }>(),
  { document: 'none' },
)

const ICONS: Record<LocalSyncState | 'consolidated', string> = {
  local: '●',
  pending: '↻',
  waiting_dependency: '↻',
  conflict: '⚠',
  acknowledged: '✓',
  rejected: '✕',
  consolidated: '✓',
}

const TONES: Record<LocalSyncState | 'consolidated', string> = {
  local: 'border-warning text-warning',
  pending: 'border-warning text-warning',
  waiting_dependency: 'border-warning text-warning',
  conflict: 'border-danger text-danger',
  acknowledged: 'border-accent text-accent',
  rejected: 'border-danger text-danger',
  consolidated: 'border-accent text-accent',
}

const documentLabel = computed(() =>
  props.document === 'none' ? null : DOCUMENT_STATE_LABELS[props.document],
)
</script>

<template>
  <span class="inline-flex flex-wrap items-center gap-2 text-sm font-medium">
    <span
      :class="[
        'inline-flex items-center gap-1 rounded-full border bg-surface px-2 py-0.5',
        TONES[props.state],
      ]"
    >
      <span aria-hidden="true">{{ ICONS[props.state] }}</span
      ><span>{{ SYNC_STATE_LABELS[props.state] }}</span>
    </span>
    <span
      v-if="documentLabel"
      :class="[
        'inline-flex items-center gap-1 rounded-full border bg-surface px-2 py-0.5',
        props.document === 'failed' ? 'border-danger text-danger' : 'border-line text-ink',
      ]"
    >
      <span aria-hidden="true">{{ props.document === 'failed' ? '⚠' : '▣' }}</span
      ><span>{{ documentLabel }}</span>
    </span>
  </span>
</template>
