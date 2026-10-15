<script setup lang="ts">
import { RouterLink } from 'vue-router'

import StateMessage from '@/components/feedback/StateMessage.vue'
</script>

<template>
  <section aria-labelledby="titre-acces-refuse" class="space-y-4">
    <h1 id="titre-acces-refuse" class="text-2xl font-semibold tracking-tight">Accès refusé</h1>
    <StateMessage state="forbidden" title="Ce compte n’a pas le droit d’ouvrir cette page">
      Demandez à la gérante d’attribuer le droit nécessaire si cette tâche fait partie de votre
      travail.
      <template #action>
        <RouterLink
          to="/"
          class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
          >Revenir à l’accueil</RouterLink
        >
      </template>
    </StateMessage>
  </section>
</template>
