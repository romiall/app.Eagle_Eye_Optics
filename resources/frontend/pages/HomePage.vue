<script setup lang="ts">
/**
 * Accueil : le compte, le cabinet et les actions d'accueil du dossier patient (Nouveau patient,
 * Rechercher, Rendez-vous, Consultations du jour, Statistiques, Paramètres : fichier 04) réellement
 * livrées pour ce compte. Tant qu'aucune n'est livrée, la page le dit au lieu d'afficher des
 * boutons vers des écrans vides. « Livrée » veut dire terminée dans le code et ouverte par
 * l'exploitant (`enabled_modules`, mise en service progressive).
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import {
  moduleOf,
  useNavigationCatalog,
  visibleHomeActions,
  visibleModules,
} from '@/app/navigation'
import { useNetworkStatus } from '@/app/network'
import { currentPermissions, enabledModules, session } from '@/app/session'
import StateMessage from '@/components/feedback/StateMessage.vue'

const catalog = useNavigationCatalog()
const { serverReachable } = useNetworkStatus()
const actions = computed(() =>
  visibleHomeActions(currentPermissions.value, enabledModules.value, catalog.homeActions),
)
// Écran livré ET ouvert par l'exploitant : un module terminé mais pas encore ouvert (mise en service
// progressive) ne compte pas, l'accueil ne parle alors ni de droits ni de menu.
const anyOpen = computed(() =>
  [...catalog.homeActions, ...catalog.modules].some(
    (entry) => entry.delivered && enabledModules.value.includes(moduleOf(entry)),
  ),
)
const menuModules = computed(() =>
  visibleModules(currentPermissions.value, enabledModules.value, catalog.modules),
)
</script>

<template>
  <section aria-labelledby="titre-accueil" class="space-y-6">
    <div class="space-y-1">
      <h1 id="titre-accueil" class="text-2xl font-semibold tracking-tight">
        <template v-if="session.account">Bonjour {{ session.account.display_name }}</template>
        <template v-else>Application du cabinet</template>
      </h1>
      <p v-if="session.account" class="text-muted">{{ session.account.clinic.name }}</p>
    </div>

    <StateMessage v-if="session.status === 'expired'" state="offline" title="Session expirée">
      Votre session a pris fin. Reconnectez-vous avec le bouton de l’en-tête : vos saisies en cours
      restent à l’écran.
    </StateMessage>

    <StateMessage
      v-else-if="session.status === 'unverified'"
      state="offline"
      :title="serverReachable === false ? 'Serveur injoignable' : 'Session non vérifiée'"
    >
      <template v-if="serverReachable === false">Le serveur ne répond pas.</template>
      <template v-else>Le serveur a répondu par une erreur.</template>
      Rien n’est conclu sur vos données ni sur votre session : l’application réessaie seule au
      retour du réseau et toutes les 30 secondes.
    </StateMessage>

    <nav v-else-if="actions.length > 0" aria-labelledby="titre-actions">
      <h2 id="titre-actions" class="sr-only">Actions</h2>
      <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="action in actions" :key="action.id">
          <RouterLink
            :to="action.path"
            class="flex min-h-14 items-center rounded-lg border-2 border-line bg-surface px-4 py-3 text-base font-semibold text-primary hover:border-primary"
            >{{ action.label }}</RouterLink
          >
        </li>
      </ul>
    </nav>

    <StateMessage
      v-else-if="anyOpen"
      state="empty"
      title="Aucune action d’accueil ouverte à ce compte"
    >
      <template v-if="menuModules.length > 0"
        >Les écrans ouverts à ce compte sont dans le menu.</template
      >
      <template v-else
        >Demandez à la gérante d’attribuer les droits nécessaires si une tâche fait partie de votre
        travail.</template
      >
    </StateMessage>

    <StateMessage v-else state="empty" title="Aucun module métier livré pour l’instant">
      Cette version ne contient encore que le socle : les actions (nouveau patient, recherche,
      factures et caisse en premier) apparaîtront ici à leur livraison, selon les droits de chaque
      compte.
    </StateMessage>
  </section>
</template>
