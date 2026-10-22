import type { MutationState } from '@/app/services/useMutation'
import type { ScreenState } from '@/components/feedback/StateMessage.vue'

/**
 * Présentation d'un échec d'enregistrement (fichier 04 : états erreur, conflit, connexion
 * indisponible). Le texte détaillé vient de `useMutation().failureMessage` ; ici, l'état d'écran, le
 * titre et la possibilité de reprendre l'envoi (même clé d'idempotence : jamais deux fiches).
 * Un refus du serveur (422, 403…) n'est pas ici : il s'affiche par champ dans le résumé d'erreurs.
 */
export interface FailureView {
  state: ScreenState
  title: string
  /** « Réessayer l'enregistrement » reprend la même commande avec la même clé. */
  retry: boolean
}

export function failureView(state: MutationState, context: 'create' | 'edit'): FailureView | null {
  switch (state) {
    case 'conflict':
      return {
        state: 'conflict',
        title: context === 'edit' ? 'La fiche a changé entre-temps' : 'Conflit d’enregistrement',
        retry: false,
      }
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
    default:
      return null
  }
}
