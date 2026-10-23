import { buffer, concatBytes, equalBytes, type RandomBytes, webRandom } from './bytes'

/**
 * Secret local d'un profil et clé de récupération (vault.md D8, D9). Le secret local est DISTINCT du mot de passe
 * du serveur et ne quitte jamais le poste.
 *
 * Secret : au moins 14 caractères (points de code), aucun blanc en tête ni en fin (jamais rogné en silence), ne
 * contient pas l'identifiant, pas un motif trivial (un seul caractère répété, suite clavier ou numérique). Proposé :
 * 4 mots prononçables de 5 lettres (consonne-voyelle-consonne-voyelle-consonne), tirage uniforme sans biais
 * (rejet), environ 68 bits.
 *
 * Clé de récupération : 32 octets aléatoires + 2 octets de contrôle (début du SHA-256), écrits en base32 de
 * Crockford en 11 groupes de 5 caractères. La saisie tolère minuscules, espaces et tirets, et lit I/L comme 1 et O
 * comme 0. Elle est gardée HORS du poste par la gérante.
 */
export type SecretProblem = 'TOO_SHORT' | 'EDGE_BLANK' | 'CONTAINS_LOGIN' | 'TRIVIAL'

const TRIVIAL_SEQUENCES = [
  '0123456789',
  'abcdefghijklmnopqrstuvwxyz',
  'azertyuiop',
  'qwertyuiop',
  'motdepasse',
  'password',
]

export function secretProblem(secret: string, login: string): SecretProblem | null {
  if ([...secret].length < 14) return 'TOO_SHORT'
  if (/^\s|\s$/u.test(secret)) return 'EDGE_BLANK'
  const lowered = secret.normalize('NFKC').toLowerCase()
  const loweredLogin = login.trim().toLowerCase()
  if (loweredLogin.length >= 3 && lowered.includes(loweredLogin)) return 'CONTAINS_LOGIN'
  if (new Set([...lowered]).size <= 2) return 'TRIVIAL'
  const compact = lowered.replace(/[\s\-_.]/g, '')
  if (
    TRIVIAL_SEQUENCES.some((sequence) => sequence.includes(compact) || compact.includes(sequence))
  ) {
    return 'TRIVIAL'
  }
  return null
}

const CONSONANTS = 'bcdfghjklmnprstvz'
const VOWELS = 'aeiou'

/** Entier uniforme dans [0, n) par rejet (aucun biais de modulo). */
function uniform(n: number, random: RandomBytes): number {
  const limit = 256 - (256 % n)
  for (;;) {
    const byte = random(1)[0] ?? 0
    if (byte < limit) return byte % n
  }
}

export function generatePassphrase(random: RandomBytes = webRandom): string {
  const words: string[] = []
  for (let word = 0; word < 4; word += 1) {
    let text = ''
    for (let letter = 0; letter < 5; letter += 1) {
      const alphabet = letter % 2 === 0 ? CONSONANTS : VOWELS
      text += alphabet[uniform(alphabet.length, random)]
    }
    words.push(text)
  }
  return words.join('-')
}

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

function base32(bytes: Uint8Array): string {
  let bits = 0
  let value = 0
  let out = ''
  for (const byte of bytes) {
    value = (value << 8) | byte
    bits += 8
    while (bits >= 5) {
      out += CROCKFORD[(value >>> (bits - 5)) & 31]
      bits -= 5
    }
  }
  if (bits > 0) out += CROCKFORD[(value << (5 - bits)) & 31]
  return out
}

function fromBase32(text: string): Uint8Array | null {
  let bits = 0
  let value = 0
  const out: number[] = []
  for (const char of text) {
    const index = CROCKFORD.indexOf(char)
    if (index < 0) return null
    value = ((value << 5) | index) & 0xffff
    bits += 5
    if (bits >= 8) {
      out.push((value >>> (bits - 8)) & 0xff)
      bits -= 8
    }
  }
  return new Uint8Array(out)
}

async function checksum(key: Uint8Array, subtle: SubtleCrypto): Promise<Uint8Array> {
  return new Uint8Array(await subtle.digest('SHA-256', buffer(key))).slice(0, 2)
}

export interface RecoveryKey {
  bytes: Uint8Array
  /** 11 groupes de 5 caractères séparés par des tirets. */
  text: string
}

export async function generateRecoveryKey(
  random: RandomBytes = webRandom,
  subtle: SubtleCrypto = globalThis.crypto.subtle,
): Promise<RecoveryKey> {
  const bytes = random(32)
  const encoded = base32(concatBytes(bytes, await checksum(bytes, subtle)))
  return { bytes, text: encoded.match(/.{1,5}/g)?.join('-') ?? encoded }
}

/** Relit une clé de récupération saisie ; null si elle est mal formée ou si son contrôle ne correspond pas. */
export async function parseRecoveryKey(
  input: string,
  subtle: SubtleCrypto = globalThis.crypto.subtle,
): Promise<Uint8Array | null> {
  const normalized = input
    .toUpperCase()
    .replace(/[\s-]/g, '')
    .replace(/[IL]/g, '1')
    .replace(/O/g, '0')
  if (normalized.length !== 55) return null
  const decoded = fromBase32(normalized)
  if (decoded === null || decoded.length < 34) return null
  const bytes = decoded.slice(0, 32)
  return equalBytes(decoded.slice(32, 34), await checksum(bytes, subtle)) ? bytes : null
}
