<script setup lang="ts">
/**
 * État d'un compte du personnel (F09) : « Actif » ou « Désactivé », en puce. Un compte n'est
 * jamais supprimé : désactivé, il reste l'auteur de ses actions passées. Le libellé porte le sens,
 * l'icône et la teinte l'accompagnent. Présentation seule : l'état est celui que le serveur a
 * renvoyé.
 */
import StatusChip from '@/components/layout/StatusChip.vue'

const props = defineProps<{ active: boolean }>()
</script>

<template>
  <StatusChip v-if="props.active" tone="success" icon="check">Actif</StatusChip>
  <StatusChip v-else tone="neutral" icon="lock">Désactivé</StatusChip>
</template>
