<script setup lang="ts">
/**
 * Contre-écriture d'un paiement enregistré à tort (B07 ; fichier 03 : « erreur d'encaissement »), EN LIGNE.
 * Ce n'est PAS une sortie d'argent : le paiement, son reçu et son numéro restent tels qu'émis, la
 * contre-écriture est une pièce liée et motivée, au plus la part encore contre-passable du paiement (le
 * serveur tranche sous verrou). Réauthentification et autorisation exacte de la gérante : voir
 * `useCorrection`. Version attendue = `ledger_version` de la facture affichée.
 */
import { computed, ref } from 'vue'

import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import ApprovalRequestDialog from '@/components/approvals/ApprovalRequestDialog.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import MoneyField from '@/components/form/MoneyField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { paymentReversalSubject } from '@/domain/approvals/approvals'
import {
  buildReversalPayload,
  CORRECTION_REASON_MAX_LENGTH,
  reversibleAmount,
  type ReversalErrors,
  validateReversal,
} from '@/domain/billing/corrections'
import type { Approval, Invoice, InvoicePayment } from '@/domain/contracts/generated'

import { formatMoney, paymentMethodLabel } from './format'
import { useCorrection } from './useCorrection'

const props = defineProps<{ invoice: Invoice; payment: InvoicePayment }>()

const emit = defineEmits<{ done: [message: string]; stale: []; cancel: [] }>()

const ids = {
  amount: `contre-ecriture-montant-${props.payment.id}`,
  reason: `contre-ecriture-motif-${props.payment.id}`,
}

const correction = useCorrection()
const { mutation, message, notice, approvalNeeded } = correction
const amount = ref<number | null>(null)
const reason = ref('')
const errors = ref<ReversalErrors>({})
const asking = ref(false)
const amountField = ref<{ commit: () => boolean } | null>(null)

const reversible = computed(() => reversibleAmount(props.payment))
const saving = computed(() => mutation.state.value === 'saving')
const canRequest = computed(() => canOnScreen('approval.request') && moduleOpenOnScreen('settings'))
/** Sans la permission de l'opération, ce compte DEMANDE seulement (la gérante exécute). */
const requestOnly = computed(() => !canOnScreen('payment.reverse'))
const subject = computed(() =>
  amount.value === null
    ? null
    : paymentReversalSubject(props.invoice.id, props.payment.id, amount.value),
)

async function submit(): Promise<void> {
  if (saving.value) return
  const committed = amountField.value?.commit() ?? true
  const checked = validateReversal(
    { amount: committed ? amount.value : null, reason: reason.value },
    reversible.value,
  )
  if (!checked.ok) {
    errors.value = checked.errors
    document.getElementById(checked.errors.amount ? ids.amount : ids.reason)?.focus()
    return
  }
  errors.value = {}
  if (requestOnly.value) {
    asking.value = true
    return
  }
  const version = props.invoice.ledger_version
  if (version === null) return
  const outcome = await correction.send({
    name: 'ReversePayment',
    path: { id: props.payment.id },
    payload: buildReversalPayload({
      invoiceId: props.invoice.id,
      paymentId: props.payment.id,
      reversalId: mutation.effectId('reversal_id'),
      amount: checked.amount,
      reason: checked.reason,
      approvalId: correction.approvalId.value,
    }),
    expectedVersion: version,
  })
  after(outcome.kind)
}

function onRequested(approval: Approval): void {
  asking.value = false
  correction.approvalRequested(approval)
}

async function passwordConfirmed(): Promise<void> {
  after((await correction.passwordConfirmed()).kind)
}

function after(kind: string): void {
  if (kind === 'synced') {
    emit(
      'done',
      `Contre-écriture de ${formatMoney(amount.value ?? 0)} enregistrée sur le serveur. Le reçu ${props.payment.receipt_number ?? ''} reste tel qu’émis.`,
    )
    return
  }
  if (correction.stale.value) emit('stale')
  const amountMessage = correction.fields.value['amount']
  const reasonMessage = correction.fields.value['reason']
  if (amountMessage !== undefined || reasonMessage !== undefined) {
    errors.value = {
      ...(amountMessage === undefined ? {} : { amount: amountMessage }),
      ...(reasonMessage === undefined ? {} : { reason: reasonMessage }),
    }
  }
}
</script>

<template>
  <form
    class="space-y-4 rounded-xl border border-line bg-surface shadow-card p-4"
    novalidate
    @submit.prevent="submit"
  >
    <h3 class="text-lg font-semibold">
      Contre-passer le paiement {{ props.payment.receipt_number ?? '' }}
    </h3>
    <p class="max-w-prose text-base">
      {{ paymentMethodLabel(props.payment.method) }}, {{ formatMoney(props.payment.amount) }}. Une
      contre-écriture corrige une erreur d’enregistrement : ce n’est pas un remboursement, le reçu
      d’origine n’est jamais modifié ni renuméroté. Part encore contre-passable :
      <strong class="tabular-nums">{{ formatMoney(reversible) }}</strong
      >.
    </p>

    <MoneyField
      ref="amountField"
      v-model="amount"
      label="Montant à contre-passer"
      field="amount"
      :hint="`Au plus ${formatMoney(reversible)}.`"
      :control-id="ids.amount"
      :error="errors.amount ?? null"
      :disabled="saving"
      required
    />
    <TextAreaField
      v-model="reason"
      :control-id="ids.reason"
      label="Motif (obligatoire)"
      hint="Par exemple : encaissement saisi deux fois, mauvais montant."
      :maxlength="CORRECTION_REASON_MAX_LENGTH"
      :error="errors.reason ?? null"
      :disabled="saving"
    />

    <StateMessage
      v-if="message"
      :state="approvalNeeded ? 'conflict' : 'error'"
      title="Correction non enregistrée"
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
      <AppButton type="submit" variant="danger" :pending="saving" pending-label="Enregistrement…">{{
        requestOnly ? 'Demander l’autorisation' : 'Enregistrer la contre-écriture'
      }}</AppButton>
      <AppButton variant="ghost" :disabled="saving" @click="emit('cancel')">Annuler</AppButton>
    </div>

    <ApprovalRequestDialog
      :open="asking"
      :subject="subject"
      :summary="`Contre-écriture de ${formatMoney(amount ?? 0)} sur le paiement ${props.payment.receipt_number ?? ''} de la facture ${props.invoice.number ?? ''}.`"
      @requested="onRequested"
      @cancel="asking = false"
    />
    <PasswordConfirmDialog
      :open="correction.askingPassword.value"
      action="Une contre-écriture de paiement"
      @confirmed="passwordConfirmed"
      @cancel="correction.askingPassword.value = false"
    />
  </form>
</template>
