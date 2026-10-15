<script setup lang="ts">
/**
 * Bouton d'action : libellé qui dit sa conséquence (« Émettre la facture », fichier 04), cible
 * tactile d'au moins 44 px, désactivé pendant la commande pour réduire les doubles clics (l'idempotence
 * locale et serveur reste la vraie protection). Pendant la commande, le libellé devient
 * `pendingLabel` et `aria-busy` est posé. L'état désactivé garde un texte lisible (teintes pleines
 * vérifiées par le test de contraste, jamais une simple opacité).
 */
const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost'
    type?: 'button' | 'submit'
    pending?: boolean
    disabled?: boolean
    pendingLabel?: string
  }>(),
  {
    variant: 'primary',
    type: 'button',
    pending: false,
    disabled: false,
    pendingLabel: 'Traitement en cours…',
  },
)

const emit = defineEmits<{ click: [event: MouseEvent] }>()

const variants: Record<string, string> = {
  primary: 'border-transparent bg-primary text-primary-contrast hover:bg-primary-strong',
  secondary: 'border-primary bg-surface text-primary hover:bg-primary-soft',
  danger: 'border-transparent bg-danger text-primary-contrast hover:bg-danger-strong',
  ghost: 'border-transparent bg-transparent text-primary hover:bg-primary-soft',
}
</script>

<template>
  <button
    :type="props.type"
    :disabled="props.disabled || props.pending"
    :aria-busy="props.pending ? 'true' : undefined"
    :class="[
      'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border-2 px-4 py-2 text-base font-semibold',
      'disabled:cursor-not-allowed disabled:border-transparent disabled:bg-disabled disabled:text-disabled-ink',
      variants[props.variant],
    ]"
    @click="emit('click', $event)"
  >
    <span v-if="props.pending">{{ props.pendingLabel }}</span>
    <slot v-else />
  </button>
</template>
