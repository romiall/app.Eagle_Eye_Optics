<script setup lang="ts">
/**
 * Tuile de chiffre clé (statistiques, caisse du jour, file du jour) : libellé, valeur en grand en
 * chiffres tabulaires, unité, complément et lien facultatif vers le détail. C'est un groupe
 * `dt`/`dd` : la tuile se place dans un `<dl class="stat-grid">`, ou porte son propre `<dl>` avec
 * `standalone`. Une valeur inconnue (`null`) s'affiche « — » avec son explication écrite, jamais un
 * zéro inventé. Le composant affiche la valeur telle quelle : l'écran la passe déjà mise en forme
 * par les règles existantes (FCFA entiers, pourcentage). La teinte accompagne le libellé et ne
 * porte jamais seule le sens. Avec `to`, toute la tuile est cliquable (lien étendu) et le lien est
 * nommé « <linkLabel> : <label> ». Présentation seule : aucun appel serveur.
 *
 * Fente par défaut : précision supplémentaire sous la valeur (puce d'état, décomposition).
 */
import { RouterLink, type RouteLocationRaw } from 'vue-router'

import AppIcon from '@/components/ui/AppIcon.vue'

export type StatTone = 'neutral' | 'gold' | 'success' | 'warning' | 'danger'

const props = withDefaults(
  defineProps<{
    label: string
    /** Valeur affichée telle quelle ; `null` : indisponible (« — » et `unavailableText`). */
    value: string | number | null
    /** Unité après la valeur, en plus petit (« FCFA », « visites »). */
    unit?: string | undefined
    /** Complément sous la valeur (période, dénominateur, précision). */
    hint?: string | undefined
    /** Explication d'une valeur indisponible. */
    unavailableText?: string
    tone?: StatTone
    /** Nom d'une icône d'`AppIcon`, décorative. */
    icon?: string | undefined
    to?: RouteLocationRaw | undefined
    linkLabel?: string
    standalone?: boolean
  }>(),
  {
    unit: undefined,
    hint: undefined,
    unavailableText: 'Non disponible',
    tone: 'neutral',
    icon: undefined,
    to: undefined,
    linkLabel: 'Voir le détail',
    standalone: false,
  },
)

/** Filet haut, pastille d'icône et couleur de la valeur (associations vérifiées sur surface). */
const TONES: Record<StatTone, { frame: string; badge: string; value: string }> = {
  neutral: { frame: '', badge: 'bg-primary-soft text-primary', value: 'text-ink' },
  gold: {
    frame: 'border-t-4 border-t-gold',
    badge: 'bg-gold-soft text-gold-ink',
    value: 'text-ink',
  },
  success: {
    frame: 'border-t-4 border-t-accent',
    badge: 'bg-accent-soft text-accent',
    value: 'text-accent',
  },
  warning: {
    frame: 'border-t-4 border-t-warning',
    badge: 'bg-warning-soft text-warning',
    value: 'text-warning',
  },
  danger: {
    frame: 'border-t-4 border-t-danger',
    badge: 'bg-danger-soft text-danger',
    value: 'text-danger',
  },
}
</script>

<template>
  <component
    :is="props.standalone ? 'dl' : 'div'"
    :class="[
      'relative flex min-w-0 flex-col gap-1 rounded-xl border border-line bg-surface p-4 shadow-card sm:p-5',
      TONES[props.tone].frame,
      props.to === undefined ? '' : 'has-[a:hover]:border-gold has-[a:hover]:shadow-raised',
    ]"
  >
    <dt :class="['text-sm font-medium text-muted', props.icon ? 'min-h-9 pr-12' : '']">
      {{ props.label }}
      <div
        v-if="props.icon"
        aria-hidden="true"
        :class="[
          'absolute top-4 right-4 flex size-9 items-center justify-center rounded-lg sm:top-5 sm:right-5',
          TONES[props.tone].badge,
        ]"
      >
        <AppIcon :name="props.icon" :size="18" />
      </div>
    </dt>
    <dd v-if="props.value === null">
      <div aria-hidden="true" class="text-3xl leading-tight font-semibold text-muted">—</div>
      <div class="text-sm text-muted">{{ props.unavailableText }}</div>
    </dd>
    <dd
      v-else
      :class="[
        'text-2xl leading-tight font-semibold tracking-tight tabular-nums break-words sm:text-3xl',
        TONES[props.tone].value,
      ]"
    >
      <span>{{ props.value }}</span
      ><span v-if="props.unit" class="text-base font-medium tracking-normal text-muted"
        >&nbsp;{{ props.unit }}</span
      >
    </dd>
    <dd v-if="props.hint" class="text-sm text-muted">{{ props.hint }}</dd>
    <dd v-if="$slots.default" class="pt-1">
      <slot />
    </dd>
    <dd v-if="props.to !== undefined" class="mt-auto pt-1">
      <RouterLink
        :to="props.to"
        :aria-label="`${props.linkLabel} : ${props.label}`"
        class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary underline underline-offset-4 after:absolute after:inset-0 after:rounded-xl"
        >{{ props.linkLabel }}<AppIcon name="chevron-right" :size="16"
      /></RouterLink>
    </dd>
  </component>
</template>
