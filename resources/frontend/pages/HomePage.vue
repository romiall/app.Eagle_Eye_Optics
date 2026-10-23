<script setup lang="ts">
/**
 * Accueil : le compte, le cabinet et le jour civil du cabinet, puis le bandeau « Aujourd’hui »
 * (`TodaySummary` : file du jour, rendez-vous et encaissé net lus sur le serveur, chaque tuile
 * seulement avec son droit et son module ouvert), puis les actions d'accueil du dossier patient
 * (Nouveau patient, Rechercher, Rendez-vous, Consultations du jour, Statistiques, Paramètres :
 * fichier 04) réellement livrées pour ce compte. Tant qu'aucune n'est livrée, la page le dit au
 * lieu d'afficher des boutons vers des écrans vides. « Livrée » veut dire terminée dans le code et
 * ouverte par l'exploitant (`enabled_modules`, mise en service progressive).
 *
 * Sous ces actions, l'accueil par profil : des raccourcis de travail regroupés par activité (accueil des
 * patients, examens, caisse et ventes, administration), chacun visible seulement avec son droit et son module
 * ouvert. Aucun profil n'est nommé : un compte qui cumule plusieurs profils voit chaque activité qui le
 * concerne. Le lien « Changer mon mot de passe » sert à tout compte (mot de passe initial transmis par la
 * gérante).
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import { clinicToday, formatDayFr } from '@/app/clinicTime'
import {
  moduleOf,
  useNavigationCatalog,
  visibleHomeActions,
  visibleModules,
  visibleWorkShortcuts,
  WORK_GROUP_LABELS,
  type WorkGroup,
  type WorkShortcut,
} from '@/app/navigation'
import { iconOf } from '@/app/navigationIcons'
import { useNetworkStatus } from '@/app/network'
import { currentPermissions, enabledModules, session } from '@/app/session'
import logoUrl from '@/assets/brand/eeo-logo.svg'
import StateMessage from '@/components/feedback/StateMessage.vue'
import TodaySummary from '@/components/home/TodaySummary.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'

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
/** Jour civil du cabinet en toutes lettres, sous la salutation (« jeudi 22 octobre 2026 »). */
const todayLabel = computed(() => formatDayFr(clinicToday()))

const WORK_GROUPS: readonly WorkGroup[] = ['reception', 'exams', 'cash', 'administration']

/** Raccourcis de travail visibles, par activité, dans l'ordre fixe des activités. */
const workGroups = computed<{ group: WorkGroup; label: string; shortcuts: WorkShortcut[] }[]>(
  () => {
    const visible = visibleWorkShortcuts(
      currentPermissions.value,
      enabledModules.value,
      catalog.workShortcuts ?? [],
    )
    return WORK_GROUPS.map((group) => ({
      group,
      label: WORK_GROUP_LABELS[group],
      shortcuts: visible.filter((shortcut) => shortcut.group === group),
    })).filter((entry) => entry.shortcuts.length > 0)
  },
)
</script>

<template>
  <section aria-labelledby="titre-accueil" class="page-stack">
    <div
      class="flex items-center justify-between gap-6 rounded-2xl border border-line border-l-4 border-l-gold bg-surface px-5 py-5 shadow-card sm:px-7 sm:py-6"
    >
      <div class="min-w-0 space-y-2">
        <p
          v-if="session.account"
          class="text-sm font-semibold tracking-wide break-words text-gold-ink uppercase"
        >
          {{ session.account.clinic.name }}
        </p>
        <h1
          id="titre-accueil"
          class="text-2xl font-semibold tracking-tight break-words sm:text-3xl"
        >
          <template v-if="session.account">Bonjour {{ session.account.display_name }}</template>
          <template v-else>Application du cabinet</template>
        </h1>
        <p v-if="session.account" class="text-base text-muted first-letter:uppercase">
          {{ todayLabel }}
        </p>
      </div>
      <img
        :src="logoUrl"
        alt=""
        width="380"
        height="417"
        class="hidden h-20 w-auto shrink-0 sm:block lg:h-24"
      />
    </div>

    <TodaySummary v-if="session.status === 'authenticated'" />

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

    <nav v-else-if="actions.length > 0" aria-labelledby="titre-actions" class="space-y-3">
      <h2 id="titre-actions" class="section-title">Actions</h2>
      <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="action in actions" :key="action.id">
          <RouterLink
            :to="action.path"
            class="group flex min-h-14 items-center gap-4 rounded-xl border border-line bg-surface px-4 py-4 text-base font-semibold text-primary shadow-card transition hover:-translate-y-0.5 hover:border-gold hover:shadow-raised motion-reduce:hover:translate-y-0"
            ><span
              aria-hidden="true"
              class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gold-soft text-gold-ink group-hover:bg-primary group-hover:text-primary-contrast"
              ><AppIcon :name="iconOf(action.id)" :size="22" /></span
            >{{ action.label }}</RouterLink
          >
        </li>
      </ul>
    </nav>

    <EmptyState v-else-if="anyOpen" title="Aucune action d’accueil ouverte à ce compte" icon="info">
      <template v-if="menuModules.length > 0"
        >Les écrans ouverts à ce compte sont dans le menu.</template
      >
      <template v-else
        >Demandez à la gérante d’attribuer les droits nécessaires si une tâche fait partie de votre
        travail.</template
      >
    </EmptyState>

    <EmptyState v-else title="Aucun module métier livré pour l’instant" icon="folder">
      Cette version ne contient encore que le socle : les actions (nouveau patient, recherche,
      factures et caisse en premier) apparaîtront ici à leur livraison, selon les droits de chaque
      compte.
    </EmptyState>

    <template v-if="session.status === 'authenticated'">
      <section
        v-for="entry in workGroups"
        :key="entry.group"
        :aria-labelledby="`titre-travail-${entry.group}`"
        class="space-y-3"
      >
        <h2 :id="`titre-travail-${entry.group}`" class="section-title">{{ entry.label }}</h2>
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li v-for="shortcut in entry.shortcuts" :key="shortcut.id">
            <RouterLink
              :to="shortcut.path"
              class="flex min-h-12 items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3 text-base font-medium text-primary shadow-card hover:border-gold hover:bg-primary-soft"
              ><AppIcon :name="iconOf(shortcut.id)" class="text-gold" />{{
                shortcut.label
              }}</RouterLink
            >
          </li>
        </ul>
      </section>

      <div class="flex flex-wrap gap-x-6 gap-y-1 border-t border-line pt-4">
        <p>
          <RouterLink
            :to="{ name: 'mot-de-passe' }"
            class="text-link inline-flex min-h-11 items-center gap-2"
            ><AppIcon name="key" :size="18" class="text-gold" />Changer mon mot de passe</RouterLink
          >
        </p>
        <p>
          <RouterLink
            :to="{ name: 'aide' }"
            class="text-link inline-flex min-h-11 items-center gap-2"
            ><AppIcon name="help" :size="18" class="text-gold" />Aide : comment utiliser
            l’application</RouterLink
          >
        </p>
      </div>
    </template>
  </section>
</template>
