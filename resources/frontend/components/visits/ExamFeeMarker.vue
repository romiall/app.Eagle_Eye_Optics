<script setup lang="ts">
/**
 * Marqueur du contrôle du paiement de l'examen d'une visite (fichier 02, 04). Tant que ce contrôle
 * n'existe pas (B04, B06), un examen ou un contrôle de suivi est « Paiement de l'examen non
 * contrôlé » : ni payé, ni impayé, ni gratuit — rien n'est vérifié. Symbole et texte, jamais la
 * couleur seule.
 */
import { computed } from 'vue'

import type { Visit } from '@/domain/contracts/generated'
import { examFeeMarker } from '@/domain/patients/visits'

const props = defineProps<{ control: Visit['exam_fee_control'] }>()

const marker = computed(() => examFeeMarker(props.control))
</script>

<template>
  <span
    :class="[
      'inline-flex items-start gap-1 text-sm font-medium',
      marker.notControlled
        ? 'rounded-lg border-2 border-warning bg-warning-soft px-2 py-0.5 text-ink'
        : 'text-muted',
    ]"
    ><span aria-hidden="true">{{ marker.symbol }}</span
    ><span>{{ marker.text }}</span></span
  >
</template>
