<script setup lang="ts">
/**
 * Disponibilité d'un article stocké sur une ligne de facture (I00), en lecture : disponible central
 * (physique − réservé − quotas locaux) de toutes ses positions, d'après le serveur à CET instant. Ce n'est
 * pas une réserve : le serveur réserve à l'émission et refuse (409 `STOCK_UNAVAILABLE`) ce qui manque.
 * Sans réponse, la disponibilité est « non lue », jamais « 0 ».
 */
import { onMounted, ref } from 'vue'

import { getStockAvailability } from '@/app/services/billing'
import { Quantity } from '@/domain/values/decimal'

import { quantityLabel } from './format'

const props = defineProps<{ itemId: string }>()

type View = { kind: 'loading' } | { kind: 'ready'; available: string; positions: number } | {
  kind: 'unknown'
}

const view = ref<View>({ kind: 'loading' })

onMounted(async () => {
  try {
    const result = await getStockAvailability({ itemId: props.itemId })
    let total = Quantity.of('0')
    for (const position of result.data.data) total = total.plus(Quantity.of(position.central_available))
    view.value = {
      kind: 'ready',
      available: total.canonical(),
      positions: result.data.data.length,
    }
  } catch {
    view.value = { kind: 'unknown' }
  }
})
</script>

<template>
  <p class="text-sm text-muted" role="status" aria-live="polite">
    <template v-if="view.kind === 'loading'">Lecture de la disponibilité…</template>
    <template v-else-if="view.kind === 'ready' && view.positions === 0"
      >Aucun stock enregistré pour cet article : l’émission sera refusée sans stock initial.</template
    >
    <template v-else-if="view.kind === 'ready'"
      >Disponible en stock (lecture du serveur) : {{ quantityLabel(view.available) }} — la réserve
      est prise à l’émission.</template
    >
    <template v-else>Disponibilité non lue (serveur indisponible) : rien n’est conclu.</template>
  </p>
</template>
