// @vitest-environment node
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import { canonicalHash, canonicalJson, sha256Hex, stepsDigest } from '@/domain/clinical/snapshot'

// Empreinte des rubriques d'une consultation (C03, hypothèse « G8 ») : JCS + SHA-256, jumeau de
// app/Domain/Clinical/RevisionHash.php. Les vecteurs communs sont rejoués à l'identique par PHPUnit
// (tests/Unit/Clinical/RevisionHashTest.php). Données FICTIVES.
const ROOT = join(import.meta.dirname, '..', '..', '..')

interface HashCase {
  id: string
  input: unknown
  canonical: string
  sha256: string
}

const vectors = JSON.parse(
  readFileSync(join(ROOT, 'contracts', 'vectors', 'clinical', 'snapshot-hash.json'), 'utf8'),
) as { cases: HashCase[] }

describe('vecteurs communs de l’empreinte clinique', () => {
  it('le fichier de vecteurs couvre les cas attendus', () => {
    expect(vectors.cases.length).toBeGreaterThanOrEqual(8)
  })

  it.each(vectors.cases.map((item) => [item.id, item] as const))(
    '%s : même forme canonique et même empreinte que le serveur',
    async (_id, item) => {
      expect(canonicalJson(item.input)).toBe(item.canonical)
      expect(await canonicalHash(item.input)).toBe(item.sha256)
    },
  )
})

describe('forme canonique', () => {
  it('trie les clés à tous les niveaux et garde l’ordre des listes', () => {
    expect(canonicalJson({ b: 1, a: { y: null, x: true } })).toBe('{"a":{"x":true,"y":null},"b":1}')
    expect(canonicalJson([2, 1])).not.toBe(canonicalJson([1, 2]))
  })

  it('refuse un nombre non entier plutôt que de produire une empreinte ambiguë', () => {
    expect(() => canonicalJson({ sphere: -1.25 })).toThrow(/entier/)
    expect(() => canonicalJson(Number.NaN)).toThrow()
  })

  it('refuse un type non pris en charge', () => {
    expect(() => canonicalJson(undefined)).toThrow()
    expect(() => canonicalJson(() => 1)).toThrow()
  })
})

describe('SHA-256', () => {
  it('rend l’empreinte hexadécimale minuscule d’un texte UTF-8', async () => {
    expect(await sha256Hex('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
    expect(await sha256Hex('é')).toMatch(/^[0-9a-f]{64}$/)
  })

  it('l’empreinte des rubriques est celle de leur forme canonique', async () => {
    const steps = {
      CONCLUSION: { conclusion: 'Fin (fictif)', referral: false, referral_urgency: null, referral_reasons: [] },
    }

    expect(await stepsDigest(steps)).toBe(
      await sha256Hex(
        '{"CONCLUSION":{"conclusion":"Fin (fictif)","referral":false,"referral_reasons":[],"referral_urgency":null}}',
      ),
    )
  })
})
