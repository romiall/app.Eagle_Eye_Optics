import { readonly, ref, type Ref } from 'vue'

/**
 * État réseau vu par le navigateur. « En ligne » signifie seulement qu'une interface réseau est
 * active ; la joignabilité du serveur est relevée par le client HTTP à chaque échange (une réponse,
 * même une erreur, prouve que le serveur répond ; une absence de réponse prouve le contraire).
 * L'état de synchronisation des pièces locales viendra du journal du poste (F10, O04, O05).
 */
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine)
/** null : aucun échange encore ; true : le serveur a répondu ; false : aucune réponse reçue. */
const serverReachable = ref<boolean | null>(null)
let listening = false

function update(): void {
  online.value = navigator.onLine
}

export function useNetworkStatus(): {
  online: Readonly<Ref<boolean>>
  serverReachable: Readonly<Ref<boolean | null>>
} {
  if (!listening && typeof window !== 'undefined') {
    window.addEventListener('online', update)
    window.addEventListener('offline', update)
    listening = true
  }
  return { online: readonly(online), serverReachable: readonly(serverReachable) }
}

/** Appelé par le client HTTP : le serveur a répondu (true) ou aucune réponse n'est arrivée (false). */
export function reportServerReachable(reachable: boolean): void {
  serverReachable.value = reachable
}

/** Réservé aux tests : état initial. */
export function resetNetworkForTests(): void {
  online.value = typeof navigator === 'undefined' ? true : navigator.onLine
  serverReachable.value = null
}
