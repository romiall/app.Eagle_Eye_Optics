import { type AadPart, type AadPurpose, encodeAad } from './aad'
import { buffer, fromUtf8, type RandomBytes, utf8, webRandom } from './bytes'

/**
 * Chiffrement authentifié AES-256-GCM du coffre (vault.md D7) : nonce de 96 bits aléatoires généré DANS `seal`
 * (jamais fourni par l'appelant), étiquette de 128 bits explicite, AAD obligatoire. La borne NIST SP 800-38D
 * (2^32 chiffrements par clé) est documentée ; une rotation de clé la rendra inatteignable (R-rotation).
 */
export interface Sealed {
  nonce: Uint8Array
  ciphertext: Uint8Array
}

export interface AeadDeps {
  subtle?: SubtleCrypto
  random?: RandomBytes
}

function subtleOf(deps: AeadDeps): SubtleCrypto {
  return deps.subtle ?? globalThis.crypto.subtle
}

export async function seal(
  key: CryptoKey,
  plaintext: Uint8Array,
  purpose: AadPurpose,
  aad: readonly AadPart[],
  deps: AeadDeps = {},
): Promise<Sealed> {
  const nonce = (deps.random ?? webRandom)(12)
  const ciphertext = await subtleOf(deps).encrypt(
    {
      name: 'AES-GCM',
      iv: buffer(nonce),
      additionalData: buffer(encodeAad(purpose, aad)),
      tagLength: 128,
    },
    key,
    buffer(plaintext),
  )
  return { nonce, ciphertext: new Uint8Array(ciphertext) }
}

/** Ouvre un contenu scellé ; lève une erreur si la clé, l'AAD, le nonce ou le texte ne correspondent pas. */
export async function open(
  key: CryptoKey,
  sealed: Sealed,
  purpose: AadPurpose,
  aad: readonly AadPart[],
  deps: AeadDeps = {},
): Promise<Uint8Array> {
  const plaintext = await subtleOf(deps).decrypt(
    {
      name: 'AES-GCM',
      iv: buffer(sealed.nonce),
      additionalData: buffer(encodeAad(purpose, aad)),
      tagLength: 128,
    },
    key,
    buffer(sealed.ciphertext),
  )
  return new Uint8Array(plaintext)
}

export async function sealJson(
  key: CryptoKey,
  value: unknown,
  purpose: AadPurpose,
  aad: readonly AadPart[],
  deps: AeadDeps = {},
): Promise<Sealed> {
  return seal(key, utf8(JSON.stringify(value)), purpose, aad, deps)
}

export async function openJson<T>(
  key: CryptoKey,
  sealed: Sealed,
  purpose: AadPurpose,
  aad: readonly AadPart[],
  deps: AeadDeps = {},
): Promise<T> {
  return JSON.parse(fromUtf8(await open(key, sealed, purpose, aad, deps))) as T
}
