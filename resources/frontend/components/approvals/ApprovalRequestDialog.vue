<script setup lang="ts">
/**
 * Demande d'approbation EXACTE depuis l'action bloquée (B06 ; fichier 04 : « Si la caissière souhaite une
 * remise, afficher Demander une autorisation »). L'écran appelant fournit le CONTENU exact de l'opération
 * (`subject`, voir `domain/approvals/approvals.ts`) ; cette boîte ne demande que le motif. La demande part
 * par le répartiteur de mutations (`RequestApproval`) avec une clé d'idempotence liée à la boîte : un double
 * clic ou une reprise après coupure ne crée jamais deux demandes. La demande attend ensuite la décision de
 * la gérante (écran « Approbations ») ; elle ne vaut que pour CE contenu.
 *
 * Réutilisable par les autres écrans (brouillon de facture, corrections) : ils passent leur `subject` et
 * reçoivent l'approbation créée (`requested`). Aucune donnée clinique ; le motif n'est jamais conservé
 * ailleurs que dans la demande.
 */
import { computed, ref, useId, watch } from 'vue'

import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import {
  buildRequestApprovalPayload,
  KIND_LABELS,
  REASON_MAX_LENGTH,
  validateRequestReason,
} from '@/domain/approvals/approvals'
import type { Approval, ApprovalResponse, ApprovalSubject } from '@/domain/contracts/generated'

const props = withDefaults(
  defineProps<{
    open: boolean
    subject: ApprovalSubject | null
    /** Phrase qui dit ce qui sera demandé (« Dispense des frais d'examen de Mme X, visite de 10h15 »). */
    summary?: string
  }>(),
  { summary: '' },
)

const emit = defineEmits<{ requested: [approval: Approval]; cancel: [] }>()

/** Identifiant propre à chaque boîte : plusieurs demandes peuvent coexister sur un écran (cartes et tableau). */
const FIELD_ID = `${useId()}-motif`

const mutation = useMutation()
const reason = ref('')
const error = ref<string | null>(null)

const saving = computed(() => mutation.state.value === 'saving')
const title = computed(() =>
  props.subject === null ? 'Demander une approbation' : `Demander : ${KIND_LABELS[props.subject.kind]}`,
)

watch(
  () => props.open,
  (open) => {
    if (!open) {
      reason.value = ''
      error.value = null
      if (!saving.value) mutation.reset()
    }
  },
)

function messageOf(outcome: MutationOutcome): string | null {
  switch (outcome.kind) {
    case 'refused':
    case 'conflict':
      return outcome.error.fields['reason']?.[0] ?? outcome.error.message
    case 'session-expired':
      void revalidateSession().catch(() => undefined)
      return mutation.failureMessage.value
    default:
      return mutation.failureMessage.value
  }
}

async function confirm(): Promise<void> {
  if (saving.value || props.subject === null) return
  const checked = validateRequestReason(reason.value)
  if (!checked.ok) {
    error.value = checked.error
    return
  }
  error.value = null
  const payload = buildRequestApprovalPayload(
    mutation.effectId('approval_id'),
    props.subject,
    checked.reason,
  )
  const outcome = await mutation.submit({ name: 'RequestApproval', payload })
  if (outcome.kind === 'synced') {
    const response = outcome.result as ApprovalResponse
    emit('requested', response.data)
    mutation.reset()
    return
  }
  error.value = messageOf(outcome)
}
</script>

<template>
  <ConfirmDialog
    :open="props.open"
    :title="title"
    confirm-label="Envoyer la demande à la gérante"
    pending-label="Envoi de la demande…"
    :pending="saving"
    :initial-focus="`#${FIELD_ID}`"
    @confirm="confirm"
    @cancel="emit('cancel')"
  >
    <div class="space-y-3">
      <p v-if="props.summary">{{ props.summary }}</p>
      <p class="text-sm text-muted">
        La demande porte sur ce contenu exact : s’il change, une nouvelle demande sera nécessaire. Rien
        n’est accordé tant que la gérante n’a pas décidé.
      </p>
      <form novalidate @submit.prevent="confirm">
        <TextAreaField
          v-model="reason"
          :control-id="FIELD_ID"
          label="Motif de la demande"
          :maxlength="REASON_MAX_LENGTH"
          :error="error"
          required
        />
      </form>
    </div>
  </ConfirmDialog>
</template>
