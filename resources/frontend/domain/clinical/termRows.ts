import { cleanText, type NewId, type StepError, textError } from './fields'
import { NONE_CODE, OTHER_CODE, termRank, type TermGroup } from './terms'
import { newUlid } from '../values/ulid'

/**
 * Lignes de termes du référentiel (motifs, antécédents, motifs d'orientation, amétropies, traitements des
 * verres) : un identifiant ULID par ligne créé sur le poste, un code, une précision pour « Autre ». Partagé par
 * les rubriques qui choisissent des termes (C01, C02, C04). Règles pures : ni Vue, ni réseau, ni stockage.
 */

/** Ligne de la donnée envoyée (forme exacte du contrat). */
export interface TermData {
  id: string
  term_code: string
  other_text: string | null
}

/** Une valeur cochée : identifiant de la ligne (créé sur le poste), code du terme, précision. */
export interface TermRow {
  id: string
  code: string
  otherText: string
}

/** Lignes dans l'ordre du référentiel ; un code inconnu vient après les autres, par ordre alphabétique. */
export function sortRows(group: TermGroup, rows: readonly TermRow[]): TermRow[] {
  return [...rows].sort((left, right) => {
    const rank = termRank(group, left.code) - termRank(group, right.code)
    if (rank !== 0) return rank
    return left.code < right.code ? -1 : left.code > right.code ? 1 : 0
  })
}

/** Coche ou décoche un terme ; une ligne déjà cochée garde son identifiant et sa précision. */
export function toggleTerm(
  group: TermGroup,
  rows: readonly TermRow[],
  code: string,
  checked: boolean,
  newId: NewId = newUlid,
): TermRow[] {
  const existing = rows.find((row) => row.code === code)
  if (!checked) return rows.filter((row) => row.code !== code)
  if (existing !== undefined) return [...rows]
  return sortRows(group, [...rows, { id: newId(), code, otherText: '' }])
}

/** Remplace la précision de la ligne « Autre » (ou de tout terme qui en porte une). */
export function setOtherText(rows: readonly TermRow[], code: string, text: string): TermRow[] {
  return rows.map((row) => (row.code === code ? { ...row, otherText: text } : row))
}

export function rowsFromData(group: TermGroup, terms: readonly TermData[] | undefined): TermRow[] {
  const seen = new Set<string>()
  const rows: TermRow[] = []
  for (const term of terms ?? []) {
    if (term.term_code === NONE_CODE || seen.has(term.term_code)) continue
    seen.add(term.term_code)
    rows.push({ id: term.id, code: term.term_code, otherText: term.other_text ?? '' })
  }
  return sortRows(group, rows)
}

export function termsData(rows: readonly TermRow[]): TermData[] {
  return rows.map((row) => ({
    id: row.id,
    term_code: row.code,
    other_text: row.code === OTHER_CODE ? cleanText(row.otherText) : null,
  }))
}

export interface TermsCheck {
  path: string
  group: TermGroup
  rows: readonly TermRow[]
  max: number
  listControl: string
  otherControl: string
  otherSubject: string
}

export function termErrors(check: TermsCheck): StepError[] {
  const errors: StepError[] = []
  if (check.rows.length > check.max) {
    errors.push({
      path: check.path,
      controlId: check.listControl,
      message: `Pas plus de ${check.max} valeurs dans cette liste.`,
    })
  }
  check.rows.forEach((row, index) => {
    if (row.code !== OTHER_CODE) return
    const path = `${check.path}.${index}.other_text`
    if (cleanText(row.otherText) === null) {
      errors.push({
        path,
        controlId: check.otherControl,
        message: `${check.otherSubject} : précisez la valeur « Autre ».`,
      })
      return
    }
    const message = textError(row.otherText, 'La précision', undefined, true)
    if (message !== null) errors.push({ path, controlId: check.otherControl, message })
  })
  return errors
}
