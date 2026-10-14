import { existsSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

import { defineConfig, devices } from '@playwright/test'

const PORT = Number(process.env['EEO_E2E_PORT'] ?? 8123)
const BASE_URL = `http://127.0.0.1:${PORT}`

/**
 * Chromium local : variable PLAYWRIGHT_CHROMIUM_EXECUTABLE, sinon le Chromium préinstallé de
 * /opt/pw-browsers (poste de développement cloud), sinon le navigateur installé par Playwright
 * (CI : « npx playwright install --with-deps chromium »). Aucun téléchargement n'est lancé ici.
 */
function chromiumExecutable(): string | undefined {
  const fromEnvironment = process.env['PLAYWRIGHT_CHROMIUM_EXECUTABLE']
  if (fromEnvironment) return fromEnvironment

  const root = process.env['PLAYWRIGHT_BROWSERS_PATH'] ?? '/opt/pw-browsers'
  if (!existsSync(root)) return undefined

  const candidates = readdirSync(root)
    .filter((name) => /^chromium-\d+$/.test(name))
    .sort()
    .reverse()
    .map((name) => join(root, name, 'chrome-linux', 'chrome'))

  return candidates.find((candidate) => existsSync(candidate))
}

const executablePath = chromiumExecutable()

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
        launchOptions: {
          ...(executablePath ? { executablePath } : {}),
          // Les conteneurs de CI et de développement cloud n'offrent pas le bac à sable de Chromium.
          args: process.env['CI'] || executablePath ? ['--no-sandbox'] : [],
        },
      },
    },
  ],
  // Application Laravel construite (public/build) servie par artisan sur une seule origine, avec la
  // base de test isolée et aucun fournisseur réel (fichier .env.e2e généré, jamais versionné).
  webServer: {
    command: `node tests/e2e/prepare-env.mjs && php artisan serve --host=127.0.0.1 --port=${PORT} --no-reload`,
    url: `${BASE_URL}/up`,
    reuseExistingServer: !process.env['CI'],
    timeout: 60_000,
    env: { APP_ENV: 'e2e' },
  },
})
