import { computed, ref } from 'vue'

import type { MutationOutcome, MutationRequest } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'

/**
 * Écriture de configuration de l'agenda (gérante) : une mutation du chemin central, ses messages par champ (422)
 * et son issue. Une reprise après coupure rejoue la même clé ; un conflit de version (409) demande de relire.
 */
export function useAgendaWrite() {
  const mutation = useMutation()
  const saving = computed(() => mutation.state.value === 'saving')
  const fields = ref<Record<string, string>>({})
  const error = ref<string | null>(null)

  async function send(request: Omit<MutationRequest, 'idempotencyKey'>): Promise<boolean> {
    if (saving.value) return false
    fields.value = {}
    error.value = null
    const outcome: MutationOutcome = await mutation.submit(request)
    switch (outcome.kind) {
      case 'synced':
        mutation.reset()
        return true
      case 'conflict':
        error.value =
          'La configuration a changé entre-temps : elle a été relue, recommencez la modification.'
        mutation.reset()
        return false
      case 'refused': {
        const found: Record<string, string> = {}
        for (const [key, messages] of Object.entries(outcome.error.fields)) {
          if (messages[0] !== undefined) found[key] = messages[0]
        }
        fields.value = found
        error.value = outcome.error.message
        if (outcome.error.status === 409) mutation.reset()
        return false
      }
      case 'session-expired':
      case 'reauthentication-required':
        void revalidateSession().catch(() => undefined)
        error.value = mutation.failureMessage.value
        return false
      default:
        error.value = mutation.failureMessage.value
        return false
    }
  }

  return { mutation, saving, fields, error, send }
}
