<script setup lang="ts">
/**
 * Lien de navigation présenté comme un bouton (même cible de 44 px et mêmes teintes qu'`AppButton`) :
 * une action qui change d'écran est un lien, pas un bouton, pour que le clavier, le menu contextuel
 * et les lecteurs d'écran la traitent comme telle.
 */
import { RouterLink, type RouteLocationRaw } from 'vue-router'

const props = withDefaults(
  defineProps<{
    to: RouteLocationRaw
    variant?: 'primary' | 'secondary' | 'ghost'
    /** Nom accessible quand plusieurs liens portent le même libellé visible (il commence par ce libellé). */
    accessibleName?: string | undefined
  }>(),
  { variant: 'secondary', accessibleName: undefined },
)

const variants: Record<string, string> = {
  primary: 'border-transparent bg-primary text-primary-contrast hover:bg-primary-strong',
  secondary: 'border-primary bg-surface text-primary hover:bg-primary-soft',
  ghost: 'border-transparent bg-transparent text-primary hover:bg-primary-soft',
}
</script>

<template>
  <RouterLink
    :to="props.to"
    :aria-label="props.accessibleName"
    :class="[
      'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border-2 px-4 py-2 text-base font-semibold',
      variants[props.variant],
    ]"
  >
    <slot />
  </RouterLink>
</template>
