import { computed, readonly, ref, toRaw } from 'vue'

import type { ApiError } from '@/app/api'
import { knownAccountId, refreshSession, session } from '@/app/session'
import type { SaveState } from '@/components/feedback/SaveStatus.vue'
import { newUlid } from '@/domain/values/ulid'

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
 * expirée).
 *
 * Une instance par formulaire. La clé d'idempotence et les identifiants d'effets (`effectId`) lui
 * sont liés : ils restent les mêmes pour toute reprise, et même après un succès (un renvoi est alors
 * un rejeu, qui rend le résultat déjà enregistré). Seule une charge différente (montant corrigé,
 * version actualisée) est une nouvelle intention, avec une nouvelle clé ; `reset()` (nouveau
 * formulaire) renouvelle clé et identifiants. La clé vit en mémoire : un rechargement de la page la
 * perd (les identifiants d'effets de la charge empêchent alors le doublon côté serveur, O04 les
 * conserve dans le journal du poste).
 */
/**
 * Copie profonde d'une saisie, détachée de l'écran : `structuredClone` refuse les objets réactifs de Vue
 * (un tableau de lignes lu depuis un `ref` lève une DataCloneError), alors que la charge d'une commande
 * est toujours une donnée simple. Les octets et les fichiers restent tels quels (déjà clonables).
 */
function detachedCopy<T>(value: T): T {
  const raw = toRaw(value) as unknown
  if (Array.isArray(raw)) return raw.map((item) => detachedCopy(item)) as T
  if (raw === null || typeof raw !== 'object') return raw as T
  if (raw instanceof Date) return new Date(raw.getTime()) as T
  if (ArrayBuffer.isView(raw) || raw instanceof ArrayBuffer || raw instanceof Blob) {
    return structuredClone(raw) as T
  }
  return Object.fromEntries(
    Object.entries(raw).map(([key, item]) => [key, detachedCopy(item)]),
  ) as T
}

export type MutationState =
  | 'idle'
  | 'saving'
  | 'saved-local'
  | 'synced'
  | 'local-failed'
  | 'conflict'
  | 'refused'
  | 'session-expired'
  | 'reauthentication-required'
  | 'retryable'
  | 'uncertain'
  | 'unavailable'
  | 'account-changed'

export type MutationIntent = Omit<MutationRequest, 'idempotencyKey'>

const NOT_CONFIRMED =
  'L’enregistrement n’est pas confirmé : la commande a pu être appliquée. Vos saisies sont conservées ; « Réessayer » les renvoie sans doublon tant que cet écran reste ouvert.'

/** Une autre saisie est envoyée pendant qu'un envoi est en cours : refus explicite. */
export class MutationBusyError extends Error {
  constructor() {
    super('Un enregistrement est déjà en cours pour ce formulaire.')
    this.name = 'MutationBusyError'
  }
}

/** Empreinte stable d'une intention (clés triées), version attendue comprise. */
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
  return JSON.stringify(canonical({ ...intent, expectedVersion: intent.expectedVersion ?? null }))
}

export function useMutation(dispatcher: MutationDispatcher = useMutationDispatcher()) {
  const state = ref<MutationState>('idle')
  const outcome = ref<MutationOutcome | null>(null)
  const recordedAt = ref<string | null>(null)
  let key: string | null = null
  let lastFingerprint: string | null = null
  let lastIntent: MutationIntent | null = null
  let inFlight: { fingerprint: string; promise: Promise<MutationOutcome> } | null = null
  let effectIds = new Map<string, string>()
  /**
   * Compte connu à l'ouverture du formulaire : une commande ne part jamais au nom d'un autre compte.
   * Épinglé à la création de l'instance (un écran protégé n'est ouvert qu'avec un compte connu), et
   * non au premier envoi, où un autre compte peut déjà avoir pris la place dans un autre onglet.
   */
  let accountId: string | null = knownAccountId()

  /** Identifiant d'effet stable pour ce formulaire (`payment_id`, `receipt_id`…) jusqu'à `reset()`. */
  function effectId(name: string): string {
    let id = effectIds.get(name)
    if (id === undefined) {
      id = newUlid()
      effectIds.set(name, id)
    }
    return id
  }

  /** Vrai quand la dernière issue rend compte d'un envoi qui n'a pas eu lieu (rien n'est parti). */
  const notSent = ref(false)

  /** Sortie anticipée sans rien envoyer : l'issue est rendue telle quelle à l'écran. */
  function stop(result: MutationOutcome): MutationOutcome {
    outcome.value = result
    state.value = result.kind
    notSent.value = true
    return result
  }

  async function send(intent: MutationIntent, print: string): Promise<MutationOutcome> {
    const previous = outcome.value
    if (previous?.kind === 'session-expired' || previous?.kind === 'reauthentication-required') {
      // Le serveur a signalé une session perdue : elle est relue avant tout renvoi (reconnexion
      // faite ici ou dans un autre onglet). Rien n'est envoyé tant qu'elle n'est pas rétablie.
      await refreshSession().catch(() => undefined)
      if (session.status === 'unverified') return stop({ kind: 'unavailable' })
      if (session.status !== 'authenticated') {
        return stop({ kind: 'session-expired', error: previous.error })
      }
    }
    // Aucun compte connu (session perdue, déconnexion) : le serveur répond 401 et l'issue est
    // « session expirée » ; un autre compte connu est un changement de compte, rien n'est envoyé.
    const current = knownAccountId()
    if (accountId === null) accountId = current
    else if (current !== null && current !== accountId) return stop({ kind: 'account-changed' })

    if (key === null || print !== lastFingerprint) {
      key = newIdempotencyKey()
      lastFingerprint = print
    }
    lastIntent = intent
    notSent.value = false
    state.value = 'saving'
    try {
      const result = await dispatcher.dispatch({ ...intent, idempotencyKey: key })
      outcome.value = result
      state.value = result.kind
      if (result.kind === 'synced' || result.kind === 'saved-local') recordedAt.value = result.at
      return result
    } catch (error) {
      // Erreur de programmation (clé ou version invalide) : rien n'a été envoyé.
      state.value = 'idle'
      throw error
    }
  }

  /**
   * Envoie la saisie ; la même saisie pendant un envoi en cours n'est pas doublée (double clic).
   * L'intention est copiée en profondeur et figée au moment de l'envoi : l'empreinte, le corps
   * envoyé et une reprise (`retry`) portent la même charge, même si l'écran modifie ensuite ses
   * objets.
   */
  async function submit(request: MutationIntent): Promise<MutationOutcome> {
    const intent = Object.freeze(detachedCopy(request)) as MutationIntent
    const print = fingerprint(intent)
    if (inFlight !== null) {
      if (inFlight.fingerprint === print) return inFlight.promise
      throw new MutationBusyError()
    }
    const promise = send(intent, print).finally(() => {
      inFlight = null
    })
    inFlight = { fingerprint: print, promise }
    return promise
  }

  /** Reprend la dernière intention avec la même clé (après reconnexion, retour du réseau). */
  function retry(): Promise<MutationOutcome> {
    if (lastIntent === null) return Promise.reject(new Error('Aucune saisie à reprendre.'))
    return submit(lastIntent)
  }

  /** Nouveau formulaire : clé, identifiants d'effets et issue précédente sont oubliés. */
  function reset(): void {
    if (inFlight !== null) throw new MutationBusyError()
    key = null
    lastFingerprint = null
    lastIntent = null
    notSent.value = false
    effectIds = new Map()
    accountId = knownAccountId()
    outcome.value = null
    recordedAt.value = null
    state.value = 'idle'
  }

  const error = computed<ApiError | null>(() => {
    const current = outcome.value
    return current !== null && 'error' in current && current.kind !== 'local-failed'
      ? (current.error as ApiError)
      : null
  })

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
    const current = outcome.value
    switch (state.value) {
      case 'local-failed':
        return 'Échec de l’enregistrement sur ce poste : rien n’est enregistré ; vos saisies sont conservées.'
      case 'conflict':
        return 'La pièce a changé entre-temps : vos saisies sont conservées ; vérifiez la version actuelle avant de reprendre.'
      case 'refused':
        return error.value?.message ?? 'Le serveur a refusé l’enregistrement.'
      case 'session-expired':
        return 'Session expirée : vos saisies sont conservées ; reconnectez-vous pour les enregistrer.'
      case 'reauthentication-required':
        return 'Opération sensible : confirmez votre mot de passe ; vos saisies sont conservées.'
      case 'retryable': {
        const delay =
          current?.kind === 'retryable' && current.retryAfterSeconds !== null
            ? ` dans ${current.retryAfterSeconds} s`
            : ' dans un instant'
        return `Le serveur a demandé de patienter : rien n’a été appliqué ; vos saisies sont conservées, réessayez${delay}.`
      }
      case 'account-changed':
        return 'Le compte connecté n’est plus celui qui a commencé cette saisie : rien n’a été envoyé ; vos saisies sont conservées. Reconnectez-vous avec le compte d’origine.'
      case 'unavailable':
        if (notSent.value) {
          return 'Session non vérifiée (serveur injoignable) : rien n’a été envoyé ; vos saisies sont conservées, réessayez quand la connexion revient.'
        }
        return NOT_CONFIRMED
      case 'uncertain':
        // La commande a pu être appliquée : rien n'est conclu, la reprise garde la clé.
        return NOT_CONFIRMED
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
    effectId,
    submit,
    retry,
    reset,
  }
}
