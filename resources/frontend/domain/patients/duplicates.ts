import type { PatientPage, PatientSummary } from '../contracts/generated'
import { RuleViolation } from '../errors'
import { trimBlanks } from '../values/text'
import type { PatientIdentity } from './form'

/**
 * Recherche de patients et suggestions de doublon (fichier 02 : recherche à partir de deux
 * caractères ; « signaler les ressemblances avant création, sans fusion automatique » ; AT04).
 * Un téléphone partagé est permis : il est signalé, jamais refusé. Aucune fusion n'est proposée ici
 * (la fusion manuelle est une amélioration L1, réservée à la gérante).
 */
export const MIN_SEARCH_LENGTH = 2

export type SearchTerm = { ok: true; text: string } | { ok: false; length: number }

/** Texte à envoyer au serveur : blancs de bord retirés, blancs internes réduits ; 2 caractères au moins. */
export function searchTerm(raw: string): SearchTerm {
  let text: string
  try {
    text = trimBlanks(raw).replace(/[ \t\n\r\u{A0}\u{202F}]+/gu, ' ')
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return { ok: false, length: 0 }
  }
  const length = [...text].length
  return length >= MIN_SEARCH_LENGTH ? { ok: true, text } : { ok: false, length }
}

export interface DuplicateQueries {
  /**
   * Nom et premier prénom saisis (le serveur ignore casse, accents et ordre des mots) ; null si le
   * texte n'atteint pas les 2 caractères d'une recherche (nom d'un seul caractère, sans prénom).
   */
  name: string | null
  /** Chiffres du téléphone principal, ou null s'il est absent. */
  phone: string | null
}

/**
 * Requêtes de ressemblance d'une identité validée : nom + premier prénom (un second prénom saisi en
 * plus ne doit pas cacher une fiche qui ne le porte pas) et chiffres du téléphone principal.
 */
export function duplicateQueries(
  values: Pick<PatientIdentity, 'last_name' | 'first_names' | 'phone_primary'>,
): DuplicateQueries {
  const firstName = values.first_names?.split(/[ \t\n\r\u{A0}\u{202F}]+/u)[0] ?? ''
  const name = searchTerm(firstName === '' ? values.last_name : `${values.last_name} ${firstName}`)
  const phone = searchTerm((values.phone_primary ?? '').replace(/[^0-9]/g, ''))
  return { name: name.ok ? name.text : null, phone: phone.ok ? phone.text : null }
}

export type SuggestionReason = 'name' | 'phone'

export interface DuplicateSuggestion {
  patient: PatientSummary
  /** Pourquoi la fiche ressemble : nom proche, même téléphone, ou les deux. */
  reasons: SuggestionReason[]
  /** Même date de naissance que la saisie (renforce la ressemblance). */
  sameBirthDate: boolean
}

export interface DuplicateReport {
  suggestions: DuplicateSuggestion[]
  /** Vrai si le serveur a d'autres fiches proches que celles listées (pages suivantes). */
  more: boolean
}

/**
 * Fusionne les résultats par nom et par téléphone en une liste sans doublon d'identifiant. Ordre :
 * fiches qui ressemblent par les deux critères, puis par le nom, puis par le téléphone seul ; à
 * l'intérieur, l'ordre du serveur.
 */
export function mergeSuggestions(input: {
  byName: PatientPage | null
  byPhone: PatientPage | null
  birthDate: string | null
}): DuplicateReport {
  const found = new Map<string, DuplicateSuggestion>()
  const add = (page: PatientPage | null, reason: SuggestionReason): void => {
    for (const patient of page?.data ?? []) {
      const known = found.get(patient.id)
      if (known === undefined) {
        found.set(patient.id, {
          patient,
          reasons: [reason],
          sameBirthDate: input.birthDate !== null && patient.birth_date === input.birthDate,
        })
      } else if (!known.reasons.includes(reason)) {
        known.reasons.push(reason)
      }
    }
  }
  add(input.byName, 'name')
  add(input.byPhone, 'phone')
  const rank = (suggestion: DuplicateSuggestion): number =>
    suggestion.reasons.length > 1 ? 0 : suggestion.reasons[0] === 'name' ? 1 : 2
  const suggestions = [...found.values()].sort((a, b) => rank(a) - rank(b))
  const more = [input.byName, input.byPhone].some(
    (page) => page !== null && page.meta.total > page.data.length,
  )
  return { suggestions, more }
}
