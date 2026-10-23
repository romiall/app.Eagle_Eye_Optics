<script setup lang="ts">
/**
 * Accès refusé : l'écran existe, mais ce compte n'a pas le droit de l'ouvrir (le serveur refuserait
 * aussi ses lectures). La page le dit sobrement, sans détailler le droit manquant, propose la
 * démarche (demander le droit à la gérante) et ramène à l'accueil.
 */
import EmptyState from '@/components/layout/EmptyState.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
</script>

<template>
  <section aria-labelledby="titre-acces-refuse" class="page-stack">
    <PageHeader title-id="titre-acces-refuse" title="Accès refusé" />
    <EmptyState title="Ce compte n’a pas le droit d’ouvrir cette page" icon="lock">
      Demandez à la gérante d’attribuer le droit nécessaire si cette tâche fait partie de votre
      travail.
      <template #action>
        <LinkButton to="/" variant="primary"
          ><AppIcon name="home" :size="18" />Revenir à l’accueil</LinkButton
        >
      </template>
    </EmptyState>
  </section>
</template>
