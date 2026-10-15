import { createApp, type App as VueApp } from 'vue'
import type { Router, RouterHistory } from 'vue-router'

import App from './App.vue'
import { DEFAULT_NAVIGATION, NAVIGATION_KEY, type NavigationCatalog } from './navigation'
import { createEeoRouter } from './router'

export interface EeoApplication {
  app: VueApp
  router: Router
}

export interface EeoAppOptions {
  /** Catalogue de navigation (les tests injectent des écrans fictifs livrés). */
  navigation?: NavigationCatalog
}

/** Construit l'application sans la monter : réutilisable par main.ts et par les tests. */
export function createEeoApp(history?: RouterHistory, options: EeoAppOptions = {}): EeoApplication {
  const router = createEeoRouter(history)
  const app = createApp(App)
    .provide(NAVIGATION_KEY, options.navigation ?? DEFAULT_NAVIGATION)
    .use(router)
  return { app, router }
}
