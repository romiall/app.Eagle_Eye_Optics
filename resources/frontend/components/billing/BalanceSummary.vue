<script setup lang="ts">
/**
 * Récapitulatif financier d'une facture émise (fichier 04) : « Total de la facture », « Total encaissé »
 * et « Reste à encaisser » mis en évidence, avoirs et remboursements s'il y en a. Les montants sont ceux
 * de la PROJECTION du serveur (`balance`, calculée sous le verrou de la facture) : ce composant n'en
 * calcule aucun et ne dit jamais « payé » ni « soldé » (un reste nul n'est pas une preuve : les reçus le
 * sont). Sur téléphone, les lignes s'empilent ; aucun montant n'est tronqué.
 */
import { computed } from 'vue'

import type { InvoiceBalance } from '@/domain/contracts/generated'
import { balanceLines } from '@/domain/billing/payment'
import { formatMoney } from '@/domain/values/money'

const props = withDefaults(defineProps<{ balance: InvoiceBalance; title?: string }>(), {
  title: 'Montants de la facture',
})

const lines = computed(() => balanceLines(props.balance))
</script>

<template>
  <div
    role="group"
    :aria-label="props.title"
    class="rounded-xl border border-line bg-surface shadow-card p-4"
  >
    <dl class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:gap-x-10">
      <div
        v-for="line in lines"
        :key="line.key"
        class="flex flex-wrap items-baseline justify-between gap-x-3 sm:flex-col sm:items-start sm:justify-start"
      >
        <dt :class="['text-base', line.emphasis ? 'font-semibold text-ink' : 'text-muted']">
          {{ line.label }}
        </dt>
        <dd
          :class="[
            'text-right tabular-nums sm:text-left',
            line.emphasis ? 'text-2xl font-bold text-ink' : 'text-xl font-semibold text-ink',
          ]"
          :data-montant="line.key"
        >
          {{ formatMoney(line.amount) }}
        </dd>
      </div>
    </dl>
  </div>
</template>
