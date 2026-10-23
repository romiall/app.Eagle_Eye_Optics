<script setup lang="ts">
/**
 * Pastille d'initiales d'un patient (liste de recherche, bandeau du dossier, fiches proches,
 * fusion) : première lettre du nom et du premier prénom (ou du deuxième mot du nom sans prénom),
 * or foncé sur fond doré léger (association vérifiée par tests/frontend/shell/palette.test.ts).
 * Purement décorative (`aria-hidden`) : le nom complet est toujours écrit à côté. Sans lettre
 * lisible, une icône de personne la remplace. Présentation seule : identité administrative.
 */
import { computed } from 'vue'

import AppIcon from '@/components/ui/AppIcon.vue'

const props = withDefaults(
  defineProps<{
    lastName: string
    firstNames: string | null
    /** `sm` : rangée de liste ; `md` : carte ; `lg` : bandeau du dossier. */
    size?: 'sm' | 'md' | 'lg'
  }>(),
  { size: 'md' },
)

const LETTER = /[\p{L}\p{N}]/u

/** Première lettre ou chiffre d'un mot (les accents sont gardés : « Élodie » donne « É »). */
function firstLetter(word: string | undefined): string {
  return Array.from(word ?? '').find((character) => LETTER.test(character)) ?? ''
}

const initials = computed(() => {
  const names = props.lastName.trim().split(/\s+/)
  const given = (props.firstNames ?? '').trim().split(/\s+/)[0] ?? ''
  const second = given !== '' ? given : names[1]
  return `${firstLetter(names[0])}${firstLetter(second)}`.toLocaleUpperCase('fr')
})

const SIZES: Record<'sm' | 'md' | 'lg', { box: string; icon: number }> = {
  sm: { box: 'size-10 text-sm', icon: 18 },
  md: { box: 'size-12 text-base', icon: 22 },
  lg: { box: 'size-12 text-base sm:size-16 sm:text-xl', icon: 26 },
}
</script>

<template>
  <span
    aria-hidden="true"
    :class="[
      'flex shrink-0 items-center justify-center rounded-full border border-gold bg-gold-soft font-semibold tracking-wide text-gold-ink select-none',
      SIZES[props.size].box,
    ]"
  >
    <template v-if="initials !== ''">{{ initials }}</template>
    <AppIcon v-else name="user" :size="SIZES[props.size].icon" />
  </span>
</template>
