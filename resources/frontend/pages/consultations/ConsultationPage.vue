<script setup lang="ts">
/**
 * Consultation (C01). L'éditeur est recréé pour chaque consultation (clé = identifiant) : une saisie,
 * une version lue ou un conflit d'une consultation ne passent jamais sur une autre quand l'adresse
 * change. La garde de saisie non enregistrée demande confirmation avant ce changement. Aucune donnée
 * clinique n'est dans l'adresse (seulement l'identifiant opaque de la consultation).
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import ConsultationEditor from './ConsultationEditor.vue'

const route = useRoute()
const id = computed(() => String(route.params['id']))
</script>

<template>
  <ConsultationEditor :key="id" :consultation-id="id" />
</template>
