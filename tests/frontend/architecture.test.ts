// @vitest-environment node
import { join } from 'node:path'

import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

/**
 * Architecture des lectures et écritures locales (contrat 23, F10) : un écran ou un composant
 * n'écrit jamais dans Dexie et n'importe rien du moteur local ; il passe par les services
 * d'application. Les services lisent les projections du moteur, jamais ses tables ni son journal ;
 * les règles pures et les modèles de documents ignorent le stockage. Seul `offline/` accède à
 * IndexedDB. La règle ESLint est éprouvée sur du code d'exemple (imports statiques, dynamiques,
 * relatifs, accès global à IndexedDB), puis lancée sur le code réel.
 */
const ROOT = join(import.meta.dirname, '..', '..')
const eslint = new ESLint({ cwd: ROOT, errorOnUnmatchedPattern: false })

const RESTRICTION_RULES = new Set([
  'no-restricted-imports',
  'no-restricted-syntax',
  'no-restricted-globals',
])

async function violations(path: string, code: string): Promise<string[]> {
  const [result] = await eslint.lintText(code, { filePath: join(ROOT, path) })
  return (result?.messages ?? [])
    .filter((message) => message.ruleId !== null && RESTRICTION_RULES.has(message.ruleId))
    .map((message) => message.message)
}

const SCREEN = 'resources/frontend/pages/Exemple.ts'
const COMPONENT = 'resources/frontend/components/Exemple.ts'
const SERVICE = 'resources/frontend/app/services/Exemple.ts'
const RULE = 'resources/frontend/domain/billing/Exemple.ts'
const TEMPLATE = 'resources/frontend/documents/Exemple.ts'
const ENGINE = 'resources/frontend/offline/Exemple.ts'

const ENGINE_ACCESS = [
  ["import Dexie from 'dexie'\nexport const d = Dexie\n", 'Dexie'],
  ["import d from 'dexie/dist/dexie.mjs'\nexport const x = d\n", 'sous-chemin de Dexie'],
  ["import { m } from '@/offline/projections/merge'\nexport const x = m\n", 'projection (alias)'],
  [
    "import { m } from '../../offline/projections/merge'\nexport const x = m\n",
    'projection (relatif)',
  ],
  ["import { m } from '@/offline'\nexport const x = m\n", 'point d’entrée du moteur'],
  ["import { t } from '@/offline/vault/tables'\nexport const x = t\n", 'tables du coffre'],
  ["export const load = () => import('@/offline/projections/merge')\n", 'import dynamique (alias)'],
  ["export const load = () => import('../../offline/journal')\n", 'import dynamique (relatif)'],
  ["export const load = () => import('dexie')\n", 'import dynamique de Dexie'],
  ["export const open = () => indexedDB.open('eeo')\n", 'IndexedDB global'],
] as const

describe('écrans et composants : aucun accès direct au moteur local', () => {
  it.each(ENGINE_ACCESS)(
    'refuse dans une page : %#',
    async (code, what) => {
      expect((await violations(SCREEN, code)).length, what).toBeGreaterThan(0)
    },
    60_000,
  )

  it.each(ENGINE_ACCESS)(
    'refuse dans un composant : %#',
    async (code, what) => {
      expect((await violations(COMPONENT, code)).length, what).toBeGreaterThan(0)
    },
    60_000,
  )

  it('laisse passer l’accès par les services d’application', async () => {
    expect(
      await violations(
        SCREEN,
        "import { useRead } from '@/app/services/reads'\nimport { mergeFacts } from '@/app/services/projections'\nexport const r = [useRead, mergeFacts]\n",
      ),
    ).toEqual([])
  }, 60_000)
})

describe('services d’application : projections oui, tables et journal non', () => {
  it.each([
    ["import { m } from '@/offline/projections/merge'\nexport const x = m\n"],
    ["import { m } from '@/offline/projections/ledger'\nexport const x = m\n"],
    ["import { m } from '../../offline/projections/merge'\nexport const x = m\n"],
    ["import type { F } from '@/offline/projections/merge'\nexport type X = F\n"],
    ["export const load = () => import('@/offline/projections/merge')\n"],
  ])(
    'lit les projections : %#',
    async (code) => {
      expect(await violations(SERVICE, code)).toEqual([])
    },
    60_000,
  )

  it.each(ENGINE_ACCESS.filter(([code]) => !code.includes('projections')))(
    'refuse : %#',
    async (code, what) => {
      expect((await violations(SERVICE, code)).length, what).toBeGreaterThan(0)
    },
    60_000,
  )

  it.each([
    ["import { db } from '@/offline/db'\nexport const d = db\n"],
    ["import { j } from '@/offline/journal'\nexport const x = j\n"],
    ["import { t } from '../../offline/vault/tables'\nexport const x = t\n"],
  ])(
    'refuse les tables et le journal : %#',
    async (code) => {
      expect((await violations(SERVICE, code)).length, code).toBeGreaterThan(0)
    },
    60_000,
  )
})

describe('règles pures et modèles de documents : aucun stockage local', () => {
  it.each([RULE, TEMPLATE])(
    'refuse Dexie, le moteur et IndexedDB dans %s',
    async (path) => {
      for (const [code] of ENGINE_ACCESS)
        expect((await violations(path, code)).length, code).toBeGreaterThan(0)
    },
    120_000,
  )
})

describe('le dossier offline reste le seul à toucher au stockage', () => {
  it('autorise Dexie et IndexedDB dans offline/', async () => {
    expect(
      await violations(
        ENGINE,
        "import Dexie from 'dexie'\nexport const d = new Dexie('eeo')\nexport const raw = () => indexedDB.open('eeo')\n",
      ),
    ).toEqual([])
  }, 60_000)
})

describe('code réel', () => {
  it('aucune violation dans les écrans, composants, services, règles et modèles', async () => {
    const results = await eslint.lintFiles([
      'resources/frontend/pages',
      'resources/frontend/components',
      'resources/frontend/app',
      'resources/frontend/domain',
      'resources/frontend/documents',
    ])
    const offenders = results.flatMap((result) =>
      result.messages
        .filter((message) => message.ruleId !== null && RESTRICTION_RULES.has(message.ruleId))
        .map((message) => `${result.filePath}:${message.line} ${message.message}`),
    )
    expect(offenders).toEqual([])
    expect(results.length).toBeGreaterThan(20)
  }, 180_000)
})
