<script setup lang="ts">
/**
 * Revue par la gérante de l'écart d'une session clôturée (L1D05 : « Écart motivé et autorisé »). La clôture a figé
 * l'attendu, le compté, l'écart et le motif de la caissière ; la gérante l'ACCEPTE ou le CONTESTE (note obligatoire),
 * une seule fois, avec son mot de passe. Rien n'est réécrit : la décision est un fait d'audit. La revue se lit à la
 * demande (bouton) pour ne pas multiplier les lectures dans la liste des sessions.
 */
import { computed, ref, shallowRef } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { formatInstantFr } from '@/app/clinicTime'
import { getCashSessionMovements } from '@/app/services/billing'
import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'
import StateMessage from '@/components/feedback/StateMessage.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import type { CashVarianceReview } from '@/domain/contracts/generated'

const props = defineProps<{ sessionId: string; cashierName: string }>()

const expanded = ref(false)
const loading = ref(false)
const problem = ref<string | null>(null)
const review = shallowRef<CashVarianceReview | null>(null)
const decision = ref<string | null>(null)
const note = ref('')
const error = ref<string | null>(null)
const askingPassword = ref(false)
const mutation = useMutation()
const saving = computed(() => mutation.state.value === 'saving')

const DECISIONS = [
  { value: 'ACCEPTED', label: 'Accepter l’écart (motif jugé suffisant)' },
  { value: 'DISPUTED', label: 'Contester l’écart (à éclaircir)' },
]

async function open(): Promise<void> {
  expanded.value = true
  loading.value = true
  problem.value = null
  try {
    review.value = (await getCashSessionMovements(props.sessionId)).data.variance_review
  } catch (failure) {
    problem.value =
      failure instanceof NetworkUnavailable
        ? 'Le serveur n’a pas répondu : la revue n’est pas affichée.'
        : failure instanceof ApiError
          ? failure.message
          : 'Une erreur inattendue est survenue.'
  } finally {
    loading.value = false
  }
}

async function after(outcome: MutationOutcome): Promise<void> {
  switch (outcome.kind) {
    case 'synced':
      await open()
      return
    case 'reauthentication-required':
      askingPassword.value = true
      return
    case 'refused':
    case 'conflict':
      error.value = Object.values(outcome.error.fields).flat()[0] ?? outcome.error.message
      if (outcome.error.code === 'STATE_TRANSITION_REFUSED') await open()
      return
    case 'session-expired':
      void revalidateSession().catch(() => undefined)
      return
    default:
      error.value = mutation.failureMessage.value
  }
}

async function submit(): Promise<void> {
  error.value = null
  if (decision.value === null) {
    error.value = 'Choisissez votre décision.'
    return
  }
  if (decision.value === 'DISPUTED' && note.value.trim().length < 3) {
    error.value = 'Une contestation exige une note (3 caractères au moins).'
    return
  }
  await after(
    await mutation.submit({
      name: 'ReviewCashVariance',
      route: {
        method: 'POST',
        path: `/cash-sessions/${encodeURIComponent(props.sessionId)}/variance-review`,
        expectedVersion: 'none',
      },
      payload: {
        cash_session_id: props.sessionId,
        decision: decision.value,
        note: note.value.trim() === '' ? null : note.value.trim(),
      },
    }),
  )
}

async function passwordConfirmed(): Promise<void> {
  askingPassword.value = false
  await after(await mutation.retry())
}
</script>

<template>
  <div class="space-y-3">
    <AppButton
      v-if="!expanded"
      variant="secondary"
      :accessible-name="`Revoir l’écart de la session de ${props.cashierName}`"
      @click="open"
      >Revoir l’écart</AppButton
    >
    <template v-else>
      <StateMessage v-if="loading" state="loading" title="Lecture de la revue…" />
      <StateMessage v-else-if="problem" state="offline" title="Revue non lue">{{
        problem
      }}</StateMessage>
      <StateMessage
        v-else-if="review"
        :state="review.decision === 'ACCEPTED' ? 'success' : 'conflict'"
        :title="
          review.decision === 'ACCEPTED'
            ? 'Écart accepté par la gérante'
            : 'Écart contesté par la gérante'
        "
      >
        {{ review.reviewed_by_name }}, le {{ formatInstantFr(review.reviewed_at)
        }}<template v-if="review.note"> : {{ review.note }}</template>
      </StateMessage>
      <form
        v-else
        novalidate
        :aria-label="`Revue de l’écart de la session de ${props.cashierName}`"
        class="space-y-3 rounded-xl border border-line p-3"
        @submit.prevent="submit"
      >
        <SelectField
          v-model="decision"
          label="Décision"
          :options="DECISIONS"
          required
          :control-id="`revue-decision-${props.sessionId}`"
        />
        <TextAreaField
          v-model="note"
          label="Note (obligatoire pour une contestation)"
          :maxlength="1000"
          :rows="2"
          :control-id="`revue-note-${props.sessionId}`"
        />
        <p v-if="error" role="alert" class="text-sm font-medium text-danger">{{ error }}</p>
        <AppButton type="submit" :pending="saving" pending-label="Enregistrement…"
          >Enregistrer la revue</AppButton
        >
      </form>
    </template>
    <PasswordConfirmDialog
      :open="askingPassword"
      action="La revue d’un écart de caisse"
      @confirmed="passwordConfirmed"
      @cancel="askingPassword = false"
    />
  </div>
</template>
