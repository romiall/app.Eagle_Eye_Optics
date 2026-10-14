// Types TypeScript des contrats (F06, fiche : « génération des types TS ») : un seul fichier généré,
// resources/frontend/domain/contracts/generated.ts, depuis les JSON Schema de contracts/ (commandes, cœur
// signé, ACK, pull, événements, ressources, requêtes, snapshots, erreurs).
//
// Les règles conditionnelles (if/then/else, not, allOf de règles) ne s'expriment pas en TypeScript : elles
// sont retirées des copies servant à la génération et restent vérifiées à l'exécution par Ajv (mêmes
// schémas, tests/frontend/contracts.test.ts). Chaque définition reçoit pour nom de type son nom de $defs.
//
//   node contracts/tools/generate-types.mjs          # écrit le fichier
//   node contracts/tools/generate-types.mjs --check  # échoue si le fichier est absent ou périmé (CI)
import { existsSync, readdirSync, readFileSync, statSync, writeFileSync, mkdirSync } from 'node:fs'
import { basename, dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { compile } from 'json-schema-to-typescript'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const CONTRACTS = join(ROOT, 'contracts')
const OUT = join(ROOT, 'resources', 'frontend', 'domain', 'contracts', 'generated.ts')
const DIRECTORIES = ['schemas', 'sync', 'commands', 'events', 'snapshots']

const pascal = (text) =>
  text
    .split(/[^A-Za-z0-9]+/)
    .filter(Boolean)
    .map((part) => part[0].toUpperCase() + part.slice(1))
    .join('')

function walk(directory) {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    return statSync(path).isDirectory() ? walk(path) : path.endsWith('.json') ? [path] : []
  })
}

// Membre d'allOf qui n'est qu'une règle de validation (condition, négation, alternative sur des
// propriétés, cohérence du cœur signé) : pas un type.
function isRule(member) {
  const keys = Object.keys(member)
  const alternatives = member.anyOf ?? member.oneOf
  return (
    'if' in member ||
    (keys.length === 1 && keys[0] === 'not') ||
    (keys.every((key) => key === 'anyOf' || key === 'oneOf') &&
      Array.isArray(alternatives) &&
      alternatives.every((alternative) => !('type' in alternative) && !('$ref' in alternative))) ||
    (typeof member.$ref === 'string' && member.$ref.includes('dispatch.json'))
  )
}

// Copie d'un schéma sans les règles non exprimables en TypeScript ; un $ref reste seul (une description
// à côté d'un $ref créerait un type dupliqué).
function typable(node) {
  if (Array.isArray(node)) {
    return node.map(typable)
  }
  if (node === null || typeof node !== 'object') {
    return node
  }
  if (typeof node.$ref === 'string') {
    return { $ref: node.$ref }
  }
  const copy = {}
  for (const [key, value] of Object.entries(node)) {
    if (['if', 'then', 'else', 'not', '$id', '$schema', 'x-entity-type', 'x-entity-id-field', 'x-permission', 'x-expected-version', 'x-direct-route', 'x-number-type'].includes(key)) {
      continue
    }
    if (key === 'allOf') {
      const kept = value.filter((member) => !isRule(member))
      if (kept.length > 0) {
        copy.allOf = typable(kept)
      }
      continue
    }
    copy[key] = typable(value)
  }
  return copy
}

// Nom de type d'un fichier (racine) et de ses définitions.
function titled(path) {
  const schema = typable(JSON.parse(readFileSync(path, 'utf8')))
  const rel = relative(CONTRACTS, path).split('\\').join('/')
  const name = basename(rel, '.json').replace(/\.v\d+$/, '')
  if (rel === 'commands/dispatch.json') {
    delete schema.title
  } else if (rel.startsWith('commands/') && rel !== 'commands/index.json') {
    schema.title = `${name}Payload`
  } else if (rel.startsWith('events/') && rel !== 'events/envelope.json') {
    schema.title = `${name}EventPayload`
  } else if (rel === 'events/envelope.json') {
    schema.title = 'EventEnvelope'
  } else if (rel.startsWith('sync/') || rel.startsWith('snapshots/')) {
    schema.title = pascal(name) + (rel.startsWith('snapshots/') ? 'Snapshot' : '')
  } else if (rel === 'schemas/error.json') {
    schema.title = 'ErrorEnvelope'
  } else if (rel === 'schemas/compatibility.json') {
    schema.title = 'CompatibilityDeclaration'
  } else {
    delete schema.title
  }
  delete schema.description
  for (const [key, definition] of Object.entries(schema.$defs ?? {})) {
    if (rel.startsWith('commands/') && key === 'DirectRequest') {
      definition.title = `${name}DirectRequest`
    } else {
      definition.title = pascal(key)
    }
  }
  return schema
}

const files = DIRECTORIES.flatMap((directory) => walk(join(CONTRACTS, directory))).filter(
  (path) => !path.endsWith('commands/index.json'),
)
const copies = new Map(files.map((path) => [resolve(path), titled(path)]))

// Racine : une propriété par type exporté (racines et définitions de chaque fichier ; dispatch.json ne
// fournit que le type CommandName).
const properties = {}
for (const path of files) {
  const rel = relative(CONTRACTS, path).split('\\').join('/')
  const schema = copies.get(resolve(path))
  const isDispatch = rel === 'commands/dispatch.json'
  if (!isDispatch && (schema.type !== undefined || schema.properties !== undefined || schema.anyOf !== undefined || schema.const !== undefined)) {
    properties[schema.title ?? pascal(rel)] = { $ref: `./${rel}` }
  }
  for (const key of Object.keys(schema.$defs ?? {})) {
    properties[`${pascal(rel)}${pascal(key)}`] = { $ref: `./${rel}#/$defs/${key}` }
  }
}

const banner = [
  '/* eslint-disable */',
  '// Généré par contracts/tools/generate-types.mjs depuis les JSON Schema de contracts/ : ne pas modifier',
  '// à la main. Les règles conditionnelles (if/then, not) sont vérifiées à l\'exécution par Ajv.',
].join('\n')

const output = await compile(
  { title: 'ContractTypes', type: 'object', additionalProperties: false, properties },
  'ContractTypes',
  {
    bannerComment: banner,
    cwd: CONTRACTS,
    declareExternallyReferenced: true,
    additionalProperties: false,
    strictIndexSignatures: true,
    format: false,
    unreachableDefinitions: false,
    $refOptions: {
      resolve: {
        contracts: {
          order: 1,
          canRead: (file) => copies.has(resolve(fileURLToPath(new URL(file.url, 'file://')))),
          read: (file) => JSON.stringify(copies.get(resolve(fileURLToPath(new URL(file.url, 'file://'))))),
        },
      },
    },
  },
)

if (process.argv.includes('--check')) {
  if (!existsSync(OUT) || readFileSync(OUT, 'utf8') !== output) {
    console.error('ECHEC : types des contrats périmés : relancer node contracts/tools/generate-types.mjs')
    process.exit(1)
  }
  console.log('Types des contrats à jour.')
} else {
  mkdirSync(dirname(OUT), { recursive: true })
  writeFileSync(OUT, output)
  console.log(`Types écrits dans ${relative(ROOT, OUT)}`)
}
