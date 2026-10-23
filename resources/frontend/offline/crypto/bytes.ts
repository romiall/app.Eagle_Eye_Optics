/**
 * Octets du coffre (O01) : UTF-8, base64url sans remplissage, hexadécimal minuscule, concaténation et aléa.
 * Fonctions pures, sans état ; l'aléa vient de Web Crypto (`getRandomValues`), jamais de `Math.random`.
 */
const encoder = new TextEncoder()
const decoder = new TextDecoder('utf-8', { fatal: true })

export function utf8(text: string): Uint8Array {
  return encoder.encode(text)
}

/** Décodage strict : un octet UTF-8 invalide lève une erreur (jamais un caractère de remplacement). */
export function fromUtf8(bytes: Uint8Array): string {
  return decoder.decode(bytes)
}

export function toBase64Url(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export function fromBase64Url(text: string): Uint8Array {
  if (!/^[A-Za-z0-9_-]*$/.test(text)) throw new RangeError('base64url invalide')
  const padded =
    text.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (text.length % 4)) % 4)
  const binary = atob(padded)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index)
  return bytes
}

export function toHex(bytes: Uint8Array): string {
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

export function concatBytes(...parts: readonly Uint8Array[]): Uint8Array {
  const total = parts.reduce((sum, part) => sum + part.length, 0)
  const out = new Uint8Array(total)
  let offset = 0
  for (const part of parts) {
    out.set(part, offset)
    offset += part.length
  }
  return out
}

export type RandomBytes = (length: number) => Uint8Array

/** Aléa cryptographique du navigateur (ou de Node 20+ en test). */
export const webRandom: RandomBytes = (length) => {
  const bytes = new Uint8Array(length)
  globalThis.crypto.getRandomValues(bytes)
  return bytes
}

/** Copie dans un ArrayBuffer neuf : Web Crypto exige un `BufferSource` sans partage ni décalage. */
export function buffer(bytes: Uint8Array): ArrayBuffer {
  return bytes.slice().buffer
}

export function equalBytes(left: Uint8Array, right: Uint8Array): boolean {
  if (left.length !== right.length) return false
  let difference = 0
  for (let index = 0; index < left.length; index += 1)
    difference |= (left[index] ?? 0) ^ (right[index] ?? 0)
  return difference === 0
}
