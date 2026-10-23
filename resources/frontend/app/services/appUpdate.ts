import { readonly, shallowRef } from 'vue'
import type { Workbox } from 'workbox-window'

import { type UpdateReadiness, updateReadinessForThisDevice } from './vault'

/**
 * Mise à jour de l'application PROPOSÉE, jamais imposée (O06 ; fiche : « Proposer SW update sans remplacement
 * forcé » ; `pwa.config.ts` : `registerType: 'prompt'`, `skipWaiting: false`, `clientsClaim: false`).
 *
 * Quand une nouvelle version est prête (service worker en attente), l'écran l'annonce avec le contrôle du poste
 * (`updateReadinessForThisDevice` : pièces en attente et compatibilité de la release, profil verrouillé, export récent,
 * stockage). L'installation n'a lieu que sur demande de l'utilisateur, et seulement si rien ne la bloque ; elle active
 * le nouveau service worker puis recharge la page. Rien n'est purgé : ni le coffre, ni le journal, ni les PDF ; la
 * migration du schéma local est additive.
 *
 * Limite déclarée : un service worker en attente s'active aussi quand TOUS les onglets de l'application sont fermés
 * (comportement des navigateurs). C'est sans risque tant que la release garde les versions des pièces en attente
 * (`contracts/compatibility.json`, règle « jamais retirer une version tant qu'un poste en détient ») ; la nouvelle
 * version ouvre la base en avant sans rien supprimer.
 */
export interface AppUpdateState {
  available: boolean
  readiness: UpdateReadiness | null
  checking: boolean
  applying: boolean
  postponed: boolean
}

const state = shallowRef<AppUpdateState>({
  available: false,
  readiness: null,
  checking: false,
  applying: false,
  postponed: false,
})
let workbox: Workbox | null = null

export const appUpdateState = readonly(state)

/** Branche l'annonce sur le service worker enregistré (aucun effet sans service worker). */
export function watchServiceWorkerUpdates(instance: Workbox | null): void {
  workbox = instance
  if (instance === null) return
  instance.addEventListener('waiting', () => {
    state.value = { ...state.value, available: true, postponed: false }
    void refreshUpdateReadiness()
  })
}

export async function refreshUpdateReadiness(): Promise<UpdateReadiness> {
  state.value = { ...state.value, checking: true }
  try {
    const readiness = await updateReadinessForThisDevice()
    state.value = { ...state.value, readiness, checking: false }
    return readiness
  } catch {
    // Le poste n'a pas pu être lu : par prudence, l'installation reste à confirmer après synchronisation.
    const readiness: UpdateReadiness = {
      decision: 'BLOCKED',
      pendingCount: 0,
      reasons: [
        {
          code: 'COMPATIBILITY_UNKNOWN',
          blocking: true,
          message: 'Le poste n’a pas pu être vérifié : réessayez après avoir ouvert votre profil.',
        },
      ],
    }
    state.value = { ...state.value, readiness, checking: false }
    return readiness
  }
}

export type ApplyOutcome = 'APPLIED' | 'BLOCKED' | 'NEEDS_CONFIRMATION' | 'UNAVAILABLE'

/**
 * Installe la nouvelle version : refusée si un motif bloque ; à confirmer explicitement (`confirmed`) si des pièces
 * attendent ou qu'un avertissement existe.
 */
export async function applyUpdate(confirmed: boolean): Promise<ApplyOutcome> {
  const readiness = await refreshUpdateReadiness()
  if (readiness.decision === 'BLOCKED') return 'BLOCKED'
  if (readiness.decision === 'CONFIRM' && !confirmed) return 'NEEDS_CONFIRMATION'
  if (workbox === null) return 'UNAVAILABLE'
  state.value = { ...state.value, applying: true }
  workbox.addEventListener('controlling', () => {
    window.location.reload()
  })
  workbox.messageSkipWaiting()
  return 'APPLIED'
}

/** « Plus tard » : l'annonce se masque jusqu'à la prochaine version prête ou au prochain démarrage. */
export function postponeUpdate(): void {
  state.value = { ...state.value, postponed: true }
}

export function resetAppUpdateForTests(): void {
  workbox = null
  state.value = {
    available: false,
    readiness: null,
    checking: false,
    applying: false,
    postponed: false,
  }
}
