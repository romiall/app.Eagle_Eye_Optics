import { computed, ref, shallowRef } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { revalidateSession } from '@/app/session'

/**
 * Écriture d'administration (comptes du personnel, paramètres du cabinet ; F05) :
 * - une tentative à la fois ;
 * - 423 `REAUTHENTICATION_REQUIRED` : l'écran demande le mot de passe de la gérante
 *   (`PasswordConfirmDialog`), puis la MÊME écriture est rejouée (`resume`) — la saisie reste à l'écran ;
 * - 422 : erreurs par champ (le serveur tranche) ;
 * - 409 : conflit de version ou garde-fou métier (dernière gérante, ses propres droits…), message du serveur ;
 * - réseau coupé, page d'hébergeur ou 5xx : issue inconnue, jamais un succès supposé ni un échec affirmé.
 */
export type AdminWriteState =
  | 'idle'
  | 'saving'
  | 'saved'
  | 'refused'
  | 'conflict'
  | 'forbidden'
  | 'reauthentication'
  | 'session-expired'
  | 'uncertain'

interface Pending {
  operation: () => Promise<unknown>
  onSuccess: (value: unknown) => void | Promise<void>
}

export function useAdminWrite() {
  const state = ref<AdminWriteState>('idle')
  const error = shallowRef<ApiError | null>(null)
  let pending: Pending | null = null

  const fieldErrors = computed<Readonly<Record<string, readonly string[]>>>(
    () => (state.value === 'refused' ? error.value?.fields : undefined) ?? {},
  )

  const message = computed<string | null>(() => {
    switch (state.value) {
      case 'refused':
        return Object.keys(fieldErrors.value).length > 0
          ? 'Certaines informations sont à corriger.'
          : (error.value?.message ?? 'Le serveur a refusé l’opération.')
      case 'conflict':
        return error.value?.code === 'VERSION_CONFLICT'
          ? 'Ce compte a été modifié entre-temps par une autre personne : relisez-le avant de recommencer. Vos saisies restent à l’écran.'
          : (error.value?.message ?? 'Le serveur a refusé l’opération.')
      case 'forbidden':
        return 'Ce compte n’a pas le droit de faire cette opération.'
      case 'session-expired':
        return 'Votre session a pris fin : reconnectez-vous avec le bouton de l’en-tête, puis recommencez. Vos saisies restent à l’écran.'
      case 'uncertain':
        return 'Le serveur n’a pas confirmé l’opération : elle a pu être appliquée ou non. Relisez avant de recommencer.'
      default:
        return null
    }
  })

  async function execute(entry: Pending): Promise<void> {
    state.value = 'saving'
    error.value = null
    try {
      const value = await entry.operation()
      pending = null
      state.value = 'saved'
      await entry.onSuccess(value)
    } catch (caught) {
      if (caught instanceof NetworkUnavailable) {
        state.value = 'uncertain'
        return
      }
      if (!(caught instanceof ApiError)) throw caught
      error.value = caught
      if (caught.code === 'REAUTHENTICATION_REQUIRED') {
        pending = entry
        state.value = 'reauthentication'
      } else if (caught.status === 401 || caught.status === 419) {
        state.value = 'session-expired'
        void revalidateSession().catch(() => undefined)
      } else if (caught.status === 403) {
        state.value = 'forbidden'
      } else if (caught.status === 409) {
        state.value = 'conflict'
      } else if (caught.status === 422) {
        state.value = 'refused'
      } else if (!caught.fromApplication || caught.status >= 500) {
        state.value = 'uncertain'
      } else {
        state.value = 'refused'
      }
    }
  }

  /** Lance une écriture ; `onSuccess` reçoit la réponse du serveur (jamais une valeur supposée). */
  async function run<T>(
    operation: () => Promise<T>,
    onSuccess: (value: T) => void | Promise<void>,
  ): Promise<void> {
    if (state.value === 'saving') return
    await execute({ operation, onSuccess: onSuccess as (value: unknown) => void | Promise<void> })
  }

  /** Mot de passe confirmé : la même écriture repart, avec les mêmes valeurs. */
  async function resume(): Promise<void> {
    if (pending === null) return
    await execute(pending)
  }

  /** Confirmation abandonnée : rien n'a été enregistré, la saisie reste à l'écran. */
  function cancel(): void {
    pending = null
    error.value = null
    state.value = 'idle'
  }

  function reset(): void {
    pending = null
    error.value = null
    state.value = 'idle'
  }

  /** Première erreur d'un champ du serveur (clé exacte ou préfixe `roles.0` → `roles`). */
  function fieldError(field: string): string | null {
    const direct = fieldErrors.value[field]
    if (direct !== undefined && direct.length > 0) return direct[0] ?? null
    for (const [key, messages] of Object.entries(fieldErrors.value)) {
      if (key.startsWith(`${field}.`) && messages.length > 0) return messages[0] ?? null
    }
    return null
  }

  return { state, error, fieldErrors, message, run, resume, cancel, reset, fieldError }
}
