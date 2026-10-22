import { ApiError, apiRequest, prepareSession } from '@/app/api'
import type { ReauthenticationResponse } from '@/domain/contracts/generated'

/**
 * Confirmation du mot de passe pour une opération sensible (fichier 09 ; route
 * `POST /api/v1/auth/reauthenticate`, F05). Le serveur répond 423 `REAUTHENTICATION_REQUIRED` à une
 * écriture sensible (règle fiscale, stock initial) tant que le mot de passe n'a pas été confirmé
 * récemment dans CETTE session ; l'écran demande alors le mot de passe, appelle cette route, puis
 * reprend la même commande avec la même clé d'idempotence (`useMutation().retry()`).
 *
 * Le mot de passe n'est jamais conservé : il part dans le corps de la requête et n'est ni journalisé
 * ni gardé en mémoire par ce service. Rend l'instant jusqu'auquel la confirmation vaut.
 */
export async function confirmPassword(password: string): Promise<string> {
  const send = (): Promise<ReauthenticationResponse> =>
    apiRequest<ReauthenticationResponse>('POST', '/api/v1/auth/reauthenticate', { password })
  try {
    return (await send()).data.reauthenticated_until
  } catch (error) {
    // Jeton CSRF périmé (419) : renouvelé une fois avant de conclure.
    if (!(error instanceof ApiError) || error.status !== 419) throw error
    await prepareSession()
    return (await send()).data.reauthenticated_until
  }
}
