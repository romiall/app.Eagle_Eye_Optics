<script setup lang="ts">
/**
 * Puce de l'état du travail d'un examen (liste des examens, en-tête de la consultation) : « Brouillon »,
 * « En cours » ou « Terminé » en toutes lettres (libellés du domaine), teinte et icône décoratives en
 * plus du texte. Un état que cette version ne connaît pas est dit tel quel (« État inconnu (…) ») en
 * teinte neutre, jamais deviné. Présentation seule : l'état vient du serveur, la puce ne le déduit pas.
 *
 * Fente par défaut : précision lue avant le libellé (« État du travail : » pour les lecteurs d'écran).
 */
import { computed } from 'vue'

import StatusChip, { type ChipTone } from '@/components/layout/StatusChip.vue'
import { workStatusLabel } from '@/domain/clinical/labels'

const props = withDefaults(
  defineProps<{
    status: string
    /** Libellé déjà calculé par l'écran ; sinon celui du domaine. */
    label?: string | undefined
    as?: 'span' | 'p' | 'li' | 'div'
  }>(),
  { label: undefined, as: 'span' },
)

interface ChipStyle {
  tone: ChipTone
  icon?: string
}

const STYLES: Readonly<Record<string, ChipStyle>> = {
  DRAFT: { tone: 'neutral' },
  IN_PROGRESS: { tone: 'gold', icon: 'clock' },
  COMPLETED: { tone: 'success', icon: 'check' },
}
const UNKNOWN: ChipStyle = { tone: 'neutral' }

const style = computed(() => STYLES[props.status] ?? UNKNOWN)
const text = computed(() => props.label ?? workStatusLabel(props.status))
</script>

<template>
  <StatusChip :tone="style.tone" :icon="style.icon" :as="props.as"><slot />{{ text }}</StatusChip>
</template>
