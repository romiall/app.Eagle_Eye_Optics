// @vitest-environment node
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  COMMAND_COMPARTMENTS,
  compartmentsOf,
  PERMISSION_COMPARTMENTS,
} from '@/offline/compartments'
import { fromBase64Url, toBase64Url, toHex, utf8 } from '@/offline/crypto/bytes'
import { canonicalize, JcsError } from '@/offline/signing/jcs'
import {
  JwsRefused,
  type PublicJwk,
  signCompact,
  thumbprint,
  verifyCompact,
} from '@/offline/signing/jws'

/**
 * JCS, JWS ES256 et compartiments contre les contrats COMMUNS au serveur (`contracts/vectors/signatures/`,
 * `contracts/compartments.json`) : même forme canonique et même empreinte que PHP, jeton Web Crypto vérifié, variantes
 * altérées refusées, classement des permissions et des commandes identique au JSON.
 */
const ROOT = join(import.meta.dirname, '..', '..', '..')

function json<T>(path: string): T {
  return JSON.parse(readFileSync(join(ROOT, path), 'utf8')) as T
}

interface JcsVectors {
  cases: { name: string; input: string; canonical: string; sha256: string }[]
  refused: { name: string; input: string }[]
}

interface GrantVector {
  public_jwk: PublicJwk
  kid: string
  claims: Record<string, unknown>
  jws: string
}

async function sha256(text: string): Promise<string> {
  return toHex(
    new Uint8Array(await crypto.subtle.digest('SHA-256', utf8(text) as Uint8Array<ArrayBuffer>)),
  )
}

describe('JCS (RFC 8785), vecteurs communs PHP / navigateur', () => {
  const vectors = json<JcsVectors>('contracts/vectors/signatures/jcs.json')

  it.each(vectors.cases.map((item) => [item.name, item]))('%s', async (_name, item) => {
    const canonical = canonicalize(JSON.parse(item.input))
    expect(canonical).toBe(item.canonical)
    expect(await sha256(canonical)).toBe(item.sha256)
  })

  it.each(vectors.refused.map((item) => [item.name, item.input]))('refuse : %s', (_name, input) => {
    expect(() => canonicalize(JSON.parse(input))).toThrow(JcsError)
  })

  it('refuse une demi-paire de substitution (texte Unicode invalide)', () => {
    expect(() => canonicalize({ x: '\ud800' })).toThrow(JcsError)
    expect(() => canonicalize({ '\udc00': 1 })).toThrow(JcsError)
  })
})

describe('JWS compact ES256', () => {
  const vector = json<GrantVector>('contracts/vectors/signatures/grant-es256.json')
  const type = 'eeo-offline-grant+jws'

  it('vérifie le jeton du vecteur commun et retrouve sa charge et son kid', async () => {
    expect(await verifyCompact(vector.jws, vector.public_jwk, vector.kid, type)).toEqual(
      vector.claims,
    )
    expect(await thumbprint(vector.public_jwk)).toBe(vector.kid)
  })

  it('signe un jeton de la forme du contrat, vérifiable', async () => {
    const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, [
      'sign',
      'verify',
    ])
    const exported = await crypto.subtle.exportKey('jwk', pair.publicKey)
    const jwk: PublicJwk = { kty: 'EC', crv: 'P-256', x: String(exported.x), y: String(exported.y) }
    const kid = await thumbprint(jwk)

    const jws = await signCompact({ b: 2, a: 'é' }, pair.privateKey, kid, type)
    const [header = '', payload = '', signature = ''] = jws.split('.')

    expect(new TextDecoder().decode(fromBase64Url(header))).toBe(
      `{"alg":"ES256","kid":"${kid}","typ":"${type}"}`,
    )
    expect(new TextDecoder().decode(fromBase64Url(payload))).toBe('{"a":"é","b":2}')
    expect(fromBase64Url(signature)).toHaveLength(64)
    expect(await verifyCompact(jws, jwk, kid, type)).toEqual({ a: 'é', b: 2 })
  })

  const [header = '', payload = '', signature = ''] = vector.jws.split('.')
  const encode = (value: unknown): string => toBase64Url(utf8(canonicalize(value)))
  const tampered: [string, string, (string | undefined)?, string?][] = [
    ['alg=none', `${encode({ alg: 'none', kid: vector.kid, typ: type })}.${payload}.`],
    [
      'autre algorithme',
      `${encode({ alg: 'HS256', kid: vector.kid, typ: type })}.${payload}.${signature}`,
    ],
    [
      'clé embarquée',
      `${encode({ alg: 'ES256', jwk: vector.public_jwk, kid: vector.kid, typ: type })}.${payload}.${signature}`,
    ],
    [
      'charge altérée',
      `${header}.${encode({ ...vector.claims, valid_until: '2099-01-01T00:00:00.000Z' })}.${signature}`,
    ],
    [
      'charge non canonique',
      `${header}.${toBase64Url(utf8(JSON.stringify(vector.claims, null, 2)))}.${signature}`,
    ],
    ['signature tronquée', `${header}.${payload}.${signature.slice(0, 40)}`],
    ['segments manquants', `${header}.${payload}`],
    ['type inattendu', vector.jws, 'eeo-device-proof+jws'],
    ['kid inconnu', vector.jws, undefined, 'A'.repeat(43)],
  ]

  it.each(tampered)('refuse : %s', async (_name, jws, expectedType, expectedKid) => {
    await expect(
      verifyCompact(jws, vector.public_jwk, expectedKid ?? vector.kid, expectedType ?? type),
    ).rejects.toThrow(JwsRefused)
  })

  it('refuse une clé publique substituée', async () => {
    const other = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, [
      'sign',
      'verify',
    ])
    const exported = await crypto.subtle.exportKey('jwk', other.publicKey)
    const jwk: PublicJwk = { kty: 'EC', crv: 'P-256', x: String(exported.x), y: String(exported.y) }

    await expect(verifyCompact(vector.jws, jwk, vector.kid, type)).rejects.toThrow(JwsRefused)
  })
})

describe('compartiments : copie exacte du contrat partagé', () => {
  interface Contract {
    permission_classes: Record<string, { compartment: string | null; permissions: string[] }>
    commands: Record<string, string[]>
  }
  const contract = json<Contract>('contracts/compartments.json')

  it('chaque permission a la même classe que dans le contrat, et seulement elles', () => {
    const expected: Record<string, string | null> = {}
    for (const entry of Object.values(contract.permission_classes)) {
      for (const permission of entry.permissions) expected[permission] = entry.compartment
    }
    expect({ ...PERMISSION_COMPARTMENTS }).toEqual(expected)
    expect(Object.keys(expected)).toHaveLength(49)
  })

  it('chaque commande du catalogue est scellée dans le compartiment du contrat', () => {
    const expected: Record<string, string> = {}
    for (const [compartment, names] of Object.entries(contract.commands)) {
      for (const name of names) expected[name] = compartment
    }
    expect({ ...COMMAND_COMPARTMENTS }).toEqual(expected)
    const catalog = readdirSync(join(ROOT, 'contracts', 'commands'))
      .filter((file) => file.endsWith('.v1.json'))
      .map((file) => file.replace('.v1.json', ''))
      .sort()
    expect(Object.keys(expected).sort()).toEqual(catalog)
  })

  it('les rôles ouvrent les compartiments attendus ; les frais d’examen ne donnent pas la finance', () => {
    expect(compartmentsOf(['patient.read', 'visit.register', 'exam_fee.collect'])).toEqual([
      'ADMINISTRATIVE',
    ])
    expect(compartmentsOf(['patient.read', 'clinical.read'])).toEqual([
      'ADMINISTRATIVE',
      'CLINICAL',
    ])
    expect(compartmentsOf(['patient.read', 'invoice.read', 'cash.own_session'])).toEqual([
      'ADMINISTRATIVE',
      'FINANCIAL',
    ])
    expect(compartmentsOf(['workshop.manage', 'inconnue'])).toEqual([])
  })
})
