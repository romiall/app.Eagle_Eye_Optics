import './styles.css'

import { createEeoApp } from './bootstrap'
import { registerServiceWorker } from './pwa'

const { app, router } = createEeoApp()

void router.isReady().then(() => {
  app.mount('#app')
  registerServiceWorker()
})
