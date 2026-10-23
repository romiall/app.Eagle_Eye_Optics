<script setup lang="ts">
/**
 * État réseau dans l'en-tête : « En ligne », « Hors ligne » ou « Serveur injoignable », avec un
 * libellé et un symbole (jamais la seule couleur), annoncé poliment à chaque changement. « En
 * ligne » ne dit rien des pièces locales : leur état de synchronisation viendra du journal du poste
 * (F10, O04, O05) et n'est pas annoncé avant d'exister.
 */
import { computed } from 'vue'

import { useNetworkStatus } from '@/app/network'

const { online, serverReachable } = useNetworkStatus()

const view = computed(() => {
  if (!online.value) return { text: 'Hors ligne', icon: '○', tone: 'text-warning' }
  if (serverReachable.value === false)
    return { text: 'Serveur injoignable', icon: '○', tone: 'text-warning' }
  return { text: 'En ligne', icon: '●', tone: 'text-accent' }
})
</script>

<template>
  <p
    role="status"
    aria-live="polite"
    :class="[
      'flex min-h-11 items-center gap-1.5 rounded-full border border-line bg-canvas px-3 text-sm font-medium',
      view.tone,
    ]"
  >
    <span aria-hidden="true">{{ view.icon }}</span
    ><span>{{ view.text }}</span>
  </p>
</template>
