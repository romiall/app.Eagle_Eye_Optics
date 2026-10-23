import { type AadPart, encodeAad } from './aad'
import { open, seal, type Sealed } from './aead'
import { buffer, equalBytes, type RandomBytes, toHex, utf8, webRandom } from './bytes'

/**
 * Dérivation des clés d'enveloppement (vault.md D1, D5, D6, D9).
 *
 * - KEK d'un profil : PBKDF2-HMAC-SHA256 du secret local normalisé NFKC (jamais rogné), sel de 16 octets,
 *   itérations bornées par une POLITIQUE injectée (production ≥ 600 000, ≤ 5 000 000 ; les tests passent la leur).
 *   La KEK n'est jamais extractable ni conservée : elle sert le temps d'envelopper ou de déballer.
 * - KEK de récupération : HKDF-SHA256 de la clé de récupération (32 octets), sel = `vaultId`, info
 *   `eeo-vault-v1/recovery/kek` ; un vérificateur (info `…/recovery/verifier`) permet de contrôler une saisie sans
 *   rien déballer.
 * - Témoin KCV : AES-GCM d'une constante sous la KEK, pour distinguer un mauvais secret d'une enveloppe corrompue.
 * - Enveloppes : `wrapKey` / `unwrapKey` AES-GCM avec AAD « envelope » (propriétaire, emplacement, `keyId`, format).
 */
export interface KdfPolicy {
  minIterations: number
  maxIterations: number
  /** Itérations proposées à la création d'un profil. */
  defaultIterations: number
}

/** Politique de production (D6) : jamais affaiblie par une option d'écran. */
export const PRODUCTION_KDF_POLICY: KdfPolicy = Object.freeze({
  minIterations: 600_000,
  maxIterations: 5_000_000,
  defaultIterations: 600_000,
})

export interface KdfParams {
  /** Sel en base64url (16 octets). */
  salt: string
  iterations: number
  normalization: 'NFKC'
}

export class KdfParamsRejected extends Error {
  constructor() {
    super('Paramètres de dérivation hors des bornes admises.')
    this.name = 'KdfParamsRejected'
  }
}

export function assertKdfParams(params: KdfParams, policy: KdfPolicy): void {
  if (
    params.normalization !== 'NFKC' ||
    !Number.isSafeInteger(params.iterations) ||
    params.iterations < policy.minIterations ||
    params.iterations > policy.maxIterations
  ) {
    throw new KdfParamsRejected()
  }
}

export interface KdfDeps {
  subtle?: SubtleCrypto
  random?: RandomBytes
}

function subtleOf(deps: KdfDeps): SubtleCrypto {
  return deps.subtle ?? globalThis.crypto.subtle
}

const KEK_USAGES: KeyUsage[] = ['wrapKey', 'unwrapKey', 'encrypt', 'decrypt']

export async function deriveProfileKek(
  secret: string,
  salt: Uint8Array,
  iterations: number,
  deps: KdfDeps = {},
): Promise<CryptoKey> {
  const subtle = subtleOf(deps)
  const material = await subtle.importKey(
    'raw',
    buffer(utf8(secret.normalize('NFKC'))),
    'PBKDF2',
    false,
    ['deriveKey'],
  )
  return subtle.deriveKey(
    { name: 'PBKDF2', hash: 'SHA-256', salt: buffer(salt), iterations },
    material,
    { name: 'AES-GCM', length: 256 },
    false,
    KEK_USAGES,
  )
}

export async function deriveRecoveryKek(
  recovery: Uint8Array,
  vaultId: string,
  deps: KdfDeps = {},
): Promise<{ kek: CryptoKey; verifier: string }> {
  const subtle = subtleOf(deps)
  const material = await subtle.importKey('raw', buffer(recovery), 'HKDF', false, [
    'deriveKey',
    'deriveBits',
  ])
  const kek = await subtle.deriveKey(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: buffer(utf8(vaultId)),
      info: buffer(utf8('eeo-vault-v1/recovery/kek')),
    },
    material,
    { name: 'AES-GCM', length: 256 },
    false,
    KEK_USAGES,
  )
  const bits = await subtle.deriveBits(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: buffer(utf8(vaultId)),
      info: buffer(utf8('eeo-vault-v1/recovery/verifier')),
    },
    material,
    256,
  )
  return { kek, verifier: toHex(new Uint8Array(bits)) }
}

const KCV_CONSTANT = utf8('eeo-vault-v1/kcv')

export async function makeKcv(
  kek: CryptoKey,
  aad: readonly AadPart[],
  deps: KdfDeps = {},
): Promise<Sealed> {
  return seal(kek, KCV_CONSTANT, 'kcv', aad, deps)
}

/** Vrai si la KEK candidate ouvre le témoin (bon secret) ; faux sinon, sans distinguer davantage. */
export async function checkKcv(
  kek: CryptoKey,
  kcv: Sealed,
  aad: readonly AadPart[],
  deps: KdfDeps = {},
): Promise<boolean> {
  try {
    return equalBytes(await open(kek, kcv, 'kcv', aad, deps), KCV_CONSTANT)
  } catch {
    return false
  }
}

export type EnvelopeFormat = 'raw' | 'pkcs8'

/** Enveloppe une clé EXTRACTABLE sous une KEK ; l'appelant abandonne ensuite toute référence à la clé extractable. */
export async function wrap(
  key: CryptoKey,
  kek: CryptoKey,
  format: EnvelopeFormat,
  aad: readonly AadPart[],
  deps: KdfDeps = {},
): Promise<Sealed> {
  const nonce = (deps.random ?? webRandom)(12)
  const wrapped = await subtleOf(deps).wrapKey(format, key, kek, {
    name: 'AES-GCM',
    iv: buffer(nonce),
    additionalData: buffer(encodeAad('envelope', aad)),
    tagLength: 128,
  })
  return { nonce, ciphertext: new Uint8Array(wrapped) }
}

/** Déballe une clé de compartiment AES-GCM (`extractable` faux en session, vrai seulement pour ré-envelopper). */
export async function unwrapAesKey(
  sealed: Sealed,
  kek: CryptoKey,
  aad: readonly AadPart[],
  extractable: boolean,
  deps: KdfDeps = {},
): Promise<CryptoKey> {
  return subtleOf(deps).unwrapKey(
    'raw',
    buffer(sealed.ciphertext),
    kek,
    {
      name: 'AES-GCM',
      iv: buffer(sealed.nonce),
      additionalData: buffer(encodeAad('envelope', aad)),
      tagLength: 128,
    },
    { name: 'AES-GCM', length: 256 },
    extractable,
    ['encrypt', 'decrypt'],
  )
}

/** Déballe la clé privée ES256 du poste (`['sign']`, non extractable en session). */
export async function unwrapSigningKey(
  sealed: Sealed,
  kek: CryptoKey,
  aad: readonly AadPart[],
  extractable: boolean,
  deps: KdfDeps = {},
): Promise<CryptoKey> {
  return subtleOf(deps).unwrapKey(
    'pkcs8',
    buffer(sealed.ciphertext),
    kek,
    {
      name: 'AES-GCM',
      iv: buffer(sealed.nonce),
      additionalData: buffer(encodeAad('envelope', aad)),
      tagLength: 128,
    },
    { name: 'ECDSA', namedCurve: 'P-256' },
    extractable,
    ['sign'],
  )
}

/** Nouvelle clé de compartiment, extractable le seul temps d'être enveloppée. */
export async function generateCompartmentKey(deps: KdfDeps = {}): Promise<CryptoKey> {
  return subtleOf(deps).generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt'])
}
