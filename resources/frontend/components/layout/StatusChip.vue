<script setup lang="ts">
/**
 * Puce d'état (statut d'une facture, d'une visite, d'un compte, d'une fiche) : pastille arrondie
 * au fond léger de la teinte de l'état, point coloré (ou icône d'`AppIcon`) et texte en encre. Le
 * texte fourni par la fente porte le sens : la couleur ne l'est jamais seule (fichier 04).
 * Associations vérifiées par tests/frontend/shell/palette.test.ts : encre sur chaque fond léger,
 * point et icône dans la teinte de l'état. Présentation seule : l'état affiché est celui que
 * l'écran fournit, la puce ne le déduit pas.
 *
 * Fente par défaut : le libellé de l'état (« Émise », « Archivée », « En attente du serveur »).
 */
import AppIcon from '@/components/ui/AppIcon.vue'

export type ChipTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'gold'

const props = withDefaults(
  defineProps<{
    tone?: ChipTone
    /** Nom d'une icône d'`AppIcon` à la place du point, décorative. */
    icon?: string | undefined
    /** Élément racine : `span` dans une phrase ou une cellule ; `p` ou `li` pour une puce seule. */
    as?: 'span' | 'p' | 'li' | 'div'
  }>(),
  { tone: 'neutral', icon: undefined, as: 'span' },
)

/** Fond et bordure de la puce, teinte du point et de l'icône. */
const TONES: Record<ChipTone, { chip: string; dot: string; icon: string }> = {
  neutral: { chip: 'border-line bg-surface', dot: 'bg-muted', icon: 'text-muted' },
  info: { chip: 'border-line bg-primary-soft', dot: 'bg-primary', icon: 'text-primary' },
  success: { chip: 'border-accent bg-accent-soft', dot: 'bg-accent', icon: 'text-accent' },
  warning: { chip: 'border-warning bg-warning-soft', dot: 'bg-warning', icon: 'text-warning' },
  danger: { chip: 'border-danger bg-danger-soft', dot: 'bg-danger', icon: 'text-danger' },
  gold: { chip: 'border-gold bg-gold-soft', dot: 'bg-gold', icon: 'text-gold-ink' },
}
</script>

<template>
  <component
    :is="props.as"
    :class="[
      'inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-sm font-medium text-ink',
      TONES[props.tone].chip,
    ]"
  >
    <AppIcon v-if="props.icon" :name="props.icon" :size="14" :class="TONES[props.tone].icon" />
    <span
      v-else
      aria-hidden="true"
      :class="['size-2 shrink-0 rounded-full', TONES[props.tone].dot]"
    ></span>
    <span class="min-w-0 break-words"><slot /></span>
  </component>
</template>
