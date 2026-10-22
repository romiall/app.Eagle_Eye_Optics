import type { ApiError } from '@/app/api'
import type { MutationState } from '@/app/services/useMutation'
import type { ScreenState } from '@/components/feedback/StateMessage.vue'

/**
 * Présentation d'un échec d'enregistrement d'une étape (fichier 04 : états erreur, conflit, connexion
 * indisponible, droits insuffisants). Le texte détaillé vient de `useMutation().failureMessage` ; ici,
 * l'état d'écran, le titre et la possibilité de reprendre l'envoi (même clé d'idempotence : jamais
 * deux enregistrements pour une seule intention). Un conflit de version a son propre encart
 * (« Recharger la consultation ») ; un refus 422 par champ s'affiche sous chaque champ et dans le
 * résumé d'erreurs, pas ici.
 */
export interface FailureView {
  state: ScreenState
  title: string
  /** « Réessayer l'enregistrement » reprend la même commande avec la même clé. */
  retry: boolean
}

export function failureView(state: MutationState, error: ApiError | null): FailureView | null {
  switch (state) {
    case 'session-expired':
      return { state: 'offline', title: 'Session expirée', retry: true }
    case 'reauthentication-required':
      return { state: 'error', title: 'Confirmation du mot de passe demandée', retry: false }
    case 'retryable':
      return { state: 'offline', title: 'Le serveur demande de patienter', retry: true }
    case 'uncertain':
    case 'unavailable':
      return { state: 'offline', title: 'Enregistrement non confirmé', retry: true }
    case 'account-changed':
      return { state: 'error', title: 'Compte différent', retry: false }
    case 'local-failed':
      return { state: 'error', title: 'Échec de l’enregistrement sur ce poste', retry: false }
    case 'refused': {
      if (error === null) return null
      // Refus par champ : chaque message est sous son champ et dans le résumé d'erreurs.
      if (error.status === 422 && Object.keys(error.fields).length > 0) return null
      if (error.status === 403)
        return { state: 'forbidden', title: 'Droits insuffisants', retry: false }
      if (error.status === 404) {
        return { state: 'error', title: 'Consultation introuvable', retry: false }
      }
      if (error.status === 409) {
        return {
          state: 'error',
          title: 'Enregistrement impossible dans l’état actuel',
          retry: false,
        }
      }
      return { state: 'error', title: 'Enregistrement refusé', retry: false }
    }
    default:
      return null
  }
}
