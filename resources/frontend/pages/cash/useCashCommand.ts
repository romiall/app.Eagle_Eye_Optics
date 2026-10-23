import { ref } from 'vue'

import type { MutationOutcome, MutationRequest } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'
import type { Approval } from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

type CashIntent = Omit<MutationRequest, 'idempotencyKey'>

/**
 * Envoi d'un mouvement de caisse hors vente (L1D05), EN LIGNE, sur le modèle des corrections de pièces :
 *
 * - Approbation exacte : la commande porte un `approval_id`, d'abord neuf. Pour la gérante, le serveur en fait une
 *   exécution directe ; pour la caissière, il répond `APPROVAL_REQUIRED_OR_EXPIRED` et `approvalNeeded` propose de
 *   demander l'autorisation (contenu exact). La demande créée remplace l'identifiant : le mouvement se renvoie après
 *   l'accord de la gérante.
 * - Réauthentification (423) : confirmation du mot de passe puis reprise avec la même clé.
 * - Session clôturée entre-temps (409) : `stale` ; l'écran relit la session.
 */
export function useCashCommand() {
  const mutation = useMutation()
  const askingPassword = ref(false)
  const approvalNeeded = ref(false)
  const approvalId = ref(newUlid())
  const requestedApproval = ref<Approval | null>(null)
  const message = ref<string | null>(null)
  const notice = ref<string | null>(null)
  const fields = ref<Record<string, string>>({})
  const stale = ref(false)

  function absorb(outcome: MutationOutcome): MutationOutcome {
    switch (outcome.kind) {
      case 'synced':
        message.value = null
        fields.value = {}
        return outcome
      case 'reauthentication-required':
        askingPassword.value = true
        return outcome
      case 'conflict':
        stale.value = true
        message.value =
          'La session a changé depuis l’affichage : elle est relue. Vérifiez puis recommencez.'
        return outcome
      case 'refused': {
        const error = outcome.error
        if (error.code === 'APPROVAL_REQUIRED_OR_EXPIRED') {
          approvalNeeded.value = true
          message.value =
            requestedApproval.value === null
              ? 'Ce mouvement exige l’autorisation de la gérante pour ce montant exact. Demandez-la, puis renvoyez-le après son accord.'
              : 'L’autorisation demandée n’est pas (ou plus) accordée : attendez la décision de la gérante, ou faites une nouvelle demande.'
          return outcome
        }
        const byField: Record<string, string> = {}
        for (const [key, messages] of Object.entries(error.fields)) {
          const first = messages[0]
          if (first !== undefined) byField[key] = first
        }
        fields.value = byField
        message.value = error.message
        if (error.code === 'STATE_TRANSITION_REFUSED') stale.value = true
        return outcome
      }
      case 'session-expired':
        void revalidateSession().catch(() => undefined)
        message.value = mutation.failureMessage.value
        return outcome
      default:
        message.value = mutation.failureMessage.value
        return outcome
    }
  }

  async function send(intent: CashIntent): Promise<MutationOutcome> {
    message.value = null
    notice.value = null
    fields.value = {}
    stale.value = false
    return absorb(await mutation.submit(intent))
  }

  async function passwordConfirmed(): Promise<MutationOutcome> {
    askingPassword.value = false
    return absorb(await mutation.retry())
  }

  function approvalRequested(approval: Approval): void {
    requestedApproval.value = approval
    approvalId.value = approval.id
    approvalNeeded.value = false
    message.value = null
    notice.value =
      'Demande d’autorisation envoyée à la gérante : elle n’est pas encore accordée. Renvoyez le mouvement après son accord.'
  }

  /** Nouveau mouvement après un succès : nouvelle intention, nouvel identifiant d'autorisation. */
  function reset(): void {
    mutation.reset()
    approvalId.value = newUlid()
    requestedApproval.value = null
    approvalNeeded.value = false
    notice.value = null
  }

  return {
    mutation,
    askingPassword,
    approvalNeeded,
    approvalId,
    requestedApproval,
    message,
    notice,
    fields,
    stale,
    send,
    passwordConfirmed,
    approvalRequested,
    reset,
  }
}
