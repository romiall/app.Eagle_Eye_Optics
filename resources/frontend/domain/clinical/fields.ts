import { RuleViolation } from '../errors'
import { FixedDecimal } from '../values/decimal'
import { trimBlanks } from '../values/text'

/**
 * Saisies élémentaires communes aux rubriques du dossier (C01 à C04) : texte borné, texte d'une ligne,
 * mesure optique exacte, axe, durée entière. Jumeau de `app/Domain/Clinical/Rules/ClinicalInput.php` et
 * `Measures.php` : mêmes limites, mêmes refus, jamais d'arrondi ni de valeur déduite. Règles pures :
 * ni Vue, ni réseau, ni stockage.
 */
export const TEXT_MAX_LENGTH = 4000

/** Identifiant de ligne créé sur le poste (ULID), injectable pour les tests. */
export type NewId = () => string

/**
 * Erreur d'une étape : `path` est la clé pointée de la donnée envoyée (`complaints.0.other_text`),
 * comme les clés de `error.fields` du serveur (sans le préfixe `step.data.`) ; `controlId` est le
 * contrôle à atteindre.
 */
export interface StepError {
  path: string
  controlId: string
  message: string
}

export type StepValidation<D> = { ok: true; data: D } | { ok: false; errors: StepError[] }

/** Passage entre le brouillon de l'écran (`D`) et la donnée du contrat (`W`) d'une rubrique. */
export interface StepCodec<D, W> {
  blank(newId: NewId): D
  fromData(data: W, newId: NewId): D
  toData(draft: D): W
  errors(draft: D): StepError[]
  /** Vrai si une saisie ne peut pas être convertie en donnée (elle compte alors comme une modification). */
  unparsable?(draft: D): boolean
}

/** Texte facultatif : fins de ligne en « \n », blancs de bord retirés, vide → null. */
export function cleanText(value: string): string | null {
  const text = value.replace(/\r\n?/g, '\n')
  let trimmed: string
  try {
    trimmed = trimBlanks(text)
  } catch (error) {
    // Caractère isolé invalide : `textError` le signale ; la donnée reste constructible.
    if (!(error instanceof RuleViolation)) throw error
    trimmed = text.trim()
  }
  return trimmed === '' ? null : trimmed
}

const CONTROL_MESSAGE = 'ne peut pas contenir de caractère de contrôle.'

/**
 * Erreur d'un texte (longueur en caractères, caractère invalide) ; null si le texte est admis. `max` vaut
 * 4000 par défaut ; `singleLine` refuse aussi les retours à la ligne (précisions, noms, formes).
 */
export function textError(
  value: string,
  subject: string,
  max: number = TEXT_MAX_LENGTH,
  singleLine = false,
): string | null {
  let trimmed: string
  try {
    trimmed = trimBlanks(value.replace(/\r\n?/g, '\n'))
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return `${subject} : le texte contient un caractère non valide.`
  }
  if ([...trimmed].length > max) {
    return `${subject} : le texte ne peut pas dépasser ${max} caractères.`
  }
  const control = singleLine ? /\p{Cc}/u : /[^\P{Cc}\n\r\t]/u
  return control.test(trimmed) ? `${subject} : le texte ${CONTROL_MESSAGE}` : null
}

// --- Mesures optiques ------------------------------------------------------------------------------

/**
 * Valeur d'une mesure optique saisie (virgule ou point décimal, signe facultatif) : forme canonique du
 * contrat (« -1.25 », « 0.00 »), null si vide. Une saisie qui n'est pas un nombre valide (ou qui exige un
 * arrondi) est conservée TELLE QUELLE (blancs de bord retirés) pour que l'écran la montre et la refuse :
 * elle n'est jamais effacée ni corrigée en silence. `measureError` dit pourquoi.
 */
export function measureData(text: string): string | null {
  const cleaned = cleanText(text)
  if (cleaned === null) return null
  try {
    return FixedDecimal.parse(cleaned, 2, 4, true, 'mesure')?.canonical() ?? null
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return cleaned
  }
}

/** Erreur d'une mesure saisie (message court, sans sujet) ; null si vide ou valide. */
export function measureError(text: string): string | null {
  const cleaned = cleanText(text)
  if (cleaned === null) return null
  try {
    FixedDecimal.parse(cleaned, 2, 4, true, 'mesure')
    return null
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return error.rule === 'decimal.scale'
      ? 'Deux décimales au plus : la valeur n’est jamais arrondie.'
      : error.rule === 'decimal.out_of_range'
        ? 'Valeur hors des bornes admises (quatre chiffres avant la virgule).'
        : 'Nombre invalide : chiffres avec une virgule ou un point décimal.'
  }
}

/** Mesure canonique en montant d'unités centièmes (BigInt) ; null si vide ou invalide. */
export function measureUnits(text: string): bigint | null {
  const cleaned = cleanText(text)
  if (cleaned === null) return null
  try {
    return FixedDecimal.parse(cleaned, 2, 4, true, 'mesure')?.units ?? null
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return null
  }
}

/** Affichage d'une mesure canonique avec la virgule française (« -1,25 ») ; null → texte vide. */
export function measureText(canonical: string | null): string {
  return canonical === null ? '' : canonical.replace('.', ',')
}

export const AXIS_MAX = 180

/** Axe saisi : entier de 0 à 180 ; une saisie invalide donne null et `axisError` la signale. */
export function axisData(text: string): number | null {
  const cleaned = cleanText(text)
  if (cleaned === null || !/^[0-9]{1,3}$/.test(cleaned)) return null
  const value = Number(cleaned)
  return value <= AXIS_MAX ? value : null
}

export function axisError(text: string): string | null {
  const cleaned = cleanText(text)
  if (cleaned === null) return null
  if (!/^[0-9]{1,3}$/.test(cleaned) || Number(cleaned) > AXIS_MAX) {
    return `L’axe est un nombre entier de 0 à ${AXIS_MAX} degrés.`
  }
  return null
}

export function axisText(axis: number | null): string {
  return axis === null ? '' : String(axis)
}

/** Vrai si la saisie d'une mesure ou d'un axe est non vide mais ne se convertit pas en donnée. */
export function measureUnparsable(text: string): boolean {
  return cleanText(text) !== null && measureError(text) !== null
}

export function axisUnparsable(text: string): boolean {
  return cleanText(text) !== null && axisError(text) !== null
}
