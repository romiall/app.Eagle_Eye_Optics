<script setup lang="ts">
/**
 * Émettre un avoir lié aux lignes d'une facture émise (B07 ; fichier 03 : « réduction de la vente »), EN
 * LIGNE. La facture, ses lignes, ses reçus et le stock ne changent pas : l'avoir est une pièce liée, numérotée
 * par le serveur (`TEST-AV-…`), au plus la part encore créditable de chaque ligne et de la facture (le
 * serveur tranche sous verrou, 422 `details.creditable`). La taxe de l'avoir est la part de la taxe FIGÉE de
 * la ligne d'origine, calculée par le serveur : l'écran n'en invente aucune. Au-delà du reste à encaisser,
 * un crédit en faveur du patient apparaît (remboursable). Réauthentification et autorisation exacte :
 * `useCorrection`.
 */
import { computed, reactive, ref } from 'vue'

import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import ApprovalRequestDialog from '@/components/approvals/ApprovalRequestDialog.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import MoneyField from '@/components/form/MoneyField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { creditNoteSubject } from '@/domain/approvals/approvals'
import {
  buildCreditNotePayload,
  CORRECTION_REASON_MAX_LENGTH,
  type CreditNoteErrors,
  creditableTotal,
  creditNoteSubjectLines,
  validateCreditNote,
} from '@/domain/billing/corrections'
import type { Approval, CreditNoteSubject, Invoice } from '@/domain/contracts/generated'

import { formatMoney, quantityLabel } from './format'
import { useCorrection } from './useCorrection'

const props = defineProps<{ invoice: Invoice }>()

const emit = defineEmits<{ done: [message: string]; stale: []; cancel: [] }>()

const correction = useCorrection()
const { mutation, message, notice, approvalNeeded } = correction

const sortedLines = computed(() =>
  [...props.invoice.lines].sort((left, right) => left.position - right.position),
)
/** Saisie par ligne de facture : montant crédité (null = ligne non retenue) et quantité indiquée. */
const amounts = reactive<Record<string, number | null>>(
  Object.fromEntries(props.invoice.lines.map((line) => [line.id, null])),
)
const quantities = reactive<Record<string, string>>(
  Object.fromEntries(props.invoice.lines.map((line) => [line.id, ''])),
)
const reason = ref('')
const errors = ref<CreditNoteErrors>({})
const asking = ref(false)
const reasonId = `avoir-motif-${props.invoice.id}`

const saving = computed(() => mutation.state.value === 'saving')
const creditable = computed(() => creditableTotal(props.invoice.balance))
const total = computed(() =>
  sortedLines.value.reduce((sum, line) => sum + (amounts[line.id] ?? 0), 0),
)
const canRequest = computed(() => canOnScreen('approval.request') && moduleOpenOnScreen('settings'))
/** Sans la permission de l'opération, ce compte DEMANDE seulement (la gérante exécute). */
const requestOnly = computed(() => !canOnScreen('credit_note.issue'))

function formLines() {
  return sortedLines.value.map((line) => {
    const quantity = quantities[line.id] ?? ''
    return {
      invoiceLineId: line.id,
      amount: amounts[line.id] ?? null,
      quantity: quantity === '' ? null : quantity,
    }
  })
}

/** Motif de remplacement pour valider les seules lignes (le motif de la demande est saisi dans sa boîte). */
const LINES_ONLY_REASON = 'lignes seulement'

const subject = computed<CreditNoteSubject | null>(() => {
  const checked = validateCreditNote(
    { lines: formLines(), reason: LINES_ONLY_REASON },
    props.invoice,
  )
  return checked.ok
    ? creditNoteSubject(props.invoice.id, creditNoteSubjectLines(checked.lines))
    : null
})

function lineFieldId(lineId: string): string {
  return `avoir-montant-${lineId}`
}

async function submit(): Promise<void> {
  if (saving.value) return
  const checked = validateCreditNote({ lines: formLines(), reason: reason.value }, props.invoice)
  if (!checked.ok) {
    errors.value = checked.errors
    const firstLine = Object.keys(checked.errors.byLine ?? {})[0]
    document.getElementById(firstLine !== undefined ? lineFieldId(firstLine) : reasonId)?.focus()
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
    name: 'IssueCreditNote',
    path: { id: props.invoice.id },
    payload: buildCreditNotePayload({
      invoiceId: props.invoice.id,
      creditNoteId: mutation.effectId('credit_note_id'),
      numberUsageId: mutation.effectId('number_usage_id'),
      documentId: mutation.effectId('document_id'),
      approvalId: correction.approvalId.value,
      reason: checked.reason,
      lines: checked.lines.map((line) => ({
        ...line,
        lineId: mutation.effectId(`credit_note_line:${line.invoiceLineId}`),
      })),
    }),
    expectedVersion: version,
  })
  after(outcome.kind, checked.total)
}

async function passwordConfirmed(): Promise<void> {
  after((await correction.passwordConfirmed()).kind, total.value)
}

function onRequested(approval: Approval): void {
  asking.value = false
  correction.approvalRequested(approval)
}

function after(kind: string, amount: number): void {
  if (kind === 'synced') {
    emit(
      'done',
      `Avoir de ${formatMoney(amount)} émis sur le serveur. La facture et ses reçus restent tels qu’émis.`,
    )
    return
  }
  if (correction.stale.value) emit('stale')
}
</script>

<template>
  <form
    class="space-y-4 rounded-xl border border-line bg-surface shadow-card p-4"
    novalidate
    @submit.prevent="submit"
  >
    <h3 class="text-lg font-semibold">
      Émettre un avoir sur la facture {{ props.invoice.number }}
    </h3>
    <p class="max-w-prose text-base">
      Un avoir réduit la vente : la facture, ses lignes et ses reçus ne changent pas. Indiquez, pour
      chaque ligne concernée, le montant à créditer. Part encore créditable de la facture :
      <strong class="tabular-nums">{{ formatMoney(creditable) }}</strong
      >.
    </p>

    <ul class="space-y-3">
      <li
        v-for="line in sortedLines"
        :key="line.id"
        class="space-y-2 rounded-xl border border-line p-3"
      >
        <p class="font-semibold break-words">{{ line.description_snapshot }}</p>
        <p class="text-sm text-muted">
          {{ quantityLabel(line.quantity) }} · montant de la ligne
          {{ formatMoney(line.line_total_gross) }}
        </p>
        <MoneyField
          :model-value="amounts[line.id] ?? null"
          :label="`Montant à créditer sur « ${line.description_snapshot} »`"
          field="amount_gross"
          :hint="`Facultatif ; au plus ${formatMoney(line.line_total_gross)}.`"
          :control-id="lineFieldId(line.id)"
          :error="errors.byLine?.[line.id] ?? null"
          :disabled="saving"
          @update:model-value="amounts[line.id] = $event"
        />
        <TextField
          :model-value="quantities[line.id] ?? ''"
          :label="`Quantité concernée (facultative) pour « ${line.description_snapshot} »`"
          inputmode="decimal"
          :control-id="`avoir-quantite-${line.id}`"
          :disabled="saving"
          @update:model-value="quantities[line.id] = $event"
        />
      </li>
    </ul>

    <p class="text-base">
      Total de l’avoir :
      <strong class="tabular-nums">{{ formatMoney(total) }}</strong>
    </p>
    <p v-if="errors.lines" role="alert" class="text-sm font-medium text-danger">
      <span aria-hidden="true">⚠ </span>{{ errors.lines }}
    </p>

    <TextAreaField
      v-model="reason"
      :control-id="reasonId"
      label="Motif (obligatoire)"
      hint="Par exemple : remise accordée après émission, article non fourni."
      :maxlength="CORRECTION_REASON_MAX_LENGTH"
      :error="errors.reason ?? null"
      :disabled="saving"
    />

    <StateMessage
      v-if="message"
      :state="approvalNeeded ? 'conflict' : 'error'"
      title="Avoir non émis"
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
      <AppButton type="submit" variant="danger" :pending="saving" pending-label="Émission…">{{
        requestOnly ? 'Demander l’autorisation' : 'Émettre l’avoir'
      }}</AppButton>
      <AppButton variant="ghost" :disabled="saving" @click="emit('cancel')">Annuler</AppButton>
    </div>

    <ApprovalRequestDialog
      :open="asking"
      :subject="subject"
      :summary="`Avoir de ${formatMoney(total)} sur la facture ${props.invoice.number ?? ''}.`"
      @requested="onRequested"
      @cancel="asking = false"
    />
    <PasswordConfirmDialog
      :open="correction.askingPassword.value"
      action="L’émission d’un avoir"
      @confirmed="passwordConfirmed"
      @cancel="correction.askingPassword.value = false"
    />
  </form>
</template>
