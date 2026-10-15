import { inject, type InjectionKey } from 'vue'

import { ApiError, apiRequest, NetworkUnavailable } from '@/app/api'
import type { CommandName } from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

import { commandContract, commandPath } from './commandIndex'

/**
 * Répartiteur de mutations (fichier 23 : « Les composants Vue appellent un service d'application,
 * jamais une table Dexie directement ») : un écran décrit une commande du contrat F06 ; le
 * répartiteur choisit le chemin et rend une issue explicite.
 *
 * - Poste préparé dont le journal enregistre cette commande (O04) : commit local atomique, toujours,
 *   même en ligne (le poste est l'autorité temporaire de ses écritures ; la synchronisation suit).
 * - Sinon : route directe du contrat (même action serveur que la synchronisation), en-tête
 *   `Idempotency-Key` identique pour toutes les reprises d'une même intention.
 *
 * Aucune issue n'est devinée : une absence de réponse n'est ni un succès ni un refus (la commande a
 * pu être appliquée) ; elle se reprend avec la même clé.
 */
export interface MutationRequest {
  name: CommandName
  /** Paramètres du chemin de la route directe (`{id}`, `{step}`). */
  path?: Readonly<Record<string, string>>
  /** Charge du contrat F06, identifiants des effets compris (connus avant tout envoi). */
  payload: Readonly<Record<string, unknown>>
  /** Version attendue quand la commande en a une (brouillon, grand livre, entité, état). */
  expectedVersion?: number
  /** Clé d'idempotence : la même pour toutes les reprises d'une même intention. */
  idempotencyKey: string
}

export type MutationOutcome =
  /** Appliquée et confirmée par le serveur (route directe). */
  | { kind: 'synced'; at: string; result: unknown }
  /** Commit local durable sur ce poste ; synchronisation à suivre (journal du poste). */
  | { kind: 'saved-local'; at: string }
  /** La pièce a changé entre-temps (version) ou la clé a déjà servi pour une autre charge. */
  | { kind: 'conflict'; error: ApiError }
  /** Refus du serveur : droits, validation, règle métier (avec valeurs actualisées). */
  | { kind: 'refused'; error: ApiError }
  /** Session expirée : la saisie est conservée, reprise après reconnexion avec la même clé. */
  | { kind: 'session-expired'; error: ApiError }
  /** Serveur occupé ou en erreur : rien n'est conclu, reprise avec la même clé. */
  | { kind: 'retryable'; error: ApiError }
  /** Aucune réponse et aucun journal local pour cette commande : rien n'est conclu. */
  | { kind: 'unavailable' }

/** Journal du poste préparé (O04) : seul autorisé à écrire dans le coffre local. */
export interface LocalCommandEngine {
  handles(name: CommandName): boolean
  /** Commit local atomique (entités, séquence, audit, file de sortie) ; instant durable. */
  commit(request: MutationRequest): Promise<{ recordedAt: string }>
}

export interface MutationDispatcher {
  dispatch(request: MutationRequest): Promise<MutationOutcome>
}

const IDEMPOTENCY_KEY = /^[A-Za-z0-9._:-]{8,128}$/

/** Nouvelle clé d'idempotence pour une nouvelle intention (ULID, comme l'identifiant de commande). */
export function newIdempotencyKey(): string {
  return newUlid()
}

function classify(error: ApiError): MutationOutcome {
  if (error.status === 401 || error.status === 419) return { kind: 'session-expired', error }
  if (error.code === 'VERSION_CONFLICT' || error.code === 'IDEMPOTENCY_PAYLOAD_MISMATCH') {
    return { kind: 'conflict', error }
  }
  if (error.status >= 500 || error.code === 'SERVER_BUSY') return { kind: 'retryable', error }
  return { kind: 'refused', error }
}

export function createMutationDispatcher(
  options: { localEngine?: LocalCommandEngine | null; now?: () => Date } = {},
): MutationDispatcher {
  const localEngine = options.localEngine ?? null
  const now = options.now ?? (() => new Date())

  return {
    async dispatch(request: MutationRequest): Promise<MutationOutcome> {
      if (!IDEMPOTENCY_KEY.test(request.idempotencyKey)) {
        throw new Error(
          'Clé d’idempotence invalide (8 à 128 caractères : lettres, chiffres, . _ : -).',
        )
      }
      const contract = commandContract(request.name)

      if (localEngine?.handles(request.name) === true) {
        const { recordedAt } = await localEngine.commit(request)
        return { kind: 'saved-local', at: recordedAt }
      }

      if (contract.expectedVersion !== 'none' && request.expectedVersion === undefined) {
        throw new Error(`Version attendue obligatoire pour ${request.name}.`)
      }
      const body =
        contract.expectedVersion === 'none'
          ? { ...request.payload }
          : { ...request.payload, expected_version: request.expectedVersion }

      try {
        const result = await apiRequest<unknown>(
          contract.method,
          commandPath(contract, request.path ?? {}),
          body,
          { 'Idempotency-Key': request.idempotencyKey },
        )
        return { kind: 'synced', at: now().toISOString(), result }
      } catch (error) {
        if (error instanceof NetworkUnavailable) return { kind: 'unavailable' }
        if (error instanceof ApiError) return classify(error)
        throw error
      }
    },
  }
}

/** Journal local fourni par le poste préparé (O04) ; absent : routes directes seulement. */
export const LOCAL_ENGINE_KEY: InjectionKey<LocalCommandEngine | null> = Symbol('eeo-local-engine')

export const MUTATION_DISPATCHER_KEY: InjectionKey<MutationDispatcher> =
  Symbol('eeo-mutation-dispatcher')

export function useMutationDispatcher(): MutationDispatcher {
  const provided = inject(MUTATION_DISPATCHER_KEY, null)
  if (provided !== null) return provided
  return createMutationDispatcher({ localEngine: inject(LOCAL_ENGINE_KEY, null) })
}
