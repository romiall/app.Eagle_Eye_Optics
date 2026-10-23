<script setup lang="ts">
/**
 * « Remettre au patient » (I01), EN LIGNE : remise partielle ou totale d'une facture émise. Une quantité par ligne
 * encore à remettre (par défaut : tout le reste) ; une ligne laissée vide n'est pas remise cette fois. Le serveur prend
 * la quantité sur la réserve posée à l'émission (lot non expiré au jour du cabinet, le plus proche de l'expiration
 * d'abord) et sort l'article du stock dans la même transaction. Rien n'est enregistré sur ce poste.
 *
 * Facture non soldée : la remise à crédit exige l'approbation exacte de la gérante (reste dû et quantités). La gérante
 * l'exécute directement ; un autre compte DEMANDE l'autorisation, puis renvoie la remise après l'accord.
 */
import { computed, ref } from 'vue'

import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import ApprovalRequestDialog from '@/components/approvals/ApprovalRequestDialog.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { creditDeliverySubject } from '@/domain/approvals/approvals'
import type { Approval, Invoice, InvoiceDeliveries } from '@/domain/contracts/generated'
import {
  buildDeliveryPayload,
  creditDeliveryRequired,
  deliveryCeiling,
  deliveryLineFieldFromServer,
  pendingLines,
  quantityLabel,
  validateDelivery,
} from '@/domain/inventory/delivery'
import { currentInstant } from '@/domain/patients/visits'
import { useStockCommand } from '@/pages/stock/useStockCommand'

import { formatMoney } from './format'

const props = defineProps<{ invoice: Invoice; view: InvoiceDeliveries }>()

const emit = defineEmits<{ done: [message: string]; stale: []; cancel: [] }>()

const command = useStockCommand(
  'Cette facture a encore un reste à encaisser : la remise exige l’autorisation de la gérante pour ces quantités et ce reste dû. Demandez-la, puis renvoyez la remise après son accord.',
)
const { mutation, message, notice, approvalNeeded } = command

const lines = computed(() => pendingLines(props.view))
const quantities = ref<Record<string, string>>(
  Object.fromEntries(
    lines.value.map((line) => {
      const ceiling = deliveryCeiling(line)
      return [line.invoice_line_id, ceiling.isZero() ? '' : quantityLabel(ceiling.canonical())]
    }),
  ),
)
const errors = ref<Record<string, string>>({})
const general = ref<string | null>(null)
const asking = ref(false)
/** Instant de la remise, fixé à la première tentative : une reprise renvoie la même charge. */
let deliveredAt: string | null = null
let submitted: { invoiceLineId: string }[] = []

const saving = computed(() => mutation.state.value === 'saving')
const credit = computed(() => creditDeliveryRequired(props.view))
const canRequest = computed(() => canOnScreen('approval.request') && moduleOpenOnScreen('settings'))
/** Sans `approval.decide`, une remise à crédit se DEMANDE d'abord (la gérante l'autorise). */
const requestOnly = computed(
  () => credit.value && !canOnScreen('approval.decide') && command.requestedApproval.value === null,
)
const subject = computed(() => {
  if (!credit.value || props.view.amount_due === null) return null
  const checked = validateDelivery(props.view, { quantities: quantities.value })
  if (!checked.ok) return null
  return creditDeliverySubject(
    props.invoice.id,
    props.view.amount_due,
    checked.lines.map((line) => ({ invoice_line_id: line.invoiceLineId, quantity: line.quantity })),
  )
})

function fieldId(invoiceLineId: string): string {
  return `remise-quantite-${invoiceLineId}`
}

function setQuantity(invoiceLineId: string, value: string): void {
  quantities.value = { ...quantities.value, [invoiceLineId]: value }
  if (errors.value[invoiceLineId] !== undefined) {
    const rest = { ...errors.value }
    delete rest[invoiceLineId]
    errors.value = rest
  }
}

async function submit(): Promise<void> {
  if (saving.value) return
  general.value = null
  const checked = validateDelivery(props.view, { quantities: quantities.value })
  if (!checked.ok) {
    errors.value = { ...checked.errors }
    general.value = checked.general
    const first = Object.keys(checked.errors)[0]
    if (first !== undefined) document.getElementById(fieldId(first))?.focus()
    return
  }
  errors.value = {}
  if (requestOnly.value) {
    asking.value = true
    return
  }
  deliveredAt ??= currentInstant()
  submitted = checked.lines
  const outcome = await command.send({
    name: 'ConfirmDelivery',
    path: { id: props.invoice.id },
    payload: buildDeliveryPayload({
      invoiceId: props.invoice.id,
      deliveryId: mutation.effectId('delivery_id'),
      deliveredAt,
      creditApprovalId: credit.value ? command.approvalId.value : null,
      lines: checked.lines.map((line) => ({
        ...line,
        deliveryLineId: mutation.effectId(`delivery_line:${line.invoiceLineId}`),
      })),
    }),
    expectedVersion: null,
  })
  after(outcome.kind)
}

function onRequested(approval: Approval): void {
  asking.value = false
  command.approvalRequested(approval)
}

async function passwordConfirmed(): Promise<void> {
  after((await command.passwordConfirmed()).kind)
}

function after(kind: string): void {
  if (kind === 'synced') {
    const count = submitted.length
    emit(
      'done',
      `Remise enregistrée sur le serveur : ${count} ligne${count > 1 ? 's' : ''} remise${count > 1 ? 's' : ''} au patient, sortie${count > 1 ? 's' : ''} du stock.`,
    )
    return
  }
  const byLine: Record<string, string> = {}
  const others: string[] = []
  for (const [key, text] of Object.entries(command.fields.value)) {
    const line = deliveryLineFieldFromServer(key, submitted)
    if (line === null) {
      if (key !== 'credit_delivery_approval_id') others.push(text)
    } else byLine[line] = text
  }
  errors.value = byLine
  if (others.length > 0) general.value = others.join(' ')
  if (command.stale.value) emit('stale')
}
</script>

<template>
  <form
    class="space-y-4 rounded-xl border border-line bg-surface shadow-card p-4"
    novalidate
    @submit.prevent="submit"
  >
    <h3 class="text-lg font-semibold">Remettre au patient</h3>
    <p class="max-w-prose text-base">
      Indiquez la quantité remise maintenant pour chaque ligne ; laissez vide une ligne qui sera
      remise plus tard. Les articles en stock sont pris sur la réserve de cette vente.
    </p>

    <StateMessage v-if="credit" state="conflict" title="Remise avant règlement complet">
      Reste à encaisser : {{ formatMoney(props.view.amount_due ?? 0) }}. Une remise avant le
      règlement complet exige l’autorisation de la gérante pour ces quantités exactes.
    </StateMessage>

    <ul class="space-y-3">
      <li
        v-for="line in lines"
        :key="line.invoice_line_id"
        class="space-y-2 rounded-lg border border-line p-3"
      >
        <p class="font-semibold break-words">{{ line.description }}</p>
        <p class="text-sm text-muted">
          Facturé {{ quantityLabel(line.quantity) }} · déjà remis
          {{ quantityLabel(line.delivered) }} · reste {{ quantityLabel(line.remaining) }}
          <template v-if="line.stock_mode === 'STOCKED'">
            · réservé {{ quantityLabel(line.reserved_open) }}</template
          >
        </p>
        <StateMessage
          v-if="line.stock_mode === 'STOCKED' && !line.deliverable"
          state="error"
          title="Aucune réserve ouverte"
        >
          Cet article n’est plus réservé pour cette vente : il ne peut pas être remis depuis cet
          écran (voir la gérante).
        </StateMessage>
        <TextField
          :model-value="quantities[line.invoice_line_id] ?? ''"
          :label="`Quantité remise — ${line.description}`"
          :control-id="fieldId(line.invoice_line_id)"
          inputmode="decimal"
          :hint="`Au plus ${quantityLabel(deliveryCeiling(line).canonical())}.`"
          :error="errors[line.invoice_line_id] ?? null"
          :disabled="saving || (line.stock_mode === 'STOCKED' && !line.deliverable)"
          @update:model-value="setQuantity(line.invoice_line_id, $event)"
        />
      </li>
    </ul>

    <StateMessage v-if="general" state="error" title="Remise non enregistrée">{{
      general
    }}</StateMessage>
    <StateMessage
      v-else-if="message"
      :state="approvalNeeded ? 'conflict' : 'error'"
      title="Remise non enregistrée"
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
        variant="primary"
        :pending="saving"
        pending-label="Enregistrement…"
        >{{ requestOnly ? 'Demander l’autorisation de remise' : 'Confirmer la remise' }}</AppButton
      >
      <AppButton variant="ghost" :disabled="saving" @click="emit('cancel')">Annuler</AppButton>
    </div>

    <ApprovalRequestDialog
      :open="asking"
      :subject="subject"
      :summary="`Remise au patient de la facture ${props.invoice.number ?? ''} avant son règlement complet (reste à encaisser : ${formatMoney(props.view.amount_due ?? 0)}).`"
      @requested="onRequested"
      @cancel="asking = false"
    />
    <PasswordConfirmDialog
      :open="command.askingPassword.value"
      action="Une remise au patient"
      @confirmed="passwordConfirmed"
      @cancel="command.askingPassword.value = false"
    />
  </form>
</template>
