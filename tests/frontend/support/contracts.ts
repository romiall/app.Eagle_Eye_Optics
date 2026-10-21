import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

import { Ajv2020 } from 'ajv/dist/2020.js'

/** Contrats F06 chargés une fois dans Ajv (JSON Schema 2020-12, mode strict), pour tous les tests. */
export const CONTRACTS_ROOT = join(import.meta.dirname, '..', '..', '..', 'contracts')
export const CONTRACTS_BASE_URI = 'https://eeo.invalid/contracts/'

export function walkJson(directory: string): string[] {
  if (!existsSync(directory)) {
    return []
  }
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    return statSync(path).isDirectory() ? walkJson(path) : path.endsWith('.json') ? [path] : []
  })
}

export function loadJson(path: string): unknown {
  return JSON.parse(readFileSync(path, 'utf8')) as unknown
}

export const SCHEMA_DIRECTORIES = ['schemas', 'sync', 'commands', 'events', 'snapshots'] as const

export interface Vocabulary {
  json_schema: Record<string, string>
}

/** Annotations `x-*` déclarées une fois pour tous les schémas (contracts/vocabulary.json). */
export const vocabulary = loadJson(join(CONTRACTS_ROOT, 'vocabulary.json')) as Vocabulary

export function contractValidator(): Ajv2020 {
  const ajv = new Ajv2020({ strict: true, allErrors: true, allowUnionTypes: true })
  // Annotations x-* : documentaires, déclarées une fois (contracts/vocabulary.json) ; tout autre
  // mot-clé inconnu reste refusé par le mode strict.
  ajv.addVocabulary(Object.keys(vocabulary.json_schema))
  for (const directory of SCHEMA_DIRECTORIES) {
    for (const file of walkJson(join(CONTRACTS_ROOT, directory))) {
      ajv.addSchema(loadJson(file) as object)
    }
  }
  return ajv
}
