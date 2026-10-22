<script setup lang="ts">
/**
 * Modification d'une fiche (P01). L'éditeur est recréé pour chaque fiche (clé = identifiant) : une
 * saisie, une version lue ou un conflit d'une fiche ne passent jamais sur une autre quand l'adresse
 * change. La garde de saisie non enregistrée demande confirmation avant ce changement.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import PatientEditor from './PatientEditor.vue'

const route = useRoute()
const id = computed(() => String(route.params['id']))
</script>

<template>
  <PatientEditor :key="id" :patient-id="id" />
</template>
