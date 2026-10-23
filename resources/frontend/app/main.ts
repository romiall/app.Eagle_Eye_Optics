import './styles.css'

import { createEeoApp } from './bootstrap'
import { registerServiceWorker } from './pwa'
import { watchServiceWorkerUpdates } from './services/appUpdate'
import { installDeviceSync } from './services/deviceSync'

const { app, router } = createEeoApp()

void router.isReady().then(() => {
  app.mount('#app')
  // Nouvelle version proposée, jamais imposée (O06) : contrôle des pièces du poste avant l'installation.
  watchServiceWorkerUpdates(registerServiceWorker())
  // Synchronisation automatique du poste préparé (O05) : sans effet tant qu'aucun poste enrôlé n'est ouvert.
  installDeviceSync()
})
