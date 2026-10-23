<script setup lang="ts">
/**
 * Page introuvable : l'adresse ne correspond à aucun écran livré et ouvert (un module fermé répond
 * de même, comme le serveur avec son 404). La page le dit sobrement et ramène à l'accueil, sans
 * rien deviner de l'écran cherché.
 */
import EmptyState from '@/components/layout/EmptyState.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
</script>

<template>
  <section aria-labelledby="titre-introuvable" class="page-stack">
    <PageHeader title-id="titre-introuvable" title="Page introuvable" />
    <EmptyState title="Cette adresse ne correspond à aucun écran de l’application." icon="search">
      Vérifiez l’adresse saisie, ou revenez à l’accueil pour retrouver vos écrans.
      <template #action>
        <LinkButton to="/" variant="primary"
          ><AppIcon name="home" :size="18" />Retour à l’accueil</LinkButton
        >
      </template>
    </EmptyState>
  </section>
</template>
