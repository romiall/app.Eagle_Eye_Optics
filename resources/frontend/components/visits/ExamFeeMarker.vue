<script setup lang="ts">
/**
 * Marqueur du contrôle du paiement de l'examen d'une visite (fichier 02, 04). Contrôle inactif (défaut) :
 * un examen ou un contrôle de suivi est « Paiement de l'examen non contrôlé » : ni payé, ni impayé, ni
 * gratuit — rien n'est vérifié. Contrôle actif (P03 avec B04 et B06) : « Frais d'examen à encaisser »,
 * « Dispense … demandée », « Frais d'examen encaissés : reçu n° … » (numéro du reçu réel) ou « Dispense
 * approuvée par la gérante ». Symbole et texte, jamais la couleur seule.
 */
import { computed } from 'vue'

import type { Visit } from '@/domain/contracts/generated'
import { examFeeMarker } from '@/domain/patients/visits'

const props = withDefaults(
  defineProps<{ control: Visit['exam_fee_control']; receiptNumber?: string | null }>(),
  { receiptNumber: null },
)

const marker = computed(() => examFeeMarker(props.control, props.receiptNumber))
</script>

<template>
  <span
    :class="[
      'inline-flex items-start gap-1 text-sm font-medium',
      marker.notControlled || marker.pending === true
        ? 'rounded-lg border-2 border-warning bg-warning-soft px-2 py-0.5 text-ink'
        : 'text-muted',
    ]"
    ><span aria-hidden="true">{{ marker.symbol }}</span
    ><span>{{ marker.text }}</span></span
  >
</template>
