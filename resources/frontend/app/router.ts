import { nextTick } from 'vue'
import { createRouter, createWebHistory, type Router, type RouterHistory } from 'vue-router'

import ForbiddenPage from '@/pages/ForbiddenPage.vue'
import HomePage from '@/pages/HomePage.vue'
import LoginPage from '@/pages/LoginPage.vue'
import NotFoundPage from '@/pages/NotFoundPage.vue'

import { canAny, enabledModules, refreshSession, session } from './session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Page accessible sans session (connexion). */
    public?: boolean
    /** Permissions dont l'une suffit pour ouvrir la page (le serveur revérifie chaque action). */
    permissions?: readonly string[]
    /** Titre de l'onglet (WCAG 2.4.2), suivi du nom de l'application. */
    title?: string
    /**
     * Module de mise en service progressive dont dépend la page : tant que l'exploitant ne l'a pas
     * ouvert, la page répond « introuvable », comme la route du serveur (404).
     */
    module?: string
  }
}

export const APPLICATION_TITLE = 'Eagle’s Eye Optics Medical'

export function createEeoRouter(history: RouterHistory = createWebHistory()): Router {
  const router = createRouter({
    history,
    routes: [
      {
        path: '/connexion',
        name: 'connexion',
        component: LoginPage,
        meta: { public: true, title: 'Connexion' },
      },
      { path: '/', name: 'accueil', component: HomePage, meta: { title: 'Accueil' } },
      {
        path: '/acces-refuse',
        name: 'acces-refuse',
        component: ForbiddenPage,
        meta: { title: 'Accès refusé' },
      },
      {
        path: '/:pathMatch(.*)*',
        name: 'introuvable',
        component: NotFoundPage,
        meta: { title: 'Page introuvable' },
      },
    ],
    scrollBehavior: (_to, _from, savedPosition) => savedPosition ?? { top: 0 },
  })

  // Garde de navigation : la session est lue une fois, puis chaque page vérifie ses permissions. Une
  // session non vérifiable n'est pas une déconnexion : la coquille l'affiche sans rien conclure.
  router.beforeEach(async (to) => {
    if (session.status === 'unknown') {
      await refreshSession().catch(() => undefined)
    }
    if (to.meta.public === true) {
      return to.name === 'connexion' && session.status === 'authenticated'
        ? { name: 'accueil' }
        : true
    }
    if (session.status === 'anonymous' || session.status === 'expired') {
      // Seul le chemin interne est conservé, jamais de paramètre de requête (aucun dossier dans l'URL).
      return { name: 'connexion', query: to.path === '/' ? {} : { suite: to.path } }
    }
    if (to.meta.module !== undefined && session.status === 'authenticated') {
      if (!enabledModules.value.includes(to.meta.module)) {
        const pathMatch = to.path.split('/').slice(1)
        return { name: 'introuvable', params: { pathMatch }, replace: true }
      }
    }
    const required = to.meta.permissions
    if (required !== undefined) {
      // Droits non vérifiables (serveur muet ou en erreur) : l'accueil explique l'état, rien ne s'ouvre.
      if (session.status !== 'authenticated') return { name: 'accueil' }
      if (!canAny(required)) return { name: 'acces-refuse' }
    }
    return true
  })

  // Après chaque navigation : titre de l'onglet, puis focus sur le contenu (sauf au premier
  // affichage) pour qu'un lecteur d'écran annonce le nouvel écran au lieu de rester sur le lien.
  router.afterEach((to, from, failure) => {
    if (failure !== undefined || typeof document === 'undefined') return
    document.title =
      to.meta.title === undefined ? APPLICATION_TITLE : `${to.meta.title} – ${APPLICATION_TITLE}`
    if (from.matched.length > 0) {
      void nextTick(() => document.getElementById('contenu')?.focus({ preventScroll: true }))
    }
  })

  return router
}
