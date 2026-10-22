import { watch } from 'vue'

import { type ReadOptions, type ReadOutcome, useRead } from '@/app/services/reads'
import { revalidateSession } from '@/app/session'

/**
 * `useRead` des écrans de la file du jour : quand le serveur répond 401 ou 419, la session est relue
 * pour que la coquille affiche « Session expirée » et « Se reconnecter » (la lecture seule ne change
 * pas l'état de la session). L'écran reste en place ; « Réessayer » relit après la reconnexion.
 */
export function useServerRead<T>(
  loader: () => Promise<ReadOutcome<T>>,
  options: ReadOptions<T> = {},
) {
  const read = useRead(loader, options)
  watch(
    read.state,
    (state) => {
      if (state === 'session-expired') void revalidateSession().catch(() => undefined)
    },
    { immediate: true },
  )
  return read
}
