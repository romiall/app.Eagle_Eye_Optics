import { computed, reactive, readonly } from 'vue'

import type { LoginRequest, Me, MeResponse } from '@/domain/contracts/generated'

import { ApiError, apiRequest, NetworkUnavailable, prepareSession } from './api'

/**
 * Session du personnel côté SPA (session Sanctum du serveur, fichier 23). Les droits affichés
 * viennent de `/auth/me` : ils guident l'interface, le serveur les revérifie à chaque action.
 *
 * `unverified` : la session n'a pas pu être vérifiée (serveur muet ou en erreur) ; l'interface le
 * dit sans conclure à une déconnexion ni ouvrir d'écran protégé. Le déverrouillage hors-ligne d'un
 * profil préparé arrive avec le coffre local (O01, O02).
 */
export type SessionStatus = 'unknown' | 'anonymous' | 'authenticated' | 'unverified'

interface SessionState {
  status: SessionStatus
  account: Me | null
}

const state = reactive<SessionState>({ status: 'unknown', account: null })

export const session = readonly(state)

export const currentPermissions = computed<readonly string[]>(
  () => state.account?.permissions ?? [],
)

export function can(permission: string): boolean {
  return currentPermissions.value.includes(permission)
}

export function canAny(permissions: readonly string[]): boolean {
  return permissions.some((permission) => can(permission))
}

export async function refreshSession(): Promise<SessionStatus> {
  try {
    const response = await apiRequest<MeResponse>('GET', '/api/v1/auth/me')
    state.account = response.data
    state.status = 'authenticated'
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 419)) {
      state.account = null
      state.status = 'anonymous'
    } else if (error instanceof NetworkUnavailable || error instanceof ApiError) {
      // Aucune réponse, ou une erreur du serveur (5xx, maintenance) : rien n'est conclu.
      state.status = 'unverified'
    } else {
      throw error
    }
  }
  return state.status
}

export async function signIn(credentials: LoginRequest): Promise<Me> {
  await prepareSession()
  const response = await apiRequest<MeResponse>('POST', '/api/v1/auth/login', credentials)
  state.account = response.data
  state.status = 'authenticated'
  return response.data
}

/**
 * Déconnexion : la session n'est dite fermée qu'une fois fermée par le serveur. Sans réponse, ou sur
 * une erreur, l'exception remonte et le compte reste affiché : sur un poste partagé, la personne
 * suivante ne doit pas hériter d'une session que l'écran prétendrait fermée. Une session déjà
 * expirée (401) est fermée ; un jeton CSRF périmé (419) est renouvelé une fois.
 */
export async function signOut(): Promise<void> {
  try {
    await apiRequest<null>('POST', '/api/v1/auth/logout')
  } catch (error) {
    if (error instanceof ApiError && error.status === 419) {
      await prepareSession()
      await apiRequest<null>('POST', '/api/v1/auth/logout').catch((retry: unknown) => {
        if (!(retry instanceof ApiError && retry.status === 401)) throw retry
      })
    } else if (!(error instanceof ApiError && error.status === 401)) {
      throw error
    }
  }
  state.account = null
  state.status = 'anonymous'
}

/** Réservé aux tests : état initial. */
export function resetSessionForTests(): void {
  state.account = null
  state.status = 'unknown'
}
