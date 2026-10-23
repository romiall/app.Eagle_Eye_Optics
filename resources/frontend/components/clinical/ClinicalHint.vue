<script setup lang="ts">
/**
 * Consigne de saisie d'une étape clinique (« une case vide est non mesuré », échelles de
 * démonstration) : encadré crème discret, icône d'information décorative et texte secondaire. Le texte
 * fourni par la fente porte tout le sens. Présentation seule : aucune règle de saisie ici.
 */
import AppIcon from '@/components/ui/AppIcon.vue'
</script>

<template>
  <p
    class="flex max-w-prose items-start gap-2.5 rounded-lg border border-line bg-canvas px-3 py-2.5 text-sm text-muted"
  >
    <AppIcon name="info" :size="18" class="mt-px text-gold" />
    <span class="min-w-0"><slot /></span>
  </p>
</template>
