import { readonly, ref, shallowRef } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import type { Freshness } from '@/offline/projections/merge'

export type { Freshness } from '@/offline/projections/merge'

/**
 * Interface de lecture commune aux écrans (contrat 23 : « Les sélecteurs/projections locales sont
 * une interface de lecture commune aux pages »). Un écran fournit une fonction de lecture (service
 * de lecture du serveur, projection locale ou fusion des deux par identifiant) qui rend la donnée et
 * sa fraîcheur ; l'interface traduit l'issue en états d'écran du fichier 04 :
 * - `offline` : serveur injoignable ou en erreur, ou résultat vide qui n'a pas été confirmé par le
 *   serveur pendant cet appel (`live`) avec des données complètes — jamais « aucun dossier » ;
 * - `session-expired` : la lecture demande une reconnexion ;
 * - `forbidden`, `error`, `empty`, `ready`.
 * Les données locales seules sont `ready`, avec une fraîcheur qui le dit (`serverAsOf` null).
 */
export type ReadState =
  'loading' | 'ready' | 'empty' | 'offline' | 'forbidden' | 'session-expired' | 'error'

export interface ReadOutcome<T> {
  data: T
  freshness: Freshness
  /**
   * Vrai si le serveur a répondu à CET appel (et non un instantané gardé en cache ou sur le poste,
   * même daté et complet). Seul un vide `live` est « aucun résultat » ; absent, il vaut faux : un
   * chargeur qui l'oublie rend « hors ligne », jamais une absence affirmée à tort.
   */
  live?: boolean
}

export interface ReadOptions<T> {
  /** Vrai si la donnée lue est vide (liste sans résultat) : état `empty`. */
  isEmpty?: (data: T) => boolean
  /** Ne rien lire à la création : la lecture part au premier `reload()` (donnée utile seulement plus tard). */
  lazy?: boolean
}

export function useRead<T>(loader: () => Promise<ReadOutcome<T>>, options: ReadOptions<T> = {}) {
  const state = ref<ReadState>('loading')
  const data = shallowRef<T | null>(null)
  const freshness = ref<Freshness | null>(null)
  const error = shallowRef<ApiError | null>(null)
  /** Échec inattendu du chargeur (doublon d'identifiant, défaut de programmation). */
  const failure = shallowRef<unknown>(null)
  let generation = 0

  async function reload(): Promise<void> {
    generation += 1
    const current = generation
    state.value = 'loading'
    error.value = null
    failure.value = null
    try {
      const result = await loader()
      if (current !== generation) return
      data.value = result.data
      freshness.value = result.freshness
      if (options.isEmpty?.(result.data) === true) {
        // Vide non confirmé par le serveur pendant cet appel, ou sans données complètes : rien n'est
        // conclu (fichier 04 : « Recherche serveur indisponible », jamais « aucun dossier »).
        const certain =
          result.live === true && result.freshness.serverAsOf !== null && result.freshness.complete
        state.value = certain ? 'empty' : 'offline'
      } else {
        state.value = 'ready'
      }
    } catch (caught) {
      if (current !== generation) return
      // La donnée précédemment affichée n'est pas effacée : seul l'état change.
      if (caught instanceof NetworkUnavailable) {
        state.value = 'offline'
      } else if (caught instanceof ApiError) {
        error.value = caught
        state.value =
          caught.status === 401 || caught.status === 419
            ? 'session-expired'
            : caught.status === 403
              ? 'forbidden'
              : caught.status >= 500 || !caught.fromApplication
                ? 'offline'
                : 'error'
      } else {
        // Jamais un écran bloqué en chargement ni une promesse rejetée sans traitement.
        failure.value = caught
        state.value = 'error'
      }
    }
  }

  if (options.lazy !== true) void reload()

  return {
    state: readonly(state),
    data,
    freshness: readonly(freshness),
    error,
    failure,
    reload,
  }
}
