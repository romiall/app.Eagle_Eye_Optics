<script setup lang="ts">
/**
 * « Annuler la visite » depuis la file du jour (P03 ; fichier 02 : la file distingue « annulé »), EN LIGNE.
 * Seulement AVANT l'examen (arrivé, en attente des frais d'examen, prêt pour l'examen), pour qui enregistre les
 * arrivées (`visit.register`). Confirmation explicite avec un MOTIF obligatoire (information d'accueil seulement,
 * jamais médicale) ; version de la visite attendue (409 : la file a changé, elle est relue). Le serveur refuse
 * aussi une visite dont l'examen a démarré ou qui porte une facture non abandonnée (409, dit tel quel). La
 * visite reste dans la file, marquée annulée : rien n'est supprimé. Une reprise après coupure rejoue la même
 * commande (même clé), jamais deux annulations.
 */
import { computed, ref, useId } from 'vue'

import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { canOnScreen, revalidateSession } from '@/app/session'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import type { Visit } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import {
  CANCEL_REASON_MAX_LENGTH,
  isCancellable,
  validateCancelReason,
} from '@/domain/patients/visits'

const props = defineProps<{ visit: Visit }>()

const emit = defineEmits<{ cancelled: [message: string]; stale: [message: string] }>()

const REASON_ID = `${useId()}-motif-annulation`

const mutation = useMutation()
const open = ref(false)
const reason = ref('')
const error = ref<string | null>(null)

const name = computed(() =>
  patientDisplayName(props.visit.patient.last_name, props.visit.patient.first_names),
)
const offered = computed(() => canOnScreen('visit.register') && isCancellable(props.visit))
const saving = computed(() => mutation.state.value === 'saving')

function close(): void {
  if (saving.value) return
  open.value = false
  error.value = null
}

async function afterOutcome(outcome: MutationOutcome): Promise<void> {
  switch (outcome.kind) {
    case 'synced':
      open.value = false
      reason.value = ''
      mutation.reset()
      emit(
        'cancelled',
        `Visite de ${name.value} annulée sur le serveur ; elle reste dans la file, marquée annulée.`,
      )
      return
    case 'conflict':
      open.value = false
      emit('stale', `La visite de ${name.value} a changé entre-temps : la file a été relue.`)
      return
    case 'refused':
      if (outcome.error.status === 409) {
        open.value = false
        emit('stale', `La visite de ${name.value} ne s’annule pas : ${outcome.error.message}`)
        return
      }
      error.value = outcome.error.fields['reason']?.[0] ?? outcome.error.message
      return
    case 'session-expired':
    case 'reauthentication-required':
      void revalidateSession().catch(() => undefined)
      error.value = mutation.failureMessage.value
      return
    default:
      error.value = mutation.failureMessage.value
  }
}

async function confirm(): Promise<void> {
  if (saving.value) return
  const checked = validateCancelReason(reason.value)
  if (!checked.ok) {
    error.value = checked.error
    document.getElementById(REASON_ID)?.focus()
    return
  }
  error.value = null
  await afterOutcome(
    await mutation.submit({
      name: 'CancelVisit',
      route: {
        method: 'POST',
        path: `/visits/${props.visit.id}/cancel`,
        expectedVersion: 'entity',
      },
      payload: { visit_id: props.visit.id, reason: checked.reason },
      expectedVersion: props.visit.version,
    }),
  )
}
</script>

<template>
  <div v-if="offered">
    <AppButton
      variant="ghost"
      :accessible-name="`Annuler la visite de ${name}`"
      @click="open = true"
      >Annuler la visite</AppButton
    >
    <ConfirmDialog
      :open="open"
      :title="`Annuler la visite de ${name} ?`"
      confirm-label="Annuler la visite"
      cancel-label="Garder la visite"
      tone="danger"
      :pending="saving"
      pending-label="Annulation en cours…"
      :initial-focus="REASON_ID"
      @confirm="confirm"
      @cancel="close"
    >
      <p class="max-w-prose">
        La visite reste dans la file du jour, marquée annulée. Une visite dont l’examen a démarré,
        ou qui porte une facture, ne s’annule pas.
      </p>
      <TextAreaField
        v-model="reason"
        :control-id="REASON_ID"
        label="Motif de l’annulation (obligatoire)"
        hint="Information d’accueil seulement, par exemple « patient reparti avant l’examen ». Aucune information médicale."
        :maxlength="CANCEL_REASON_MAX_LENGTH"
        :error="error"
        :disabled="saving"
      />
    </ConfirmDialog>
  </div>
</template>
