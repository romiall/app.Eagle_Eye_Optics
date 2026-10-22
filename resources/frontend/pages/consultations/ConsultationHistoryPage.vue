<script setup lang="ts">
/**
 * Révisions d'une consultation (C03). Une clé par consultation : la sélection et les révisions lues ne passent
 * jamais sur une autre consultation quand l'adresse change. Aucune donnée clinique dans l'adresse (seulement
 * l'identifiant opaque de la consultation).
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import RevisionHistory from './RevisionHistory.vue'

const route = useRoute()
const id = computed(() => String(route.params['id']))
</script>

<template>
  <RevisionHistory :key="id" :consultation-id="id" />
</template>
