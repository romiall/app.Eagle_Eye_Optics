import { buffer, fromBase64Url, fromUtf8, toBase64Url, utf8 } from '../crypto/bytes'

import { canonicalize } from './jcs'

/**
 * JWS compact ES256 à en-tête et charge JCS, jumeau d'`App\Offline\CompactJws` (fichier 23, « Signature et
 * chiffrement »). Web Crypto produit et vérifie directement la signature JWA R||S de 64 octets : aucune conversion DER.
 *
 * Vérification stricte : trois segments, en-tête EXACTEMENT `{alg, kid, typ}` avec `alg = ES256`, type et `kid`
 * attendus ; aucune clé n'est lue dans le jeton ; charge = objet JSON dont les octets sont sa forme JCS. Le
 * vérificateur reçoit la clé publique épinglée par le poste, jamais une clé fournie par le jeton.
 */
export interface PublicJwk {
  kty: 'EC'
  crv: 'P-256'
  x: string
  y: string
}

export class JwsRefused extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'JwsRefused'
  }
}

export interface JwsDeps {
  subtle?: SubtleCrypto
}

function subtleOf(deps: JwsDeps): SubtleCrypto {
  return deps.subtle ?? globalThis.crypto.subtle
}

/** Empreinte RFC 7638 d'une clé publique EC (base64url du SHA-256 de `{"crv","kty","x","y"}`). */
export async function thumbprint(jwk: PublicJwk, deps: JwsDeps = {}): Promise<string> {
  const canonical = canonicalize({ crv: jwk.crv, kty: jwk.kty, x: jwk.x, y: jwk.y })
  return toBase64Url(
    new Uint8Array(await subtleOf(deps).digest('SHA-256', buffer(utf8(canonical)))),
  )
}

export async function signCompact(
  payload: Record<string, unknown>,
  privateKey: CryptoKey,
  kid: string,
  type: string,
  deps: JwsDeps = {},
): Promise<string> {
  const input = `${toBase64Url(utf8(canonicalize({ alg: 'ES256', kid, typ: type })))}.${toBase64Url(utf8(canonicalize(payload)))}`
  const signature = await subtleOf(deps).sign(
    { name: 'ECDSA', hash: 'SHA-256' },
    privateKey,
    buffer(utf8(input)),
  )
  return `${input}.${toBase64Url(new Uint8Array(signature))}`
}

function decodeObject(
  segment: string,
  what: string,
): { raw: string; value: Record<string, unknown> } {
  let raw: string
  let value: unknown
  try {
    raw = fromUtf8(fromBase64Url(segment))
    value = JSON.parse(raw)
  } catch {
    throw new JwsRefused(`JSON invalide dans ${what}.`)
  }
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new JwsRefused(`Objet JSON attendu dans ${what}.`)
  }
  return { raw, value: value as Record<string, unknown> }
}

/** Vérifie le jeton avec la clé publique attendue et rend sa charge ; lève `JwsRefused` sinon. */
export async function verifyCompact(
  jws: string,
  jwk: PublicJwk,
  expectedKid: string,
  expectedType: string,
  deps: JwsDeps = {},
): Promise<Record<string, unknown>> {
  const segments = jws.split('.')
  if (segments.length !== 3 || segments.some((segment) => segment === '')) {
    throw new JwsRefused('JWS compact attendu (trois segments).')
  }
  const [headerSegment = '', payloadSegment = '', signatureSegment = ''] = segments
  const header = decodeObject(headerSegment, 'en-tête')
  const keys = Object.keys(header.value).sort()
  if (keys.join(',') !== 'alg,kid,typ') throw new JwsRefused('En-tête protégé inattendu.')
  if (header.value['alg'] !== 'ES256') throw new JwsRefused('Algorithme refusé : ES256 exigé.')
  if (header.value['typ'] !== expectedType) throw new JwsRefused('Type de jeton inattendu.')
  if (header.value['kid'] !== expectedKid) throw new JwsRefused('Clé de signature inconnue.')
  if (header.raw !== canonicalize(header.value)) throw new JwsRefused('En-tête non canonique.')

  const payload = decodeObject(payloadSegment, 'charge')
  if (payload.raw !== canonicalize(payload.value))
    throw new JwsRefused('Charge non canonique : JCS exigé.')

  let signature: Uint8Array
  try {
    signature = fromBase64Url(signatureSegment)
  } catch {
    throw new JwsRefused('Signature illisible.')
  }
  if (signature.length !== 64) throw new JwsRefused('Signature ES256 de 64 octets attendue.')

  const subtle = subtleOf(deps)
  let key: CryptoKey
  try {
    key = await subtle.importKey(
      'jwk',
      { kty: jwk.kty, crv: jwk.crv, x: jwk.x, y: jwk.y },
      { name: 'ECDSA', namedCurve: 'P-256' },
      false,
      ['verify'],
    )
  } catch {
    throw new JwsRefused('Clé publique inutilisable.')
  }
  const valid = await subtle.verify(
    { name: 'ECDSA', hash: 'SHA-256' },
    key,
    buffer(signature),
    buffer(utf8(`${headerSegment}.${payloadSegment}`)),
  )
  if (!valid) throw new JwsRefused('Signature refusée.')
  return payload.value
}
