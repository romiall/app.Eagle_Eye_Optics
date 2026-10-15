<script setup lang="ts">
/**
 * Récapitulatif financier d'une pièce (fichier 04) : Total, Versé et Reste mis en évidence, en FCFA
 * entiers. Le composant affiche les montants qu'on lui donne (projection locale ou réponse du
 * serveur) ; il ne calcule aucun solde lui-même : les règles de solde restent dans le domaine (F08)
 * et dans la projection. Aucun montant n'est tronqué : sur téléphone, les lignes s'empilent.
 */
import { computed } from 'vue'

import { formatMoney } from '@/domain/values/money'

const props = withDefaults(
  defineProps<{
    total: number
    paid: number
    remaining: number
    /** Titre lu par les lecteurs d'écran (ex. « Montants de la facture »). */
    title?: string
  }>(),
  { title: 'Montants' },
)

const rows = computed(() => [
  { key: 'total', label: 'Total', amount: formatMoney(props.total), emphasis: false },
  { key: 'paid', label: 'Versé', amount: formatMoney(props.paid), emphasis: false },
  { key: 'remaining', label: 'Reste', amount: formatMoney(props.remaining), emphasis: true },
])
</script>

<template>
  <div
    role="group"
    :aria-label="props.title"
    class="rounded-lg border-2 border-line bg-surface p-4"
  >
    <!-- Colonnes à la taille de leur contenu, qui passent à la ligne : un grand montant ne
         déborde jamais sur son voisin (pas de colonnes de largeur fixe). -->
    <dl class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:gap-x-10">
      <div
        v-for="row in rows"
        :key="row.key"
        class="flex flex-wrap items-baseline justify-between gap-x-3 sm:flex-col sm:items-start sm:justify-start"
      >
        <dt :class="['text-base', row.emphasis ? 'font-semibold text-ink' : 'text-muted']">
          {{ row.label }}
        </dt>
        <dd
          :class="[
            'text-right tabular-nums sm:text-left',
            row.emphasis ? 'text-2xl font-bold text-ink' : 'text-xl font-semibold text-ink',
          ]"
        >
          {{ row.amount }}
        </dd>
      </div>
    </dl>
  </div>
</template>
