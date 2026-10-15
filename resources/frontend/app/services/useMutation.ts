import { computed, readonly, ref } from 'vue'

import type { ApiError } from '@/app/api'
import type { SaveState } from '@/components/feedback/SaveStatus.vue'

import {
  type MutationDispatcher,
  type MutationOutcome,
  type MutationRequest,
  newIdempotencyKey,
  useMutationDispatcher,
} from './mutations'

/**
 * Envoi d'une saisie par le répartiteur, sans jamais toucher au formulaire de l'écran (fichier 04 :
 * « conserver les saisies valides » ; F10 : formulaire préservé lors d'un conflit ou d'une session
 * expirée). Une même intention (même commande, même charge) garde la même clé d'idempotence à
 * chaque reprise : une réponse perdue puis un nouvel envoi n'appliquent jamais deux fois la commande.
 * Une charge modifiée est une nouvelle intention, avec une nouvelle clé (les identifiants d'effets
 * de la charge empêchent par ailleurs tout doublon côté serveur).
 */
export type MutationState =
  | 'idle'
  | 'saving'
  | 'saved-local'
  | 'synced'
  | 'conflict'
  | 'refused'
  | 'session-expired'
  | 'retryable'
  | 'unavailable'

export type MutationIntent = Omit<MutationRequest, 'idempotencyKey'>

/** Empreinte stable d'une intention (clés triées), pour savoir si une reprise est la même. */
function fingerprint(intent: MutationIntent): string {
  const canonical = (value: unknown): unknown => {
    if (Array.isArray(value)) return value.map(canonical)
    if (value !== null && typeof value === 'object') {
      return Object.fromEntries(
        Object.keys(value)
          .sort()
          .map((key) => [key, canonical((value as Record<string, unknown>)[key])]),
      )
    }
    return value
  }
  return JSON.stringify(canonical(intent))
}

export function useMutation(dispatcher: MutationDispatcher = useMutationDispatcher()) {
  const state = ref<MutationState>('idle')
  const outcome = ref<MutationOutcome | null>(null)
  const recordedAt = ref<string | null>(null)
  let key: string | null = null
  let lastFingerprint: string | null = null
  let lastIntent: MutationIntent | null = null
  let inFlight: Promise<MutationOutcome> | null = null

  async function send(intent: MutationIntent): Promise<MutationOutcome> {
    const print = fingerprint(intent)
    if (key === null || print !== lastFingerprint) {
      key = newIdempotencyKey()
      lastFingerprint = print
    }
    lastIntent = intent
    state.value = 'saving'
    try {
      const result = await dispatcher.dispatch({ ...intent, idempotencyKey: key })
      outcome.value = result
      state.value = result.kind
      if (result.kind === 'synced' || result.kind === 'saved-local') {
        recordedAt.value = result.at
        // Intention aboutie : un nouvel envoi sera une nouvelle intention.
        key = null
        lastFingerprint = null
      }
      return result
    } catch (error) {
      state.value = 'idle'
      throw error
    }
  }

  /** Envoie la saisie ; un envoi déjà en cours n'est pas doublé (double clic). */
  function submit(intent: MutationIntent): Promise<MutationOutcome> {
    if (inFlight !== null) return inFlight
    inFlight = send(intent).finally(() => {
      inFlight = null
    })
    return inFlight
  }

  /** Reprend la dernière intention avec la même clé (après reconnexion, retour du réseau). */
  function retry(): Promise<MutationOutcome> {
    if (lastIntent === null) throw new Error('Aucune saisie à reprendre.')
    return submit(lastIntent)
  }

  /** Nouvelle saisie (formulaire vidé par l'écran) : l'intention précédente est oubliée. */
  function reset(): void {
    key = null
    lastFingerprint = null
    lastIntent = null
    outcome.value = null
    recordedAt.value = null
    state.value = 'idle'
  }

  const error = computed<ApiError | null>(() =>
    outcome.value !== null && 'error' in outcome.value ? outcome.value.error : null,
  )

  /** État pour `SaveStatus` : jamais « enregistré » avant un commit réussi. */
  const saveState = computed<SaveState>(() => {
    switch (state.value) {
      case 'idle':
        return 'idle'
      case 'saving':
        return 'saving'
      case 'saved-local':
        return 'saved-local'
      case 'synced':
        return 'synced'
      default:
        return 'failed'
    }
  })

  /** Message d'échec à montrer, la saisie restant affichée. */
  const failureMessage = computed<string | null>(() => {
    switch (state.value) {
      case 'conflict':
        return 'La pièce a changé entre-temps : vos saisies sont conservées ; vérifiez la version actuelle avant de reprendre.'
      case 'refused':
        return error.value?.message ?? 'Le serveur a refusé l’enregistrement.'
      case 'session-expired':
        return 'Session expirée : vos saisies sont conservées ; reconnectez-vous pour les enregistrer.'
      case 'retryable':
        return 'Le serveur n’a pas pu traiter la demande : vos saisies sont conservées ; réessayez dans un instant.'
      case 'unavailable':
        // Sans réponse, la commande a pu être appliquée : rien n'est conclu, la reprise garde la clé.
        return 'Serveur injoignable : l’enregistrement n’est pas confirmé. Vos saisies sont conservées et seront renvoyées sans doublon.'
      default:
        return null
    }
  })

  return {
    state: readonly(state),
    outcome: readonly(outcome),
    recordedAt: readonly(recordedAt),
    error,
    saveState,
    failureMessage,
    submit,
    retry,
    reset,
  }
}
