// @vitest-environment node
// Vecteurs communs des règles pures (F08, IT03) côté TypeScript : mêmes fichiers que
// tests/Unit/Rules/RuleVectorsTest.php (PHP). Résultat exact attendu, ou refus avec le même code
// d'API, le même champ, la même règle et les mêmes détails. Un verdict différent est un défaut.
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

import { Ajv2020 } from 'ajv/dist/2020.js'
import { describe, expect, it } from 'vitest'

import { RuleViolation } from '@/domain/errors'
import { RULES_VERSION } from '@/domain/rulesVersion'

import { runVectorOperation } from './vectorOperations'

const CONTRACTS = join(import.meta.dirname, '..', '..', '..', 'contracts')
const VECTORS = join(CONTRACTS, 'vectors')

interface Refusal {
  code: string
  field: string
  rule: string
  details?: Record<string, unknown>
}

interface VectorCase {
  id: string
  operation: string
  input: Record<string, unknown>
  expected?: unknown
  error?: Refusal
}

interface VectorFile {
  rules_version: string
  cases: VectorCase[]
}

const files = readdirSync(VECTORS)
  .filter((name) => name.endsWith('.json'))
  .sort()
const documents = files.map((name) => ({
  name,
  document: JSON.parse(readFileSync(join(VECTORS, name), 'utf8')) as VectorFile,
}))
const cases = documents.flatMap(({ document }) => document.cases)

describe('format des vecteurs de règles', () => {
  it('chaque fichier respecte le schéma et la version des règles', () => {
    expect(files.length).toBeGreaterThanOrEqual(9)
    const ajv = new Ajv2020({ strict: true, allErrors: true, allowUnionTypes: true })
    const validate = ajv.compile(
      JSON.parse(readFileSync(join(CONTRACTS, 'schemas', 'rule-vectors.json'), 'utf8')) as object,
    )
    const compatibility = JSON.parse(
      readFileSync(join(CONTRACTS, 'compatibility.json'), 'utf8'),
    ) as { rules_versions: string[] }

    for (const { name, document } of documents) {
      expect(validate(document), `${name} : ${JSON.stringify(validate.errors)}`).toBe(true)
      expect(document.rules_version, name).toBe(RULES_VERSION)
    }
    expect(compatibility.rules_versions).toContain(RULES_VERSION)
    expect(new Set(cases.map((item) => item.id)).size).toBe(cases.length)
  })
})

describe('vecteurs rejoués par les règles TypeScript', () => {
  it.each(cases.map((item) => [item.id, item] as const))('%s', (_id, item) => {
    let result: unknown
    try {
      result = runVectorOperation(item.operation, item.input)
    } catch (error) {
      if (!(error instanceof RuleViolation)) throw error
      expect(item.error, `refus inattendu ${error.code} ${error.field} ${error.rule}`).toBeDefined()
      const expected = item.error as Refusal
      expect([error.code, error.field, error.rule]).toEqual([
        expected.code,
        expected.field,
        expected.rule,
      ])
      if (expected.details !== undefined) expect(error.details).toStrictEqual(expected.details)
      return
    }
    expect(item.error, `refus attendu, résultat obtenu ${JSON.stringify(result)}`).toBeUndefined()
    expect(result).toStrictEqual(item.expected)
  })
})
