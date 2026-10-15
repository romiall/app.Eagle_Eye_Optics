/**
 * ULID (contrat F06 `common.json#/$defs/Ulid`) : 26 caractères Crockford en majuscules, 48 bits de
 * temps (millisecondes) puis 80 bits aléatoires. Généré sur le poste avant tout commit local, pour
 * que chaque effet porte son identifiant définitif (contrat 23) ; le serveur le reprend tel quel.
 * Monotone pendant une même milliseconde (partie aléatoire incrémentée), jamais l'identifiant nul.
 */
import { floorMod, intDiv } from './integer'

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
const ULID_PATTERN = /^(?!0{26}$)[0-9A-HJKMNP-TV-Z]{26}$/
const TIME_MAX = 281_474_976_710_655 // 2^48 - 1

export type RandomSource = (bytes: Uint8Array<ArrayBuffer>) => Uint8Array<ArrayBuffer>

const defaultRandom: RandomSource = (bytes) => crypto.getRandomValues(bytes)

let lastTime = -1
let lastRandom: number[] = []

function encodeTime(time: number): string {
  let value = time
  let text = ''
  for (let index = 0; index < 10; index += 1) {
    text = ALPHABET.charAt(floorMod(value, 32)) + text
    value = intDiv(value, 32)
  }
  return text
}

/** 80 bits en 16 chiffres de 5 bits (valeurs 0..31). */
function randomDigits(random: RandomSource): number[] {
  const bytes = random(new Uint8Array(16))
  return [...bytes].map((byte) => floorMod(byte, 32))
}

function increment(digits: readonly number[]): number[] | null {
  const next = [...digits]
  for (let index = next.length - 1; index >= 0; index -= 1) {
    const digit = next[index] ?? 0
    if (digit < 31) {
      next[index] = digit + 1
      return next
    }
    next[index] = 0
  }
  return null
}

export function isUlid(value: string): boolean {
  return ULID_PATTERN.test(value)
}

/**
 * Nouveau ULID. `now` en millisecondes depuis l'époque Unix (horloge du poste) ; `random` remplaçable
 * pour les tests. Deux appels de la même milliseconde donnent des identifiants strictement croissants.
 */
export function newUlid(now: number = Date.now(), random: RandomSource = defaultRandom): string {
  if (!Number.isSafeInteger(now) || now < 0 || now > TIME_MAX) {
    throw new Error('Horloge hors des bornes d’un ULID.')
  }
  let digits: number[]
  if (now === lastTime) {
    const next = increment(lastRandom)
    if (next === null) throw new Error('Plus d’identifiant disponible dans cette milliseconde.')
    digits = next
  } else {
    digits = randomDigits(random)
  }
  lastTime = now
  lastRandom = digits
  const ulid = encodeTime(now) + digits.map((digit) => ALPHABET.charAt(digit)).join('')
  if (!isUlid(ulid)) throw new Error('ULID invalide produit.')
  return ulid
}

/** Réservé aux tests : oublie la dernière milliseconde utilisée. */
export function resetUlidMonotonicityForTests(): void {
  lastTime = -1
  lastRandom = []
}
