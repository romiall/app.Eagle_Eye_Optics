<script setup lang="ts">
/**
 * Pastille d'un créneau libre (prise ou déplacement d'un rendez-vous) : le libellé entoure le vrai
 * bouton radio de l'écran (fente), reste une cible d'au moins 44 px et prend le fond doré léger, le
 * contour d'encre et le filet doré quand son créneau est coché (en plus de la case cochée, jamais
 * la seule couleur). Présentation seule : le nom du groupe, la valeur et le choix restent ceux de
 * l'écran (`v-model` sur le radio).
 *
 * Fente par défaut : le radio du créneau et son heure.
 */
</script>

<template>
  <label
    class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-field bg-surface px-3 py-2 font-semibold text-ink tabular-nums shadow-card hover:border-primary has-checked:border-primary has-checked:bg-primary-soft has-checked:shadow-[inset_0_-3px_0_var(--color-gold)]"
  >
    <slot />
  </label>
</template>
