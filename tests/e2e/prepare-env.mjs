// Génère .env.e2e (ignoré par git) pour le serveur de test des e2e : environnement « e2e », base de
// test isolée, aucun fournisseur réel. Aucune valeur n'est un secret : la clé d'application est
// aléatoire à chaque exécution et rien n'est versionné.
import { randomBytes } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const port = process.env.EEO_E2E_PORT ?? '8123'

if (!existsSync(join(root, 'public', 'build', 'manifest.json'))) {
  console.error(
    'Build absent : lancer « npm run build » avant les tests e2e (ou « npm run test:e2e »).',
  )
  process.exit(1)
}

const overrides = {
  APP_ENV: 'e2e',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_DEBUG: 'false',
  APP_URL: `http://127.0.0.1:${port}`,
  DB_HOST: process.env.EEO_TEST_DB_HOST ?? '127.0.0.1',
  DB_PORT: process.env.EEO_TEST_DB_PORT ?? '3306',
  DB_DATABASE: process.env.EEO_TEST_DB_DATABASE ?? 'eeo_test',
  DB_USERNAME: process.env.EEO_TEST_DB_USERNAME ?? 'eeo_test',
  DB_PASSWORD: process.env.EEO_TEST_DB_PASSWORD ?? 'eeo_test_password',
  LOG_CHANNEL: 'stderr',
  LOG_LEVEL: 'warning',
  MAIL_MAILER: 'array',
  EEO_INTEGRATIONS_MODE: 'fake',
  PRODUCTION_DOCUMENTS_ENABLED: 'false',
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${port}`,
}

const lines = readFileSync(join(root, '.env.example'), 'utf8')
  .split('\n')
  .map((line) => {
    const match = /^([A-Z0-9_]+)=/.exec(line)
    const key = match?.[1]
    return key && key in overrides ? `${key}=${overrides[key]}` : line
  })

writeFileSync(join(root, '.env.e2e'), `${lines.join('\n')}\n`, { mode: 0o600 })
console.log(`.env.e2e généré (APP_ENV=e2e, base ${overrides.DB_DATABASE}, port ${port}).`)
