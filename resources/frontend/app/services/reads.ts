import { readonly, ref, shallowRef } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import type { Freshness } from '@/offline/projections/merge'

export type { Freshness } from '@/offline/projections/merge'

/**
 * Interface de lecture commune aux écrans (contrat 23 : « Les sélecteurs/projections locales sont
 * une interface de lecture commune aux pages »). Un écran fournit une fonction de lecture (service
 * de lecture du serveur, projection locale ou fusion des deux par identifiant) qui rend la donnée et
 * sa fraîcheur ; l'interface traduit l'issue en états d'écran du fichier 04 :
 * - `offline` : serveur injoignable et aucune donnée locale suffisante — jamais « aucun dossier » ;
 * - `session-expired` : la lecture demande une reconnexion ;
 * - `forbidden`, `error`, `empty`, `ready`.
 * Les données locales seules sont `ready`, avec une fraîcheur qui le dit (`serverAsOf` null).
 */
export type ReadState =
  'loading' | 'ready' | 'empty' | 'offline' | 'forbidden' | 'session-expired' | 'error'

export interface ReadOutcome<T> {
  data: T
  freshness: Freshness
}

export interface ReadOptions<T> {
  /** Vrai si la donnée lue est vide (liste sans résultat) : état `empty`. */
  isEmpty?: (data: T) => boolean
}

export function useRead<T>(loader: () => Promise<ReadOutcome<T>>, options: ReadOptions<T> = {}) {
  const state = ref<ReadState>('loading')
  const data = shallowRef<T | null>(null)
  const freshness = ref<Freshness | null>(null)
  const error = shallowRef<ApiError | null>(null)
  let generation = 0

  async function reload(): Promise<void> {
    generation += 1
    const current = generation
    state.value = 'loading'
    error.value = null
    try {
      const result = await loader()
      if (current !== generation) return
      data.value = result.data
      freshness.value = result.freshness
      state.value = options.isEmpty?.(result.data) === true ? 'empty' : 'ready'
    } catch (failure) {
      if (current !== generation) return
      // La donnée précédemment affichée n'est pas effacée : seul l'état change.
      if (failure instanceof NetworkUnavailable) {
        state.value = 'offline'
      } else if (failure instanceof ApiError) {
        error.value = failure
        state.value =
          failure.status === 401 || failure.status === 419
            ? 'session-expired'
            : failure.status === 403
              ? 'forbidden'
              : 'error'
      } else {
        throw failure
      }
    }
  }

  void reload()

  return {
    state: readonly(state),
    data,
    freshness: readonly(freshness),
    error,
    reload,
  }
}
