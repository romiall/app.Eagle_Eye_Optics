<script setup lang="ts">
/**
 * Marqueur du contrôle du paiement de l'examen d'une visite (fichier 02, 04). Contrôle inactif (défaut) :
 * un examen ou un contrôle de suivi est « Paiement de l'examen non contrôlé » : ni payé, ni impayé, ni
 * gratuit — rien n'est vérifié. Contrôle actif (P03 avec B04 et B06) : « Frais d'examen à encaisser »,
 * « Dispense … demandée », « Frais d'examen encaissés : reçu n° … » (numéro du reçu réel) ou « Dispense
 * approuvée par la gérante ». Symbole et texte, jamais la couleur seule.
 *
 * Présenté en puce (charte du logo) : avertissement quand rien n'est contrôlé ou qu'une action est
 * attendue, succès pour des frais encaissés ou une dispense accordée, neutre sans examen
 * (associations vérifiées par tests/frontend/shell/palette.test.ts). Le symbole reste le premier
 * élément décoratif de la puce.
 */
import { computed } from 'vue'

import type { Visit } from '@/domain/contracts/generated'
import { examFeeMarker } from '@/domain/patients/visits'

const props = withDefaults(
  defineProps<{ control: Visit['exam_fee_control']; receiptNumber?: string | null }>(),
  { receiptNumber: null },
)

const marker = computed(() => examFeeMarker(props.control, props.receiptNumber))

/** Fond et bordure de la puce, teinte du symbole. */
const tone = computed(() => {
  if (marker.value.notControlled || marker.value.pending === true) {
    return { chip: 'border-warning bg-warning-soft text-ink', symbol: 'text-warning' }
  }
  if (props.control === 'PAID' || props.control === 'WAIVED') {
    return { chip: 'border-accent bg-accent-soft text-ink', symbol: 'text-accent' }
  }
  return { chip: 'border-line bg-surface text-muted', symbol: 'text-muted' }
})
</script>

<template>
  <span
    :class="[
      'inline-flex max-w-full items-start gap-1.5 rounded-2xl border px-2.5 py-0.5 text-sm font-medium',
      tone.chip,
    ]"
    ><span aria-hidden="true" :class="tone.symbol">{{ marker.symbol }}</span
    ><span class="min-w-0 break-words">{{ marker.text }}</span></span
  >
</template>
