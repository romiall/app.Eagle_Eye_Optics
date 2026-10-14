// @vitest-environment node
// Contrat OpenAPI 3.1 (F06) : document valide selon le schéma officiel OAS 3.1, références vers les
// JSON Schema de contracts/ résolues, cohérence avec les commandes du journal local, les permissions
// seedées et le backlog. La correspondance des routes IMPLEMENTED avec Laravel est vérifiée en PHP
// (tests/Contracts/OpenApiRoutesTest.php).
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'

import { Ajv2020 } from 'ajv/dist/2020.js'
import { describe, expect, it } from 'vitest'
import { parse } from 'yaml'

const ROOT = join(import.meta.dirname, '..', '..')
const CONTRACTS = join(ROOT, 'contracts')
const METHODS = ['get', 'post', 'put', 'patch', 'delete'] as const

type Json = Record<string, unknown>

interface Operation extends Json {
  operationId: string
  parameters?: { $ref?: string; name?: string }[]
  'x-permission'?: string[]
  'x-idempotency': 'required' | 'none'
  'x-offline-command'?: string
  'x-implementation-status': 'IMPLEMENTED' | 'PLANNED'
  'x-backlog-task': string
}

const readJson = (path: string): unknown => JSON.parse(readFileSync(path, 'utf8')) as unknown
const document = parse(readFileSync(join(CONTRACTS, 'openapi.yaml'), 'utf8')) as Json
const paths = document.paths as Record<string, Record<string, unknown>>

const operations: { method: string; path: string; operation: Operation }[] = Object.entries(
  paths,
).flatMap(([path, item]) =>
  METHODS.filter((method) => method in item).map((method) => ({
    method,
    path,
    operation: item[method] as Operation,
  })),
)

function collectRefs(node: unknown, refs: string[] = []): string[] {
  if (Array.isArray(node)) {
    node.forEach((item) => collectRefs(item, refs))
  } else if (node !== null && typeof node === 'object') {
    for (const [key, value] of Object.entries(node)) {
      if (key === '$ref' && typeof value === 'string') {
        refs.push(value)
      } else {
        collectRefs(value, refs)
      }
    }
  }
  return refs
}

function resolvePointer(target: unknown, pointer: string): unknown {
  return pointer
    .split('/')
    .filter((segment) => segment !== '')
    .map((segment) => segment.replaceAll('~1', '/').replaceAll('~0', '~'))
    .reduce<unknown>((node, segment) => {
      if (node !== null && typeof node === 'object' && segment in node) {
        return (node as Json)[segment]
      }
      return undefined
    }, target)
}

function seededPermissions(): Set<string> {
  const statements = readJson(join(ROOT, 'database', 'baseline', 'statements.json')) as {
    steps: { '03_reference_data': { table: string; sql: string }[] }
  }
  const sql =
    statements.steps['03_reference_data'].find((step) => step.table === 'permissions')?.sql ?? ''
  return new Set([...sql.matchAll(/\('([a-z_.]+)', '/g)].map((match) => match[1] as string))
}

describe('contrat OpenAPI 3.1', () => {
  it('est valide selon le schéma officiel OpenAPI 3.1', () => {
    const ajv = new Ajv2020({ strict: false, validateFormats: false, allErrors: true })
    const official = readFileSync(
      join(
        ROOT,
        'node_modules',
        '@apidevtools',
        'openapi-schemas',
        'schemas',
        'v3.1',
        'schema.json',
      ),
      'utf8',
    )
    // Le schéma officiel désigne les objets Schema par {"$dynamicRef": "#meta"}, ancre portée par
    // #/$defs/schema (tout objet ou booléen). Ajv résout mal cette ancre dynamique (portée racine) : la
    // référence statique équivalente vers #/$defs/schema est utilisée à la place, sans élargir le contrôle
    // du reste du document.
    const schema = JSON.parse(
      official
        .replaceAll('{"$dynamicRef":"#meta"}', '{"$ref":"#/$defs/schema"}')
        .replaceAll(/"\$dynamicRef":\s*"#meta"/g, '"$ref": "#/$defs/schema"'),
    ) as object
    const validate = ajv.compile(schema)
    expect(validate(document), JSON.stringify(validate.errors?.slice(0, 5))).toBe(true)
    expect(document.openapi).toBe('3.1.0')

    // Contrôles négatifs : le validateur refuse bien un document altéré (titre obligatoire absent,
    // paramètre sans emplacement valide). En 3.1, « responses » n'est plus obligatoire.
    const withoutTitle = structuredClone(document) as { info: Json }
    delete withoutTitle.info.title
    expect(validate(withoutTitle)).toBe(false)
    const badParameter = structuredClone(document) as {
      paths: Record<string, Record<string, Json>>
    }
    const staffShow = badParameter.paths['/v1/staff/{id}']?.get as { parameters: Json[] }
    staffShow.parameters = [{ name: 'id', in: 'corps', required: true, schema: { type: 'string' } }]
    expect(validate(badParameter)).toBe(false)
  })

  it('résout chaque référence vers contracts/ et chaque référence interne', () => {
    const unresolved: string[] = []
    for (const reference of collectRefs(document)) {
      const [file, pointer = ''] = reference.split('#')
      if (file === '' || file === undefined) {
        if (resolvePointer(document, pointer) === undefined) {
          unresolved.push(reference)
        }
        continue
      }
      const path = join(CONTRACTS, file)
      if (!existsSync(path) || resolvePointer(readJson(path), pointer) === undefined) {
        unresolved.push(reference)
      }
    }
    expect(unresolved).toEqual([])
  })

  it('a des operationId uniques et des annotations x-* déclarées', () => {
    const ids = operations.map(({ operation }) => operation.operationId)
    expect(new Set(ids).size).toBe(ids.length)

    const vocabulary = readJson(join(CONTRACTS, 'vocabulary.json')) as {
      openapi: Record<string, string>
    }
    const used = new Set(
      operations.flatMap(({ operation }) =>
        Object.keys(operation).filter((key) => key.startsWith('x-')),
      ),
    )
    expect([...used].filter((key) => !(key in vocabulary.openapi))).toEqual([])
  })

  it('associe chaque commande du journal local à sa route directe', () => {
    const index = (
      readJson(join(CONTRACTS, 'commands', 'index.json')) as {
        const: Record<string, { direct_route: string; permission: string }>
      }
    ).const
    for (const [name, entry] of Object.entries(index)) {
      const [method = '', route = ''] = entry.direct_route.split(' ')
      const operation = paths[`/v1${route}`]?.[method.toLowerCase()] as Operation | undefined
      expect(operation?.['x-offline-command'], `${name} → ${entry.direct_route}`).toBe(name)
      expect(operation?.['x-permission'] ?? [], `${name} : permission de la commande`).toContain(
        entry.permission,
      )
      expect(operation?.['x-idempotency'], `${name} : idempotence`).toBe('required')
    }
    const offline = new Set(
      operations.map(({ operation }) => operation['x-offline-command']).filter(Boolean),
    )
    expect([...offline].filter((name) => !((name as string) in index))).toEqual([])
  })

  it("n'emploie que les permissions seedées et des tâches du backlog", () => {
    const permissions = seededPermissions()
    expect(permissions.size).toBe(49)
    const backlog = readJson(join(ROOT, 'specifications', 'developpement', 'backlog.json')) as {
      tasks: { id: string }[]
    }
    const tasks = new Set(backlog.tasks.map((task) => task.id))
    for (const { method, path, operation } of operations) {
      for (const permission of operation['x-permission'] ?? []) {
        expect(permissions.has(permission), `${method} ${path} : ${permission}`).toBe(true)
      }
      expect(
        tasks.has(operation['x-backlog-task']),
        `${method} ${path} : ${operation['x-backlog-task']}`,
      ).toBe(true)
      expect(['IMPLEMENTED', 'PLANNED']).toContain(operation['x-implementation-status'])
    }
  })

  it("exige Idempotency-Key sur chaque mutation idempotente et une réponse d'erreur publiée", () => {
    for (const { method, path, operation } of operations) {
      const hasKey = (operation.parameters ?? []).some(
        (parameter) => parameter.$ref === '#/components/parameters/IdempotencyKey',
      )
      expect(hasKey, `${method} ${path}`).toBe(operation['x-idempotency'] === 'required')
    }
    expect(dirname(join(CONTRACTS, 'schemas', 'error.json'))).toContain('schemas')
  })
})
