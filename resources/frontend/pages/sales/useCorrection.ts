import { ref } from 'vue'

import type { MutationOutcome, MutationRequest } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'
import type { Approval } from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

type CorrectionIntent = Omit<MutationRequest, 'idempotencyKey'>

/**
 * Envoi d'une correction de pièce émise (B07, B08 : contre-écriture, avoir, remboursement), EN LIGNE.
 *
 * - Réauthentification : sur 423, `askingPassword` ouvre la confirmation du mot de passe ; la MÊME commande est
 *   ensuite reprise avec la même clé d'idempotence (jamais deux corrections).
 * - Approbation exacte : la commande porte un `approval_id`. Il vaut d'abord un identifiant neuf : pour la gérante
 *   (qui détient la permission de l'opération et `approval.decide`), le serveur en fait une exécution directe,
 *   autorisation écrite sous cet identifiant. Pour un autre compte, le serveur répond
 *   `APPROVAL_REQUIRED_OR_EXPIRED` : `approvalNeeded` propose alors de demander l'autorisation (contenu exact de
 *   l'opération) ; la demande créée remplace l'identifiant, et l'opération se renvoie après l'accord de la gérante.
 *   Changer l'identifiant change la charge : une nouvelle clé d'idempotence est prise (nouvelle intention).
 * - Aucune issue n'est devinée : sans réponse, la commande se reprend avec la même clé.
 */
export function useCorrection() {
  const mutation = useMutation()
  const askingPassword = ref(false)
  const approvalNeeded = ref(false)
  const approvalId = ref(newUlid())
  const requestedApproval = ref<Approval | null>(null)
  const message = ref<string | null>(null)
  /** Avis neutre : autorisation demandée, en attente de la gérante (ce n'est pas un échec). */
  const notice = ref<string | null>(null)
  const fields = ref<Record<string, string>>({})
  /** La pièce a changé (version, crédit) : l'écran relit la facture. */
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
          'La facture a changé depuis l’affichage (un autre encaissement, une autre correction) : elle est relue. Vérifiez les montants puis recommencez.'
        return outcome
      case 'refused': {
        const error = outcome.error
        if (error.code === 'APPROVAL_REQUIRED_OR_EXPIRED') {
          approvalNeeded.value = true
          message.value =
            requestedApproval.value === null
              ? 'Cette correction exige l’autorisation de la gérante pour ce contenu exact. Demandez-la, puis renvoyez la correction après son accord.'
              : 'L’autorisation demandée n’est pas (ou plus) accordée : attendez la décision de la gérante, ou faites une nouvelle demande si elle est échue ou refusée.'
          return outcome
        }
        const byField: Record<string, string> = {}
        for (const [key, messages] of Object.entries(error.fields)) {
          const first = messages[0]
          if (first !== undefined) byField[key] = first
        }
        fields.value = byField
        message.value = error.message
        if (error.code === 'STATE_TRANSITION_REFUSED' || error.code === 'WRITE_OWNER_REQUIRED') {
          stale.value = true
        }
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

  async function send(intent: CorrectionIntent): Promise<MutationOutcome> {
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

  /** La demande d'autorisation est créée : la correction se renverra sous son identifiant. */
  function approvalRequested(approval: Approval): void {
    requestedApproval.value = approval
    approvalId.value = approval.id
    approvalNeeded.value = false
    message.value = null
    notice.value =
      'Demande d’autorisation envoyée à la gérante : elle n’est pas encore accordée. Renvoyez la correction après son accord.'
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
  }
}
