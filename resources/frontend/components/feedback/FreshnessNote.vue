<script setup lang="ts">
/**
 * Fraîcheur des données affichées (fichier 04 : « afficher fraîcheur distante » ; AT45 : totaux
 * distants marqués incomplets). Dit d'où viennent les chiffres : serveur à quelle heure, données
 * distantes incomplètes, ou données de ce poste seulement.
 */
import { computed } from 'vue'

import type { Freshness } from '@/app/services/reads'

const props = withDefaults(defineProps<{ freshness: Freshness; timeZone?: string }>(), {
  timeZone: 'Africa/Douala',
})

function stamp(instant: string): string {
  const parts = new Intl.DateTimeFormat('fr-FR', {
    timeZone: props.timeZone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(instant))
  const read = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((part) => part.type === type)?.value ?? '00'
  return `${read('day')}/${read('month')}/${read('year')} à ${read('hour')}h${read('minute')}`
}

const lines = computed<{ icon: string; text: string; warn: boolean }[]>(() => {
  const { serverAsOf, complete } = props.freshness
  if (serverAsOf === null) {
    return [
      {
        icon: '○',
        text: 'Données de ce poste seulement : le serveur n’a pas été consulté.',
        warn: true,
      },
    ]
  }
  const result = [{ icon: '·', text: `Données du serveur au ${stamp(serverAsOf)}.`, warn: false }]
  if (!complete) {
    result.push({
      icon: '⚠',
      text: 'Totaux distants incomplets : une partie des pièces du serveur n’est pas encore reçue.',
      warn: true,
    })
  }
  return result
})
</script>

<template>
  <div class="space-y-1 text-sm">
    <p
      v-for="line in lines"
      :key="line.text"
      :class="['flex items-start gap-1', line.warn ? 'font-medium text-warning' : 'text-muted']"
    >
      <span aria-hidden="true">{{ line.icon }}</span
      ><span>{{ line.text }}</span>
    </p>
  </div>
</template>
