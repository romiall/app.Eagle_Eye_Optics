import type { MutationState } from '@/app/services/useMutation'
import type { ScreenState } from '@/components/feedback/StateMessage.vue'

/**
 * Présentation d'un échec d'écriture sur les écrans de vente et de caisse (fichier 04). Le texte
 * détaillé vient de `useMutation().failureMessage` ; ici, l'état d'écran, le titre et la possibilité de
 * reprendre l'envoi avec la MÊME clé d'idempotence (jamais deux pièces, jamais deux encaissements). Un
 * conflit de version a son propre encart (« Recharger ») ; un refus du serveur (422, 409 d'état) est
 * rendu par champ dans le résumé d'erreurs.
 */
export interface FailureView {
  state: ScreenState
  title: string
  retry: boolean
}

export function salesFailureView(state: MutationState): FailureView | null {
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
    default:
      return null
  }
}

/** Issue non confirmée : la commande a PU être appliquée ; l'écran ne propose que de la reprendre. */
export function unconfirmed(state: MutationState): boolean {
  return state === 'uncertain' || state === 'unavailable' || state === 'retryable'
}
