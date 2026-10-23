// @vitest-environment node
import { describe, expect, it } from 'vitest'

import { encodeAad } from '@/offline/crypto/aad'
import { open, seal } from '@/offline/crypto/aead'
import { fromUtf8, utf8 } from '@/offline/crypto/bytes'
import {
  assertKdfParams,
  checkKcv,
  deriveProfileKek,
  KdfParamsRejected,
  makeKcv,
  PRODUCTION_KDF_POLICY,
} from '@/offline/crypto/kdf'
import {
  generatePassphrase,
  generateRecoveryKey,
  parseRecoveryKey,
  secretProblem,
} from '@/offline/crypto/secrets'
import { webCryptoSelfTest } from '@/offline/crypto/selftest'

/**
 * Primitives du coffre (vault.md D3 à D9, D25) : octets d'AAD figés, AES-GCM avec AAD obligatoire, politique de
 * dérivation, témoin KCV, secret local, clé de récupération et auto-test Web Crypto. Environnement Node (Web Crypto
 * de Node 20+).
 */
describe('AAD (D3)', () => {
  it('encode un tableau JSON figé, but obligatoire, entiers en décimal', () => {
    expect(
      fromUtf8(encodeAad('entity', ['01CLINIC', '01VAULT', 'FINANCIAL', 1, 'invoice', '01ID', 3])),
    ).toBe('["eeo-vault-v1","entity","01CLINIC","01VAULT","FINANCIAL",1,"invoice","01ID",3]')
  })

  it('refuse une chaîne vide, un entier négatif ou non entier et un but inconnu', () => {
    expect(() => encodeAad('entity', [''])).toThrow()
    expect(() => encodeAad('entity', [-1])).toThrow()
    expect(() => encodeAad('entity', [1.5])).toThrow()
    expect(() => encodeAad('autre' as never, [])).toThrow()
  })
})

describe('AES-GCM avec AAD (D7)', () => {
  it('le nonce est généré dans seal, l’AAD est vérifié, le chiffré ne contient pas le clair', async () => {
    const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, [
      'encrypt',
      'decrypt',
    ])
    const sealed = await seal(key, utf8('SENTINELLE-CLAIR'), 'entity', ['a', 1])

    expect(sealed.nonce).toHaveLength(12)
    expect(new TextDecoder('latin1').decode(sealed.ciphertext)).not.toContain('SENTINELLE')
    expect(fromUtf8(await open(key, sealed, 'entity', ['a', 1]))).toBe('SENTINELLE-CLAIR')
    await expect(open(key, sealed, 'entity', ['a', 2])).rejects.toThrow()
    await expect(open(key, sealed, 'document', ['a', 1])).rejects.toThrow()
    const other = await seal(key, utf8('SENTINELLE-CLAIR'), 'entity', ['a', 1])
    expect(other.nonce).not.toEqual(sealed.nonce)
  })
})

describe('dérivation et témoin (D5, D6)', () => {
  it('la politique de production refuse moins de 600 000 itérations et une autre normalisation', () => {
    expect(() =>
      assertKdfParams(
        { salt: 'AAAA', iterations: 599_999, normalization: 'NFKC' },
        PRODUCTION_KDF_POLICY,
      ),
    ).toThrow(KdfParamsRejected)
    expect(() =>
      assertKdfParams(
        { salt: 'AAAA', iterations: 600_000, normalization: 'NFD' as never },
        PRODUCTION_KDF_POLICY,
      ),
    ).toThrow(KdfParamsRejected)
    expect(() =>
      assertKdfParams(
        { salt: 'AAAA', iterations: 600_000, normalization: 'NFKC' },
        PRODUCTION_KDF_POLICY,
      ),
    ).not.toThrow()
  })

  it('le témoin distingue le bon secret, et la normalisation NFKC rend NFC et NFD équivalents', async () => {
    const salt = new Uint8Array(16).fill(7)
    const kek = await deriveProfileKek('Éloïse-secret-fictif', salt, 1000)
    const kcv = await makeKcv(kek, ['vault', 'profile', 1])

    const nfd = await deriveProfileKek('Éloïse-secret-fictif'.normalize('NFD'), salt, 1000)
    const wrong = await deriveProfileKek('Autre-secret-fictif', salt, 1000)
    expect(await checkKcv(nfd, kcv, ['vault', 'profile', 1])).toBe(true)
    expect(await checkKcv(wrong, kcv, ['vault', 'profile', 1])).toBe(false)
    expect(await checkKcv(kek, kcv, ['vault', 'profile', 2])).toBe(false)
  })
})

describe('secret local et clé de récupération (D8, D9)', () => {
  it('refuse un secret court, bordé de blancs, contenant l’identifiant ou trivial', () => {
    expect(secretProblem('court', 'gerante')).toBe('TOO_SHORT')
    expect(secretProblem(' secret-tres-long-fictif', 'gerante')).toBe('EDGE_BLANK')
    expect(secretProblem('gerante-secret-tres-long', 'gerante')).toBe('CONTAINS_LOGIN')
    expect(secretProblem('aaaaaaaaaaaaaaaa', 'gerante')).toBe('TRIVIAL')
    expect(secretProblem('01234567890123', 'gerante')).toBe('TRIVIAL')
    expect(secretProblem('maison-velo-fleuve-42', 'gerante')).toBeNull()
  })

  it('propose 4 mots prononçables de 5 lettres', () => {
    expect(generatePassphrase()).toMatch(
      /^([bcdfghjklmnprstvz][aeiou][bcdfghjklmnprstvz][aeiou][bcdfghjklmnprstvz]-){3}[bcdfghjklmnprstvz][aeiou][bcdfghjklmnprstvz][aeiou][bcdfghjklmnprstvz]$/,
    )
  })

  it('la clé de récupération se relit malgré minuscules, espaces et I/L/O, et son contrôle détecte une faute', async () => {
    const key = await generateRecoveryKey()
    expect(key.text).toMatch(/^([0-9A-HJKMNP-TV-Z]{5}-){10}[0-9A-HJKMNP-TV-Z]{5}$/)

    expect(await parseRecoveryKey(key.text)).toEqual(key.bytes)
    expect(await parseRecoveryKey(key.text.toLowerCase().replace(/-/g, ' '))).toEqual(key.bytes)
    const confusable = key.text.replace(/1/g, 'I').replace(/0/g, 'O')
    expect(await parseRecoveryKey(confusable)).toEqual(key.bytes)
    const first = key.text[0] === 'A' ? 'B' : 'A'
    expect(await parseRecoveryKey(first + key.text.slice(1))).toBeNull()
    expect(await parseRecoveryKey('trop-court')).toBeNull()
  })
})

describe('auto-test Web Crypto (D25)', () => {
  it('réussit sur un environnement conforme', async () => {
    expect(await webCryptoSelfTest()).toBe('ok')
  })
})
