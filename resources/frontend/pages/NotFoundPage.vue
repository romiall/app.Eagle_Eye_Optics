<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <section aria-labelledby="titre-introuvable" class="space-y-4">
    <h1 id="titre-introuvable" class="text-2xl font-semibold tracking-tight">Page introuvable</h1>
    <p class="max-w-prose text-muted">
      Cette adresse ne correspond à aucun écran de l’application.
    </p>
    <RouterLink
      to="/"
      class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
    >
      Retour à l’accueil
    </RouterLink>
  </section>
</template>
