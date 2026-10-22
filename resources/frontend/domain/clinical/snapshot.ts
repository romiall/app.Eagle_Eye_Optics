/**
 * Empreinte des rubriques d'une consultation (C03) : SHA-256 hexadécimal minuscule de la sérialisation JSON
 * canonique (JCS, RFC 8785). Jumeau de `app/Domain/Clinical/RevisionHash.php` : mêmes règles, mêmes vecteurs
 * (`contracts/vectors/clinical/snapshot-hash.json`), rejoués par PHPUnit et Vitest.
 *
 * Sous-ensemble de JCS suffisant pour un dossier clinique : objets à clés triées (ici des clés ASCII, donc l'ordre
 * des unités de code UTF-16 est celui des octets), tableaux dans leur ordre, chaînes selon `JSON.stringify`
 * (échappement minimal, Unicode et « / » non échappés), ENTIERS seulement (une mesure est une chaîne décimale
 * exacte, jamais un flottant), booléens et null. Un flottant lève une exception plutôt que de produire une
 * empreinte ambiguë.
 *
 * L'empreinte de `CompleteConsultation` (`snapshot_sha256`) est celle de JCS(steps) que le poste a LUES : le
 * serveur la recalcule sur les tables vivantes sous verrou et refuse un écart. Hypothèse « G8 » (voir clinical.md).
 */
export type CanonicalValue =
  | null
  | boolean
  | number
  | string
  | readonly CanonicalValue[]
  | { readonly [key: string]: CanonicalValue }

export function canonicalJson(value: unknown): string {
  if (value === null) return 'null'
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) {
      throw new Error('Un nombre non entier n’a pas de forme canonique admise dans un dossier clinique.')
    }
    return String(value)
  }
  if (typeof value === 'string') return JSON.stringify(value)
  if (Array.isArray(value)) return `[${value.map((member) => canonicalJson(member)).join(',')}]`
  if (typeof value === 'object') {
    const record = value as Record<string, unknown>
    const keys = Object.keys(record).sort((left, right) => (left < right ? -1 : left > right ? 1 : 0))
    return `{${keys.map((key) => `${JSON.stringify(key)}:${canonicalJson(record[key])}`).join(',')}}`
  }
  throw new Error('Type non pris en charge dans un dossier clinique.')
}

/** SHA-256 hexadécimal minuscule (64 caractères) d'un texte UTF-8, par Web Crypto (contexte sécurisé). */
export async function sha256Hex(text: string): Promise<string> {
  const subtle = globalThis.crypto?.subtle
  if (subtle === undefined) {
    throw new Error('Le calcul de l’empreinte exige un contexte sécurisé (HTTPS ou localhost).')
  }
  const digest = await subtle.digest('SHA-256', new TextEncoder().encode(text))
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

/** Empreinte de JCS(valeur). */
export async function canonicalHash(value: unknown): Promise<string> {
  return sha256Hex(canonicalJson(value))
}

/** Empreinte des sept rubriques lues sur le serveur (`snapshot_sha256` de la finalisation). */
export async function stepsDigest(steps: unknown): Promise<string> {
  return canonicalHash(steps)
}
