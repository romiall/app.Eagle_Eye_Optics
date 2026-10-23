import { createApp, type App as VueApp } from 'vue'
import type { Router, RouterHistory } from 'vue-router'

import App from './App.vue'
import { DEFAULT_NAVIGATION, NAVIGATION_KEY, type NavigationCatalog } from './navigation'
import { deviceSyncState } from './services/deviceSync'
import { deviceLocalEngine } from './services/localEngine'
import { LOCAL_ENGINE_KEY } from './services/mutations'
import { SYNC_STATE_KEY } from './services/sync'
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
    // Journal du poste préparé (O04) : n'agit que module `offline` ouvert et coffre ouvert avec un grant valide ;
    // sinon chaque mutation suit la route directe, comme avant.
    .provide(LOCAL_ENGINE_KEY, deviceLocalEngine)
    // État de synchronisation du poste (O05) : null tant qu'aucun poste enrôlé n'est ouvert (rien n'est annoncé).
    .provide(SYNC_STATE_KEY, deviceSyncState)
    .use(router)
  return { app, router }
}
