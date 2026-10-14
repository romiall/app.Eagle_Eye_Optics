// @vitest-environment node
// Contrats exécutables (F06) côté navigateur/TypeScript : mêmes schémas et mêmes fixtures que
// tests/Contracts/ContractFixturesTest.php (PHP). Un verdict différent entre les deux runtimes est
// un défaut de contrat.
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { Ajv2020 } from 'ajv/dist/2020.js'
import { describe, expect, it } from 'vitest'

const ROOT = join(import.meta.dirname, '..', '..', 'contracts')
const BASE_URI = 'https://eeo.invalid/contracts/'

function walk(directory: string): string[] {
  if (!existsSync(directory)) {
    return []
  }
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    return statSync(path).isDirectory() ? walk(path) : path.endsWith('.json') ? [path] : []
  })
}

function loadJson(path: string): unknown {
  return JSON.parse(readFileSync(path, 'utf8')) as unknown
}

const SCHEMA_DIRECTORIES = ['schemas', 'sync', 'commands', 'events', 'snapshots'] as const

interface Vocabulary {
  json_schema: Record<string, string>
}

const vocabulary = loadJson(join(ROOT, 'vocabulary.json')) as Vocabulary

export function contractValidator(): Ajv2020 {
  const ajv = new Ajv2020({ strict: true, allErrors: true, allowUnionTypes: true })
  // Annotations x-* : documentaires, déclarées une fois (contracts/vocabulary.json) ; tout autre
  // mot-clé inconnu reste refusé par le mode strict.
  ajv.addVocabulary(Object.keys(vocabulary.json_schema))
  for (const directory of SCHEMA_DIRECTORIES) {
    for (const file of walk(join(ROOT, directory))) {
      ajv.addSchema(loadJson(file) as object)
    }
  }
  return ajv
}

interface PatchOperation {
  op: 'add' | 'remove' | 'replace'
  path: string
  value?: unknown
}

interface Fixture {
  schema: string
  valid: boolean
  description: string
  data?: unknown
  base?: string
  patch?: PatchOperation[]
}

type Container = Record<string, unknown> | unknown[]

// Variante d'une fixture acceptée : un seul défaut décrit par un patch JSON (RFC 6902, sous-ensemble
// add/remove/replace), appliqué de la même façon qu'en PHP.
function applyPatch(document: unknown, operations: PatchOperation[]): unknown {
  const holder: Record<string, unknown> = { root: structuredClone(document) }
  for (const operation of operations) {
    expect(['add', 'remove', 'replace']).toContain(operation.op)
    expect(operation.path.startsWith('/')).toBe(true)
    const segments = [
      'root',
      ...operation.path
        .slice(1)
        .split('/')
        .map((segment) => segment.replaceAll('~1', '/').replaceAll('~0', '~')),
    ]
    const key = segments.pop() as string
    let parent: Container = holder
    for (const segment of segments) {
      const next: unknown = Array.isArray(parent) ? parent[Number(segment)] : parent[segment]
      expect(next !== null && typeof next === 'object', `chemin ${operation.path}`).toBe(true)
      parent = next as Container
    }
    if (Array.isArray(parent)) {
      if (operation.op === 'add') {
        parent.splice(key === '-' ? parent.length : Number(key), 0, operation.value)
      } else {
        expect(Number(key) < parent.length, `élément ${operation.path}`).toBe(true)
        if (operation.op === 'remove') {
          parent.splice(Number(key), 1)
        } else {
          parent[Number(key)] = operation.value
        }
      }
    } else {
      if (operation.op !== 'add') {
        expect(Object.hasOwn(parent, key), `propriété ${operation.path}`).toBe(true)
      }
      if (operation.op === 'remove') {
        delete parent[key]
      } else {
        parent[key] = operation.value
      }
    }
  }
  return holder.root
}

const fixtures = walk(join(ROOT, 'fixtures')).sort()

describe('contrats JSON Schema 2020-12 (Ajv, mode strict)', () => {
  it('compile chaque schéma en mode strict, avec un $id qui reflète son chemin', () => {
    const ajv = contractValidator()
    for (const directory of SCHEMA_DIRECTORIES) {
      for (const file of walk(join(ROOT, directory))) {
        const schema = loadJson(file) as { $id?: string; $schema?: string }
        const path = relative(ROOT, file).split('\\').join('/')
        expect(schema.$schema, path).toBe('https://json-schema.org/draft/2020-12/schema')
        expect(schema.$id, path).toBe(`${BASE_URI}${path}`)
        expect(ajv.getSchema(`${BASE_URI}${path}`), path).toBeTypeOf('function')
      }
    }
  })

  it("n'emploie que les annotations x-* déclarées dans contracts/vocabulary.json", () => {
    const declared = new Set(Object.keys(vocabulary.json_schema))
    const used = new Set<string>()
    const collect = (node: unknown): void => {
      if (Array.isArray(node)) {
        node.forEach(collect)
      } else if (node !== null && typeof node === 'object') {
        for (const [key, value] of Object.entries(node)) {
          if (key.startsWith('x-')) {
            used.add(key)
          }
          collect(value)
        }
      }
    }
    for (const directory of SCHEMA_DIRECTORIES) {
      walk(join(ROOT, directory)).forEach((file) => collect(loadJson(file)))
    }
    expect([...used].filter((key) => !declared.has(key))).toEqual([])
  })

  it('dispose de fixtures acceptées et refusées', () => {
    const parsed = fixtures.map((file) => loadJson(file) as Fixture)
    expect(parsed.some((fixture) => fixture.valid)).toBe(true)
    expect(parsed.some((fixture) => !fixture.valid)).toBe(true)
  })

  const ajv = contractValidator()
  it.each(fixtures.map((file) => [relative(join(ROOT, 'fixtures'), file), file]))(
    'fixture %s : verdict attendu',
    (_name, file) => {
      const fixture = loadJson(file) as Fixture
      let data: unknown
      if (fixture.base !== undefined) {
        expect('data' in fixture, 'une variante porte « base » et « patch », pas « data »').toBe(
          false,
        )
        expect(fixture.patch?.length ?? 0).toBeGreaterThan(0)
        const base = loadJson(join(ROOT, 'fixtures', fixture.base)) as Fixture
        expect(base.valid, "la base d'une variante est une fixture acceptée").toBe(true)
        expect(base.base, 'pas de variante de variante').toBeUndefined()
        expect(ajv.getSchema(`${BASE_URI}${base.schema}`)?.(base.data), 'base acceptée').toBe(true)
        data = applyPatch(base.data, fixture.patch ?? [])
      } else {
        expect('data' in fixture, 'champ « data » requis').toBe(true)
        data = fixture.data
      }
      const validate = ajv.getSchema(`${BASE_URI}${fixture.schema}`)
      expect(validate, `schéma ${fixture.schema}`).toBeTypeOf('function')
      const valid = validate?.(data) ?? false
      expect(valid, `${fixture.description} ${JSON.stringify(validate?.errors ?? [])}`).toBe(
        fixture.valid,
      )
    },
  )
})
