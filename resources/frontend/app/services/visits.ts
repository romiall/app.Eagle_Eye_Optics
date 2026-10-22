import { apiRequest } from '@/app/api'
import type { Visit, VisitPage } from '@/domain/contracts/generated'
import { RuleViolation } from '@/domain/errors'
import { CivilDate } from '@/domain/values/civilDate'

import type { ReadOutcome } from './reads'

/**
 * Lectures du serveur pour la file du jour (P03). Comme pour les patients, chaque lecture rend la
 * donnée avec sa fraîcheur (`serverAsOf` : l'heure de la réponse du serveur à CET appel, `complete`)
 * et `live` vrai : seul un vide `live` peut devenir « aucune visite » (`useRead`). Une erreur du
 * serveur est propagée telle quelle (422 sur `date` ou `page`, 403, 404 d'un module fermé) ; un
 * réseau coupé reste `NetworkUnavailable`, jamais une liste vide.
 *
 * Mode en ligne seulement : la file du jour vient du serveur. Le moteur local (annuaire préparé du
 * poste, O01 à O05) ne l'alimente pas encore et ne s'ajoutera pas sans le dire à l'écran.
 */
function answered<T>(data: T, complete = true): ReadOutcome<T> {
  return { data, freshness: { serverAsOf: new Date().toISOString(), complete }, live: true }
}

function assertDay(day: string): void {
  try {
    CivilDate.fromString(day)
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    throw new RangeError('Jour invalide : forme AAAA-MM-JJ attendue.', { cause: error })
  }
}

function assertPage(page: number): void {
  if (!Number.isSafeInteger(page) || page < 1) throw new RangeError('Page de visites invalide.')
}

async function fetchPage(day: string, page: number): Promise<VisitPage> {
  const parameters = new URLSearchParams({ date: day, page: String(page) })
  return apiRequest<VisitPage>('GET', `/api/v1/visits?${parameters}`)
}

/**
 * Une page (50 visites) de la file d'un jour civil du cabinet, de la plus ancienne arrivée à la plus
 * récente. `day` est un jour civil `AAAA-MM-JJ` (jamais un minuit UTC).
 */
export async function listVisits(day: string, page = 1): Promise<ReadOutcome<VisitPage>> {
  assertDay(day)
  assertPage(page)
  return answered(await fetchPage(day, page))
}

/** Toutes les visites d'un jour, pour y repérer une autre arrivée du même patient. */
export interface DayVisits {
  visits: Visit[]
  /** Vrai si la file est plus longue que ce qui a été lu : l'absence d'une visite n'est alors pas établie. */
  truncated: boolean
}

/** Pages lues au plus pour une vérification (50 visites par page) : au-delà, la lecture est dite partielle. */
export const MAX_DAY_PAGES = 10

/**
 * Parcourt les pages de la file d'un jour (au plus `maxPages`). Un échec sur une page, quelle qu'elle
 * soit, échoue la lecture entière : une file partielle n'est jamais présentée comme complète.
 */
export async function listDayVisits(
  day: string,
  maxPages = MAX_DAY_PAGES,
): Promise<ReadOutcome<DayVisits>> {
  assertDay(day)
  assertPage(maxPages)
  const visits: Visit[] = []
  let truncated = false
  for (let page = 1; ; page += 1) {
    const current = await fetchPage(day, page)
    visits.push(...current.data)
    if (current.meta.current_page >= current.meta.last_page) break
    if (page >= maxPages) {
      truncated = true
      break
    }
  }
  return answered({ visits, truncated }, !truncated)
}
