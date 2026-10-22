/**
 * Empreintes et sérialisation stable (D01). `sha256Hex` calcule l'empreinte des octets d'un PDF
 * (Web Crypto, aucune bibliothèque) ; `stableStringify` donne la même chaîne pour le même contenu quel
 * que soit l'ordre des clés ; `fileIdentifier` en tire l'identifiant de fichier de 32 caractères
 * hexadécimaux du PDF, pour qu'un même modèle produise toujours les mêmes octets (réimpression : même
 * pièce, empreinte identique).
 */
export async function sha256Hex(bytes: Uint8Array): Promise<string> {
  const subtle = globalThis.crypto?.subtle
  if (subtle === undefined) {
    throw new Error('Web Crypto indisponible : contexte non sécurisé (HTTPS obligatoire).')
  }
  const digest = await subtle.digest('SHA-256', new Uint8Array(bytes))
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

export function stableStringify(value: unknown): string {
  if (value === undefined) return 'null'
  if (value === null || typeof value !== 'object') return JSON.stringify(value) ?? 'null'
  if (Array.isArray(value)) return `[${value.map((item) => stableStringify(item)).join(',')}]`
  const entries = Object.entries(value as Record<string, unknown>)
    .filter(([, item]) => item !== undefined)
    .sort(([left], [right]) => (left < right ? -1 : left > right ? 1 : 0))
  return `{${entries.map(([key, item]) => `${JSON.stringify(key)}:${stableStringify(item)}`).join(',')}}`
}

const FNV_PRIME = 0x100000001b3n
const MASK_64 = 0xffffffffffffffffn

function fnv1a64(bytes: Uint8Array, offset: bigint): bigint {
  let hash = offset
  for (const byte of bytes) {
    hash ^= BigInt(byte)
    hash = (hash * FNV_PRIME) & MASK_64
  }
  return hash
}

/** 32 caractères hexadécimaux (identifiant de fichier PDF) dérivés du contenu, pas une empreinte de sécurité. */
export function fileIdentifier(seed: string): string {
  const bytes = new TextEncoder().encode(seed)
  const first = fnv1a64(bytes, 0xcbf29ce484222325n)
  const second = fnv1a64(bytes, 0x84222325cbf29ce4n)
  return first.toString(16).padStart(16, '0') + second.toString(16).padStart(16, '0')
}
