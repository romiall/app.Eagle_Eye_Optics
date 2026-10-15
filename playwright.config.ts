import { defineConfig, devices } from '@playwright/test'

import { chromiumLaunchOptions } from './tests/e2e/support/chromium'
import { MAIN_PORT } from './tests/e2e/support/ports'

const BASE_URL = `http://127.0.0.1:${MAIN_PORT}`

export default defineConfig({
  testDir: 'tests/e2e',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env['CI']),
  retries: process.env['CI'] ? 1 : 0,
  reporter: process.env['CI'] ? [['github'], ['list']] : [['list']],
  outputDir: 'test-results',
  timeout: 30_000,
  expect: { timeout: 10_000 },
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
  // Application Laravel construite (public/build) servie par artisan sur une seule origine, avec la
  // base de test isolée et aucun fournisseur réel (fichier .env.e2e généré, jamais versionné).
  webServer: {
    command: `node tests/e2e/prepare-env.mjs && php artisan serve --host=127.0.0.1 --port=${MAIN_PORT} --no-reload`,
    url: `${BASE_URL}/up`,
    reuseExistingServer: !process.env['CI'],
    // La préparation remet à zéro et migre toute la base (117 tables, 464 FK) : environ 35 s sur un
    // poste Windows à disque lent, d'où une marge large ; Playwright n'attend que le temps nécessaire.
    timeout: 180_000,
    env: { APP_ENV: 'e2e' },
  },
})
