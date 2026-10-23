<script setup lang="ts">
/**
 * Rembourser un crédit au patient (B08 ; fichier 03 : sortie d'argent liée à un avoir), EN LIGNE. Au plus la
 * part de l'avoir non encore remboursée ET le crédit disponible de la facture (projection du serveur, qui
 * tranche sous verrou : 422 `details.refundable_credit`). Espèces : session de caisse OUVERTE de l'auteur ;
 * autres modes : référence (numéro de transaction ou de virement). Pièce numérotée par le serveur
 * (`TEST-RMB-…`), jamais renumérotée ; la facture, l'avoir et les reçus ne changent pas. Une reprise après
 * coupure rejoue la même commande (même clé, même instant) : jamais deux remboursements. Réauthentification et
 * autorisation exacte : `useCorrection`.
 */
import { computed, ref } from 'vue'

import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import ApprovalRequestDialog from '@/components/approvals/ApprovalRequestDialog.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import MoneyField from '@/components/form/MoneyField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { refundSubject } from '@/domain/approvals/approvals'
import {
  buildRefundPayload,
  isRefundMethod,
  REFUND_METHODS,
  REFUND_REFERENCE_MAX_LENGTH,
  refundableAmount,
  type RefundErrors,
  validateRefund,
} from '@/domain/billing/corrections'
import type { Approval, Invoice } from '@/domain/contracts/generated'
import { currentInstant } from '@/domain/patients/visits'

import { formatMoney, paymentMethodLabel } from './format'
import { useCorrection } from './useCorrection'

type CreditNoteEntry = Invoice['credit_notes'][number]

const props = withDefaults(
  defineProps<{
    invoice: Invoice
    note: CreditNoteEntry
    /** Session de caisse ouverte du compte (espèces), ou null. */
    cashSessionId: string | null
    /** Lecture de cette session : rien n'est conclu tant que le serveur n'a pas répondu. */
    cashSessionRead?: 'ready' | 'loading' | 'unavailable'
  }>(),
  { cashSessionRead: 'ready' },
)

const emit = defineEmits<{ done: [message: string]; stale: []; cancel: [] }>()

const ids = {
  amount: `remboursement-montant-${props.note.id}`,
  method: `remboursement-mode-${props.note.id}`,
  reference: `remboursement-reference-${props.note.id}`,
}

const correction = useCorrection()
const { mutation, message, notice, approvalNeeded } = correction
const amount = ref<number | null>(null)
const methodValue = ref<string | null>(null)
const reference = ref('')
const errors = ref<RefundErrors>({})
const asking = ref(false)
const amountField = ref<{ commit: () => boolean } | null>(null)
/** Marqueur : la validation d'une simple demande d'autorisation ne réclame pas de session de caisse. */
const REQUEST_WITHOUT_SESSION = 'demande-sans-session'
/** Instant du remboursement, fixé à la première demande de cette intention (reprise identique). */
let effectiveAt: string | null = null

const method = computed(() => (isRefundMethod(methodValue.value) ? methodValue.value : null))
const refundable = computed(() => refundableAmount(props.note, props.invoice.balance))
const saving = computed(() => mutation.state.value === 'saving')
const methodOptions = REFUND_METHODS.map((value) => ({ value, label: paymentMethodLabel(value) }))
const cashUnknown = computed(
  () =>
    method.value === 'CASH' && props.cashSessionId === null && props.cashSessionRead !== 'ready',
)
const canRequest = computed(() => canOnScreen('approval.request') && moduleOpenOnScreen('settings'))
/** Sans la permission de l'opération, ce compte DEMANDE seulement (la gérante exécute). */
const requestOnly = computed(() => !canOnScreen('refund.execute'))
const subject = computed(() =>
  amount.value === null || method.value === null
    ? null
    : refundSubject(props.invoice.id, props.note.id, amount.value, method.value),
)

async function submit(): Promise<void> {
  if (saving.value || cashUnknown.value) return
  const committed = amountField.value?.commit() ?? true
  const checked = validateRefund(
    { amount: committed ? amount.value : null, method: method.value, reference: reference.value },
    {
      refundable: refundable.value,
      // Une DEMANDE d'autorisation n'exige pas de session de caisse : c'est l'exécution qui l'exige.
      cashSessionId: requestOnly.value ? REQUEST_WITHOUT_SESSION : props.cashSessionId,
    },
  )
  if (!checked.ok) {
    errors.value = checked.errors
    const first = checked.errors.amount
      ? ids.amount
      : checked.errors.method
        ? ids.method
        : ids.reference
    document.getElementById(first)?.focus()
    return
  }
  errors.value = {}
  if (requestOnly.value) {
    asking.value = true
    return
  }
  const version = props.invoice.ledger_version
  if (version === null) return
  effectiveAt ??= currentInstant()
  const outcome = await correction.send({
    name: 'RefundCredit',
    path: { id: props.invoice.id },
    payload: buildRefundPayload({
      invoiceId: props.invoice.id,
      refundId: mutation.effectId('refund_id'),
      creditNoteId: props.note.id,
      numberUsageId: mutation.effectId('number_usage_id'),
      documentId: mutation.effectId('document_id'),
      approvalId: correction.approvalId.value,
      amount: checked.amount,
      method: checked.method,
      reference: checked.reference,
      effectiveAt,
      cashSessionId: props.cashSessionId,
    }),
    expectedVersion: version,
  })
  after(outcome.kind)
}

async function passwordConfirmed(): Promise<void> {
  after((await correction.passwordConfirmed()).kind)
}

function onRequested(approval: Approval): void {
  asking.value = false
  correction.approvalRequested(approval)
}

function after(kind: string): void {
  if (kind === 'synced') {
    emit(
      'done',
      `Remboursement de ${formatMoney(amount.value ?? 0)} enregistré sur le serveur (avoir ${props.note.number ?? ''}).`,
    )
    return
  }
  if (correction.stale.value) emit('stale')
  const byField: RefundErrors = {}
  const map: Record<string, keyof RefundErrors> = {
    amount: 'amount',
    method: 'method',
    reference: 'reference',
    cash_session_id: 'cashSession',
  }
  for (const [key, field] of Object.entries(map)) {
    const text = correction.fields.value[key]
    if (text !== undefined) byField[field] = text
  }
  if (Object.keys(byField).length > 0) errors.value = byField
}
</script>

<template>
  <form
    class="space-y-4 rounded-xl border border-line bg-surface shadow-card p-4"
    novalidate
    @submit.prevent="submit"
  >
    <h3 class="text-lg font-semibold">Rembourser le crédit de l’avoir {{ props.note.number }}</h3>
    <p class="max-w-prose text-base">
      Sortie d’argent remise au patient, liée à cet avoir. Montant remboursable :
      <strong class="tabular-nums">{{ formatMoney(refundable) }}</strong
      >.
    </p>

    <MoneyField
      ref="amountField"
      v-model="amount"
      label="Montant remboursé"
      field="amount"
      :hint="`Au plus ${formatMoney(refundable)}.`"
      :control-id="ids.amount"
      :error="errors.amount ?? null"
      :disabled="saving"
      required
    />
    <SelectField
      v-model="methodValue"
      label="Mode de remboursement"
      :options="methodOptions"
      :control-id="ids.method"
      :error="errors.method ?? null"
      :disabled="saving"
      required
    />
    <TextField
      v-model="reference"
      label="Référence (numéro de transaction ou de virement)"
      :control-id="ids.reference"
      :maxlength="REFUND_REFERENCE_MAX_LENGTH"
      :error="errors.reference ?? null"
      :disabled="saving"
    />
    <p v-if="errors.cashSession" role="alert" class="text-sm font-medium text-danger">
      <span aria-hidden="true">⚠ </span>{{ errors.cashSession }}
    </p>
    <p v-if="cashUnknown" class="text-sm text-muted">Lecture de la session de caisse…</p>

    <StateMessage
      v-if="message"
      :state="approvalNeeded ? 'conflict' : 'error'"
      title="Remboursement non enregistré"
    >
      {{ message }}
      <template v-if="approvalNeeded && canRequest && subject" #action>
        <AppButton variant="secondary" @click="asking = true">Demander l’autorisation</AppButton>
      </template>
    </StateMessage>
    <StateMessage v-if="notice" state="empty" title="Autorisation demandée">{{
      notice
    }}</StateMessage>

    <div class="flex flex-wrap gap-3">
      <AppButton
        type="submit"
        variant="danger"
        :pending="saving"
        :disabled="cashUnknown"
        pending-label="Enregistrement…"
        >{{ requestOnly ? 'Demander l’autorisation' : 'Enregistrer le remboursement' }}</AppButton
      >
      <AppButton variant="ghost" :disabled="saving" @click="emit('cancel')">Annuler</AppButton>
    </div>

    <ApprovalRequestDialog
      :open="asking"
      :subject="subject"
      :summary="`Remboursement de ${formatMoney(amount ?? 0)} sur l’avoir ${props.note.number ?? ''}.`"
      @requested="onRequested"
      @cancel="asking = false"
    />
    <PasswordConfirmDialog
      :open="correction.askingPassword.value"
      action="Un remboursement"
      @confirmed="passwordConfirmed"
      @cancel="correction.askingPassword.value = false"
    />
  </form>
</template>
