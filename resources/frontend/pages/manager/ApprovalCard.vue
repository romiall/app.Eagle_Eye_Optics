<script setup lang="ts">
/**
 * Une demande d'approbation dans la liste de la gérante (B06) : nature, cible (patient, facture, visite,
 * paiement), contenu exact VÉRIFIÉ par le serveur, motif, demandeuse, échéance, décision. La gérante
 * accorde ou refuse (un refus exige un motif) ; la décision exige une réauthentification récente : sur 423,
 * la confirmation du mot de passe s'ouvre, puis la MÊME commande est reprise avec la même clé
 * d'idempotence (jamais deux décisions). Une demande ne se décide pas par la personne qui l'a faite, ni
 * après son échéance : le serveur revérifie tout.
 */
import { computed, ref } from 'vue'

import { formatInstantFr } from '@/app/clinicTime'
import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { canOnScreen, knownAccountId, revalidateSession } from '@/app/session'
import TextAreaField from '@/components/form/TextAreaField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import {
  buildDecision,
  DECISION_REASON_MAX_LENGTH,
  describeSubject,
  isDecidable,
  kindLabel,
  statusLabel,
  statusSymbol,
} from '@/domain/approvals/approvals'
import type { Approval } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import { formatMoney, paymentMethodLabel } from '@/pages/sales/format'

const props = defineProps<{ approval: Approval }>()

/**
 * `decided` : la liste est à relire. Le message de réussite est rendu par la page : la relecture
 * remplace la liste (et cette carte) ; une demande décidée sort d'ailleurs de « En attente ».
 */
const emit = defineEmits<{ decided: [notice: string | null] }>()

const mutation = useMutation()
const reason = ref('')
const error = ref<string | null>(null)
const askingPassword = ref(false)
const choice = ref<'APPROVED' | 'REFUSED' | null>(null)

const fieldId = computed(() => `decision-${props.approval.id}`)
const saving = computed(() => mutation.state.value === 'saving')
const own = computed(() => props.approval.requested_by === knownAccountId())
const canDecide = computed(
  () => canOnScreen('approval.decide') && isDecidable(props.approval) && !own.value,
)

const subjectLines = computed(() =>
  describeSubject(props.approval.subject, formatMoney, paymentMethodLabel),
)

const patient = computed(() => {
  const target = props.approval.target.patient
  return target === null
    ? null
    : `${patientDisplayName(target.last_name, target.first_names)} (dossier ${target.record_number})`
})

async function afterOutcome(outcome: MutationOutcome): Promise<void> {
  switch (outcome.kind) {
    case 'synced':
      reason.value = ''
      mutation.reset()
      emit(
        'decided',
        choice.value === 'APPROVED'
          ? 'Approbation accordée et enregistrée sur le serveur.'
          : 'Refus enregistré sur le serveur.',
      )
      return
    case 'reauthentication-required':
      askingPassword.value = true
      return
    case 'refused':
    case 'conflict':
      error.value = outcome.error.fields['reason']?.[0] ?? outcome.error.message
      if (outcome.error.status === 409) emit('decided', null)
      return
    case 'session-expired':
      void revalidateSession().catch(() => undefined)
      error.value = mutation.failureMessage.value
      return
    default:
      error.value = mutation.failureMessage.value
  }
}

async function decide(decision: 'APPROVED' | 'REFUSED'): Promise<void> {
  if (saving.value) return
  const checked = buildDecision(props.approval.id, decision, reason.value)
  if (!checked.ok) {
    error.value = checked.error
    document.getElementById(fieldId.value)?.focus()
    return
  }
  error.value = null
  choice.value = decision
  await afterOutcome(
    await mutation.submit({
      name: 'DecideApproval',
      path: { id: props.approval.id },
      payload: checked.payload,
    }),
  )
}

async function passwordConfirmed(): Promise<void> {
  askingPassword.value = false
  await afterOutcome(await mutation.retry())
}
</script>

<template>
  <article class="space-y-3 rounded-lg border-2 border-line bg-surface p-4">
    <header class="flex flex-wrap items-baseline justify-between gap-2">
      <h3 class="text-lg font-semibold">{{ kindLabel(props.approval.kind) }}</h3>
      <p class="font-medium">
        <span aria-hidden="true">{{ statusSymbol(props.approval.effective_status) }}</span
        ><span class="sr-only">Statut :</span>
        {{ statusLabel(props.approval.effective_status) }}
      </p>
    </header>

    <dl class="grid gap-x-4 gap-y-1 text-base sm:grid-cols-[auto_1fr]">
      <template v-if="patient">
        <dt class="font-medium text-muted">Patient</dt>
        <dd class="break-words">{{ patient }}</dd>
      </template>
      <template v-if="props.approval.target.invoice_number">
        <dt class="font-medium text-muted">Facture</dt>
        <dd>{{ props.approval.target.invoice_number }}</dd>
      </template>
      <template v-if="props.approval.target.visit_occurred_at">
        <dt class="font-medium text-muted">Visite</dt>
        <dd>Arrivée le {{ formatInstantFr(props.approval.target.visit_occurred_at) }}</dd>
      </template>
      <template v-if="props.approval.target.payment">
        <dt class="font-medium text-muted">Paiement visé</dt>
        <dd>
          {{ formatMoney(props.approval.target.payment.amount) }},
          {{ paymentMethodLabel(props.approval.target.payment.method) }}, le
          {{ formatInstantFr(props.approval.target.payment.effective_at) }}
        </dd>
      </template>
      <template v-for="line in subjectLines" :key="line.label">
        <dt class="font-medium text-muted">{{ line.label }}</dt>
        <dd class="break-words">{{ line.value }}</dd>
      </template>
      <dt class="font-medium text-muted">Motif</dt>
      <dd class="break-words whitespace-pre-line">{{ props.approval.reason }}</dd>
      <dt class="font-medium text-muted">Demandée par</dt>
      <dd>
        {{ props.approval.requested_by_name }}, le
        {{ formatInstantFr(props.approval.requested_at) }}
      </dd>
      <template v-if="props.approval.expires_at">
        <dt class="font-medium text-muted">Échéance</dt>
        <dd>{{ formatInstantFr(props.approval.expires_at) }}</dd>
      </template>
      <template v-if="props.approval.decided_at">
        <dt class="font-medium text-muted">Décision</dt>
        <dd>
          {{ props.approval.decided_by_name ?? 'Compte inconnu' }}, le
          {{ formatInstantFr(props.approval.decided_at) }}
        </dd>
      </template>
      <template v-if="props.approval.decision_reason">
        <dt class="font-medium text-muted">Motif de la décision</dt>
        <dd class="break-words whitespace-pre-line">{{ props.approval.decision_reason }}</dd>
      </template>
    </dl>

    <p v-if="own && isDecidable(props.approval)" class="text-sm text-muted">
      Vous avez fait cette demande : une autre personne habilitée la décide. Pour agir vous-même,
      exécutez directement l’opération.
    </p>

    <form v-if="canDecide" novalidate class="space-y-3" @submit.prevent>
      <TextAreaField
        v-model="reason"
        :control-id="fieldId"
        label="Motif de la décision (obligatoire pour un refus)"
        :maxlength="DECISION_REASON_MAX_LENGTH"
        :error="error"
      />
      <div class="flex flex-wrap gap-3">
        <AppButton
          variant="primary"
          :pending="saving && choice === 'APPROVED'"
          :disabled="saving"
          pending-label="Accord en cours…"
          :accessible-name="`Accorder : ${kindLabel(props.approval.kind)}`"
          @click="decide('APPROVED')"
          >Accorder</AppButton
        >
        <AppButton
          variant="danger"
          :pending="saving && choice === 'REFUSED'"
          :disabled="saving"
          pending-label="Refus en cours…"
          :accessible-name="`Refuser : ${kindLabel(props.approval.kind)}`"
          @click="decide('REFUSED')"
          >Refuser</AppButton
        >
      </div>
    </form>
    <p v-else-if="error" role="alert" class="text-sm font-medium text-danger">
      <span aria-hidden="true">⚠ </span>{{ error }}
    </p>

    <PasswordConfirmDialog
      :open="askingPassword"
      action="La décision d’une approbation"
      @confirmed="passwordConfirmed"
      @cancel="askingPassword = false"
    />
  </article>
</template>
