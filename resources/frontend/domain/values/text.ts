import { RuleViolation } from '../errors'

/**
 * Règles de texte communes PHP/TypeScript (F08), jumelles de `app/Domain/Shared/ValueObjects/Text.php`.
 * Le blanc est une liste FERMÉE (espace, tabulation, retours, U+00A0, U+202F) : `\s` n'a pas le même
 * sens en JavaScript et en PCRE.
 */
const EDGE_BLANKS = /^[ \t\n\r\u{A0}\u{202F}]+|[ \t\n\r\u{A0}\u{202F}]+$/gu
/** Avec le drapeau u, une paire de substitution valide est UN point de code : seul un isolé correspond. */
const LONE_SURROGATE = /[\u{D800}-\u{DFFF}]/u

function assertWellFormed(value: string, field: string): void {
  if (LONE_SURROGATE.test(value)) {
    throw RuleViolation.invalid(field, 'text.encoding', 'Le texte n’est pas un UTF-8 valide.')
  }
}

export function trimBlanks(value: string): string {
  assertWellFormed(value, 'text')
  return value.replace(EDGE_BLANKS, '')
}

/** Champ facultatif : blancs de bord retirés, vide → null, longueur en caractères bornée. */
export function optionalText(
  value: string | null,
  maxLength: number,
  field: string,
): string | null {
  if (value === null) return null
  const trimmed = trimBlanks(value)
  if (trimmed === '') return null
  if ([...trimmed].length > maxLength) {
    throw RuleViolation.invalid(
      field,
      'text.too_long',
      `Ce texte dépasse ${maxLength} caractères.`,
      {
        max_length: maxLength,
      },
    )
  }
  return trimmed
}

/**
 * Clé de recherche (`patient_records.name_search`) : minuscules, NFD, sans marques combinantes, tout
 * séparateur, ponctuation, symbole ou contrôle remplacé par une espace unique. Valeur séparée : le
 * nom saisi est conservé tel quel.
 */
export function searchKey(value: string): string {
  assertWellFormed(value, 'text')
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\p{Mn}\p{Cf}]+/gu, '')
    .replace(/[\p{Z}\p{P}\p{S}\p{Cc}]+/gu, ' ')
    .replace(/^ +| +$/g, '')
}

/**
 * Téléphone normalisé pour la recherche : chiffres, précédés de « + » si la saisie commence par « + »
 * ou « 00 ». Aucun indicatif n'est déduit. Vide → null.
 */
export function normalizePhone(value: string | null, field: string): string | null {
  const trimmed = value === null ? '' : trimBlanks(value)
  if (trimmed === '') return null
  if (!/^\+?[0-9 .\-/()\u{A0}\u{202F}]+$/u.test(trimmed)) {
    throw RuleViolation.invalid(
      field,
      'phone.format',
      'Numéro de téléphone invalide : chiffres, espaces, points, tirets ou parenthèses seulement.',
    )
  }
  let international = trimmed.startsWith('+')
  let digits = trimmed.replace(/[^0-9]/g, '')
  if (!international && digits.startsWith('00')) {
    international = true
    digits = digits.slice(2)
  }
  if (digits.length < 3 || digits.length > 31) {
    throw RuleViolation.invalid(
      field,
      'phone.length',
      'Un numéro de téléphone compte de 3 à 31 chiffres.',
    )
  }
  return (international ? '+' : '') + digits
}
