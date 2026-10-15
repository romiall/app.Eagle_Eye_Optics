import { afterEach, describe, expect, it } from 'vitest'

import { isUlid, newUlid, resetUlidMonotonicityForTests } from '@/domain/values/ulid'

const CONTRACT_PATTERN = /^(?!0{26}$)[0-9A-HJKMNP-TV-Z]{26}$/

function fixedRandom(fill: number) {
  return (bytes: Uint8Array<ArrayBuffer>) => {
    bytes.fill(fill)
    return bytes
  }
}

afterEach(() => resetUlidMonotonicityForTests())

describe('ULID du poste (contrat F06 common.json#/$defs/Ulid)', () => {
  it('encode le temps comme la spécification ULID (exemple de référence)', () => {
    // Exemple de la spécification : 1469918176385 ms → préfixe « 01ARYZ6S41 ».
    expect(newUlid(1469918176385, fixedRandom(0)).slice(0, 10)).toBe('01ARYZ6S41')
    resetUlidMonotonicityForTests()
    expect(newUlid(0, fixedRandom(1)).slice(0, 10)).toBe('0000000000')
  })

  it('respecte le motif du contrat et n’est jamais l’identifiant nul', () => {
    for (let index = 0; index < 200; index += 1) {
      const ulid = newUlid()
      expect(ulid).toMatch(CONTRACT_PATTERN)
      expect(isUlid(ulid)).toBe(true)
    }
    expect(isUlid('00000000000000000000000000')).toBe(false)
    expect(isUlid('01ARYZ6S41TSV4RRFFQ69G5FAI')).toBe(false) // « I » exclu de Crockford
    expect(isUlid('01arYZ6S41TSV4RRFFQ69G5FAV')).toBe(false) // minuscules refusées
  })

  it('reste strictement croissant dans une même milliseconde', () => {
    const first = newUlid(1_760_000_000_000, fixedRandom(7))
    const second = newUlid(1_760_000_000_000, fixedRandom(7))
    const third = newUlid(1_760_000_000_000, fixedRandom(7))
    expect(second > first).toBe(true)
    expect(third > second).toBe(true)
    expect(second.slice(0, 10)).toBe(first.slice(0, 10))
  })

  it('refuse une horloge hors bornes plutôt que de produire un identifiant faux', () => {
    expect(() => newUlid(-1)).toThrow()
    expect(() => newUlid(2 ** 48)).toThrow()
    expect(() => newUlid(1.5)).toThrow()
  })

  it('refuse de déborder dans la même milliseconde (aucun doublon)', () => {
    const saturated = (bytes: Uint8Array<ArrayBuffer>) => {
      bytes.fill(31)
      return bytes
    }
    newUlid(1_760_000_000_001, saturated)
    expect(() => newUlid(1_760_000_000_001, saturated)).toThrow()
  })
})
