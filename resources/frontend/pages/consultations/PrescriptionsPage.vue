<script setup lang="ts">
/**
 * Prescription optique d'une consultation (C04). Une clé par consultation : un brouillon, un conflit ou une
 * lecture d'une consultation ne passent jamais sur une autre quand l'adresse change. Aucune donnée clinique dans
 * l'adresse (seulement l'identifiant opaque de la consultation).
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import PrescriptionsScreen from './PrescriptionsScreen.vue'

const route = useRoute()
const id = computed(() => String(route.params['id']))
</script>

<template>
  <PrescriptionsScreen :key="id" :consultation-id="id" />
</template>
