<script setup lang="ts">
/**
 * Tableau de comptages des statistiques (R02) : libellé, nombre et, si un dénominateur est donné, la part
 * en pour cent avec ce dénominateur nommé sous le tableau (fichier 02 : « Afficher la définition et le
 * dénominateur des ratios »). Trois colonnes courtes : lisible à 360 px sans défilement horizontal.
 *
 * Présentation : tableau commun (`data-table`) dans sa carte, barre proportionnelle sous chaque
 * libellé. La barre ne calcule rien : sa largeur reprend la part déjà écrite par `percentLabel`
 * (« 33,3 % » devient « 33.3% ») ; sans part (« — », dénominateur nul), aucune barre. Décorative
 * (`aria-hidden`) : le nombre et la part restent écrits dans leurs colonnes.
 */
import { percentLabel } from '@/domain/reports/statistics'

export interface CountRow {
  readonly key: string
  readonly label: string
  readonly count: number
}

const props = withDefaults(
  defineProps<{
    caption: string
    rows: readonly CountRow[]
    denominator?: number | null
    denominatorLabel?: string | null
    emptyText?: string
  }>(),
  { denominator: null, denominatorLabel: null, emptyText: 'Aucun élément sur la période.' },
)

/** Largeur CSS de la barre d'une ligne, lue dans la part écrite ; `null` : ni part ni barre. */
function barWidth(count: number): string | null {
  if (props.denominator === null) return null
  const match = /^(\d+),(\d) %$/.exec(percentLabel(count, props.denominator))
  return match === null ? null : `${match[1]}.${match[2]}%`
}
</script>

<template>
  <div class="data-table-wrap flex flex-col">
    <table class="data-table">
      <caption>
        {{
          props.caption
        }}
      </caption>
      <thead>
        <tr>
          <th scope="col">Élément</th>
          <th scope="col" class="num">Nombre</th>
          <th v-if="props.denominator !== null" scope="col" class="num">Part</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="props.rows.length === 0">
          <td :colspan="props.denominator !== null ? 3 : 2" class="text-muted">
            {{ props.emptyText }}
          </td>
        </tr>
        <tr v-for="row in props.rows" :key="row.key">
          <th scope="row" class="w-full font-normal break-words">
            {{ row.label }}
            <span
              v-if="barWidth(row.count) !== null"
              aria-hidden="true"
              class="mt-2 block h-1.5 overflow-hidden rounded-full bg-primary-soft"
            >
              <span
                class="block h-full max-w-full rounded-full bg-gold"
                :style="{ width: barWidth(row.count) ?? undefined }"
              ></span>
            </span>
          </th>
          <td class="num font-semibold">{{ row.count }}</td>
          <td v-if="props.denominator !== null" class="num text-muted">
            {{ percentLabel(row.count, props.denominator) }}
          </td>
        </tr>
      </tbody>
    </table>
    <p
      v-if="props.denominator !== null && props.denominatorLabel"
      class="mt-auto border-t border-line px-3 py-2.5 text-sm text-muted sm:px-4"
    >
      Part calculée sur {{ props.denominator }} {{ props.denominatorLabel }}.
    </p>
  </div>
</template>
