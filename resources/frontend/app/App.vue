<script setup lang="ts">
/**
 * Coquille de l'application (fichier 04) : en-tête « EAGLE’S EYE OPTICS MEDICAL – GESTION DES
 * PATIENTS » (lien vers l'accueil), état réseau, compte connecté ; navigation des seuls modules
 * livrés et autorisés (barre latérale sur ordinateur ; raccourcis Aujourd'hui, Patients, Agenda et
 * « Plus » sur téléphone) ; lien d'évitement vers le contenu. Sans module livré, aucune navigation
 * n'est affichée : l'accueil le dit.
 *
 * Le titre est écrit en casse normale et affiché en capitales par le style : les lecteurs d'écran le
 * lisent comme des mots, pas lettre par lettre. La recherche rapide de l'en-tête arrive avec la
 * recherche de patients (P02). L'état de synchronisation du poste (F10) s'affiche dès qu'un poste
 * préparé le fournit (journal du poste, O04, O05) ; il est distinct de l'état du réseau.
 */
import { computed, ref, watch, watchEffect } from 'vue'
import {
  isNavigationFailure,
  NavigationFailureType,
  RouterLink,
  RouterView,
  useRoute,
  useRouter,
} from 'vue-router'

import NetworkStatus from '@/components/feedback/NetworkStatus.vue'
import SyncStatus from '@/components/SyncStatus.vue'
import AppButton from '@/components/ui/AppButton.vue'

import { useNavigationCatalog, visibleModules } from './navigation'
import { probeServer, useNetworkStatus } from './network'
import { currentPermissions, refreshSession, session, signOut } from './session'

const route = useRoute()
const router = useRouter()
const catalog = useNavigationCatalog()
const { online, serverReachable } = useNetworkStatus()

const signedIn = computed(() => session.status === 'authenticated' && session.account !== null)
const modules = computed(() =>
  signedIn.value && route.meta.public !== true
    ? visibleModules(currentPermissions.value, catalog.modules)
    : [],
)
const shortcuts = computed(() => modules.value.filter((module) => module.mobileShortcut))
const others = computed(() => modules.value.filter((module) => !module.mobileShortcut))

const moreMenu = ref<HTMLDetailsElement | null>(null)
const logoutError = ref<string | null>(null)
const loggingOut = ref(false)

function closeMoreMenu(returnFocus: boolean): void {
  const menu = moreMenu.value
  if (menu === null || !menu.open) return
  menu.open = false
  if (returnFocus) menu.querySelector('summary')?.focus()
}

// Le menu « Plus » se referme dès qu'un écran est ouvert ; un message de déconnexion ancien disparaît.
watch(
  () => route.fullPath,
  () => {
    closeMoreMenu(false)
    logoutError.value = null
  },
)

function skipToContent(): void {
  document.getElementById('contenu')?.focus()
}

/**
 * Session non vérifiable au démarrage (serveur muet ou en erreur) : elle est relue quand le réseau
 * revient, puis toutes les 30 secondes (aucune conclusion tirée entre-temps).
 */
const SESSION_RETRY_MS = 30_000

async function recheckSession(): Promise<void> {
  if (session.status !== 'unverified') return
  const status = await refreshSession().catch(() => session.status)
  if (status === 'anonymous' && route.meta.public !== true) {
    await router.push({ name: 'connexion', query: route.path === '/' ? {} : { suite: route.path } })
  }
}

watch(online, (isOnline) => {
  if (!isOnline) return
  void probeServer()
  void recheckSession()
})

/**
 * Joignabilité du serveur vérifiée par requête, pas seulement par `navigator.onLine` : toutes les
 * 30 secondes quand le serveur ne répond pas (l'en-tête redevient « En ligne » à son retour), toutes
 * les 60 secondes sinon (une liaison Internet perdue n'est pas affichée « En ligne » sans limite).
 */
const PROBE_UNREACHABLE_MS = 30_000
const PROBE_REACHABLE_MS = 60_000

watchEffect((onCleanup) => {
  if (!online.value) return
  const delay = serverReachable.value === false ? PROBE_UNREACHABLE_MS : PROBE_REACHABLE_MS
  const timer = setInterval(() => void probeServer(), delay)
  onCleanup(() => clearInterval(timer))
})

watchEffect((onCleanup) => {
  if (session.status !== 'unverified') return
  const timer = setInterval(() => void recheckSession(), SESSION_RETRY_MS)
  onCleanup(() => clearInterval(timer))
})

/**
 * Déconnexion : l'écran en cours est d'abord quitté (sa garde de saisie peut refuser : la session
 * reste alors ouverte et la saisie intacte), puis la session est fermée par le serveur, et la
 * connexion ne s'affiche qu'après sa confirmation (voir signOut).
 */
async function logout(): Promise<void> {
  logoutError.value = null
  const left = await router.push({ name: 'accueil' })
  if (isNavigationFailure(left, NavigationFailureType.aborted)) return
  loggingOut.value = true
  try {
    await signOut()
    await router.push({ name: 'connexion' })
  } catch {
    logoutError.value =
      'Déconnexion impossible : le serveur n’a pas confirmé la fermeture. La session reste ouverte sur ce poste ; réessayez quand la connexion revient.'
  } finally {
    loggingOut.value = false
  }
}

const linkClass =
  'flex min-h-11 items-center rounded-lg px-3 py-2 text-base font-medium text-ink hover:bg-primary-soft aria-[current=page]:bg-primary-soft aria-[current=page]:font-semibold aria-[current=page]:text-primary'
</script>

<template>
  <a
    href="#contenu"
    class="sr-only rounded-lg bg-primary px-4 py-2 text-primary-contrast focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50"
    @click.prevent="skipToContent"
    >Aller au contenu</a
  >
  <div class="flex min-h-screen flex-col">
    <header class="border-b border-line bg-surface">
      <div class="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
        <RouterLink
          v-if="signedIn"
          to="/"
          class="inline-flex min-h-11 items-center rounded-lg text-base font-semibold tracking-tight text-primary uppercase"
          >Eagle’s Eye Optics Medical – Gestion des patients<span class="sr-only">
            (accueil)</span
          ></RouterLink
        >
        <p
          v-else
          class="inline-flex min-h-11 items-center text-base font-semibold tracking-tight text-primary uppercase"
        >
          Eagle’s Eye Optics Medical – Gestion des patients
        </p>
        <div class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-2">
          <NetworkStatus />
          <SyncStatus v-if="signedIn" />
          <template v-if="signedIn && session.account">
            <p class="text-sm text-ink">
              <span class="sr-only">Compte connecté : </span>{{ session.account.display_name }}
            </p>
            <AppButton
              variant="ghost"
              :pending="loggingOut"
              pending-label="Déconnexion en cours…"
              @click="logout"
              >Se déconnecter</AppButton
            >
          </template>
        </div>
      </div>
    </header>

    <div class="mx-auto flex w-full max-w-6xl flex-1">
      <nav
        v-if="modules.length > 0"
        aria-label="Navigation principale"
        class="hidden w-56 shrink-0 border-r border-line px-2 py-4 md:block"
      >
        <ul class="space-y-1">
          <li><RouterLink to="/" :class="linkClass">Accueil</RouterLink></li>
          <li v-for="module in modules" :key="module.id">
            <RouterLink :to="module.path" :class="linkClass">{{ module.label }}</RouterLink>
          </li>
        </ul>
      </nav>
      <main
        id="contenu"
        tabindex="-1"
        :class="['w-full min-w-0 flex-1 px-4 py-6', modules.length > 0 ? 'pb-24 md:pb-6' : '']"
      >
        <div
          v-if="logoutError"
          role="alert"
          class="mb-4 rounded-lg border-2 border-danger bg-danger-soft p-4 text-ink"
        >
          <span aria-hidden="true">⚠ </span>{{ logoutError }}
        </div>
        <RouterView />
      </main>
    </div>

    <nav
      v-if="modules.length > 0"
      aria-label="Raccourcis"
      class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface md:hidden"
    >
      <ul class="flex justify-around gap-1 px-2 py-1">
        <li v-for="module in shortcuts" :key="module.id">
          <RouterLink :to="module.path" :class="linkClass">{{ module.label }}</RouterLink>
        </li>
        <li v-if="others.length > 0" class="relative">
          <details ref="moreMenu" @keydown.esc="closeMoreMenu(true)">
            <summary :class="[linkClass, 'cursor-pointer list-none']">Plus</summary>
            <ul
              class="absolute right-0 bottom-full mb-2 max-h-[min(70vh,24rem)] w-56 space-y-1 overflow-y-auto rounded-lg border border-line bg-surface p-2 shadow-lg"
            >
              <li v-for="module in others" :key="module.id">
                <RouterLink :to="module.path" :class="linkClass">{{ module.label }}</RouterLink>
              </li>
            </ul>
          </details>
        </li>
      </ul>
    </nav>
  </div>
</template>
