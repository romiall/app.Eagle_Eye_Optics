<script setup lang="ts">
/**
 * Étape numérotée d'un parcours de saisie (prise de rendez-vous) : carte blanche, pastille du numéro
 * (une coche quand l'écran dit l'étape remplie), vrai titre `h2`, aide facultative, puis le corps
 * aligné sous le titre dès la tablette. La pastille est décorative : l'état réel de l'étape se lit
 * dans ses champs. Présentation seule : aucun appel serveur, aucune règle.
 *
 * Fente par défaut : les champs de l'étape.
 */
import AppIcon from '@/components/ui/AppIcon.vue'

const props = withDefaults(
  defineProps<{
    step: number
    title: string
    hint?: string | undefined
    /** Étape remplie : la pastille montre une coche au lieu du numéro. */
    done?: boolean
  }>(),
  { hint: undefined, done: false },
)
</script>

<template>
  <div class="card p-4 sm:p-6">
    <div class="flex items-start gap-3 sm:gap-4">
      <span
        aria-hidden="true"
        :class="[
          'flex size-9 shrink-0 items-center justify-center rounded-full border text-base font-semibold tabular-nums',
          props.done
            ? 'border-accent bg-accent-soft text-accent'
            : 'border-gold bg-gold-soft text-gold-ink',
        ]"
      >
        <AppIcon v-if="props.done" name="check" :size="18" />
        <template v-else>{{ props.step }}</template>
      </span>
      <div class="min-w-0 flex-1 space-y-1 pt-1">
        <h2 class="text-lg leading-7 font-semibold break-words">{{ props.title }}</h2>
        <p v-if="props.hint" class="max-w-prose text-sm text-muted">{{ props.hint }}</p>
      </div>
    </div>
    <div class="mt-4 space-y-4 sm:mt-5 sm:pl-13">
      <slot />
    </div>
  </div>
</template>
