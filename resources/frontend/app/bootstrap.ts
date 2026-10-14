import { createApp, type App as VueApp } from 'vue'
import type { Router, RouterHistory } from 'vue-router'

import App from './App.vue'
import { createEeoRouter } from './router'

export interface EeoApplication {
  app: VueApp
  router: Router
}

/** Construit l'application sans la monter : réutilisable par main.ts et par les tests. */
export function createEeoApp(history?: RouterHistory): EeoApplication {
  const router = createEeoRouter(history)
  const app = createApp(App).use(router)
  return { app, router }
}
