import { defineConfig, devices } from '@playwright/test'

import { chromiumLaunchOptions } from './tests/e2e/support/chromium'
import { phpServerOptions } from './tests/e2e/support/php'
import { MAIN_PORT } from './tests/e2e/support/ports'

const BASE_URL = `http://127.0.0.1:${MAIN_PORT}`
const LARAVEL_ROUTER = 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'

export default defineConfig({
  testDir: 'tests/e2e',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env['CI']),
  retries: process.env['CI'] ? 1 : 0,
  reporter: process.env['CI'] ? [['github'], ['list']] : [['list']],
  outputDir: 'test-results',
  // Marges pour un poste Windows partagé et chargé, où une requête Laravel prend parfois plusieurs
  // secondes (serveur intégré : une requête à la fois, précache du service worker compris). Ce qui
  // est vérifié ne change pas ; un test réussi n'attend que le temps nécessaire.
  timeout: 60_000,
  expect: { timeout: 20_000 },
  globalSetup: './tests/e2e/global-setup.ts',
  use: {
    baseURL: BASE_URL,
    locale: 'fr-FR',
    timezoneId: 'Africa/Douala',
    serviceWorkers: 'allow',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Navigateur : variable PLAYWRIGHT_CHROMIUM_EXECUTABLE, Chromium préinstallé ou navigateur
        // de Playwright (voir tests/e2e/support/chromium.ts). Jamais de téléchargement ici.
        launchOptions: chromiumLaunchOptions(),
      },
    },
  ],
  // Application Laravel construite (public/build) servie sur une seule origine, avec la base de test
  // isolée et aucun fournisseur réel (fichier .env.e2e généré, jamais versionné). Serveur intégré
  // comme « artisan serve » (répertoire public/, routeur de Laravel), avec OPcache pour ce seul
  // processus (tests/e2e/support/php.ts).
  webServer: {
    command: `node tests/e2e/prepare-env.mjs && cd public && php ${phpServerOptions().join(' ')} -S 127.0.0.1:${MAIN_PORT} ../${LARAVEL_ROUTER}`,
    url: `${BASE_URL}/up`,
    reuseExistingServer: !process.env['CI'],
    // La préparation remet à zéro et migre toute la base (117 tables, 464 FK) : environ 35 s sur un
    // poste Windows à disque lent, plus de trois minutes une fois sous forte charge (2026-10-15), d'où
    // une marge large ; Playwright n'attend que le temps nécessaire.
    timeout: 300_000,
    env: { APP_ENV: 'e2e' },
  },
})
