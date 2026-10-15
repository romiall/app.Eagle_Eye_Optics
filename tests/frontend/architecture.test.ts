// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

/**
 * Architecture des lectures et écritures locales (contrat 23, F10) : un écran ou un composant
 * n'écrit jamais dans Dexie et n'importe rien du moteur local ; il passe par les services
 * d'application. La règle ESLint est vérifiée sur du code d'exemple, et le code réel est relu.
 */
const ROOT = join(import.meta.dirname, '..', '..')

async function lint(path: string, code: string): Promise<string[]> {
  const eslint = new ESLint({ cwd: ROOT })
  const [result] = await eslint.lintText(code, { filePath: join(ROOT, path) })
  return (result?.messages ?? [])
    .filter((message) => message.ruleId === 'no-restricted-imports')
    .map((message) => message.message)
}

function files(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    if (statSync(path).isDirectory()) return files(path)
    return /\.(?:ts|vue)$/.test(entry) ? [path] : []
  })
}

describe('écrans et composants : aucun accès direct au moteur local', () => {
  it('refuse Dexie et le moteur local dans une page ou un composant', async () => {
    expect(
      await lint(
        'resources/frontend/pages/Exemple.ts',
        "import Dexie from 'dexie'\nexport const d = Dexie\n",
      ),
    ).toHaveLength(1)
    expect(
      await lint(
        'resources/frontend/components/Exemple.ts',
        "import { mergeById } from '@/offline/projections/merge'\nexport const m = mergeById\n",
      ),
    ).toHaveLength(1)
  }, 60_000)

  it('laisse les services d’application lire les projections, jamais les tables', async () => {
    expect(
      await lint(
        'resources/frontend/app/services/Exemple.ts',
        "import { mergeById } from '@/offline/projections/merge'\nexport const m = mergeById\n",
      ),
    ).toEqual([])
    expect(
      await lint(
        'resources/frontend/app/services/Exemple.ts',
        "import Dexie from 'dexie'\nexport const d = Dexie\n",
      ),
    ).toHaveLength(1)
    expect(
      await lint(
        'resources/frontend/app/services/Exemple.ts',
        "import { db } from '@/offline/db'\nexport const d = db\n",
      ),
    ).toHaveLength(1)
  }, 60_000)

  it('aucune page ni aucun composant réel n’importe Dexie ou le moteur local', () => {
    const offenders = ['pages', 'components']
      .flatMap((folder) => files(join(ROOT, 'resources', 'frontend', folder)))
      .filter((path) =>
        /from\s+['"](?:dexie|@\/offline\/[^'"]*)['"]/.test(readFileSync(path, 'utf8')),
      )
      .map((path) => relative(ROOT, path))
    expect(offenders).toEqual([])
  })
})
