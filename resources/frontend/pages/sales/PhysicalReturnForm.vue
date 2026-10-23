<script setup lang="ts">
/**
 * Retour physique d'un article remis (I02), EN LIGNE, droit `stock.adjust` (gérante par défaut), mot de passe
 * confirmé récemment. Au plus la quantité remise non encore retournée. L'état constaté et la destination sont
 * obligatoires : seul un article intact et vendable, accepté, retourne dans le stock vendable (même position, même
 * lot) ; un article abîmé ou à contrôler est tracé sans entrer en stock.
 *
 * Le retour ne crée AUCUN avoir et ne rend aucun argent : le remboursement éventuel est une décision distincte
 * (« Émettre un avoir », puis « Rembourser »). Un avoir déjà émis sur cette facture peut être relié au retour.
 */
import { computed, ref } from 'vue'

import { knownAccountId } from '@/app/session'
import StateMessage from '@/components/feedback/StateMessage.vue'
import CheckboxField from '@/components/form/CheckboxField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import type { Invoice, PhysicalReturnsConditionCode } from '@/domain/contracts/generated'
import {
  buildReturnPayload,
  CONDITION_LABELS,
  CONDITIONS,
  type DeliveredLine,
  quantityLabel,
  REASON_MAX_LENGTH,
  type ReturnErrors,
  validateReturn,
} from '@/domain/inventory/delivery'
import { currentInstant } from '@/domain/patients/visits'
import { useStockCommand } from '@/pages/stock/useStockCommand'

const props = defineProps<{
  invoice: Invoice
  deliveryId: string
  line: DeliveredLine
  integerOnly: boolean
}>()

const emit = defineEmits<{ done: [message: string]; stale: []; cancel: [] }>()

const ids = {
  quantity: `retour-quantite-${props.line.id}`,
  condition: `retour-etat-${props.line.id}`,
  restock: `retour-stock-${props.line.id}`,
  reason: `retour-motif-${props.line.id}`,
  creditNote: `retour-avoir-${props.line.id}`,
}

const command = useStockCommand('Ce retour exige une autorisation de la gérante.')
const { mutation, message } = command
const quantity = ref(quantityLabel(props.line.returnable))
const condition = ref<PhysicalReturnsConditionCode | null>(null)
const restock = ref(false)
const reason = ref('')
const creditNoteId = ref<string | null>(null)
const errors = ref<ReturnErrors>({})
/** Instant du retour, fixé à la première tentative : une reprise renvoie la même charge. */
let occurredAt: string | null = null

const saving = computed(() => mutation.state.value === 'saving')
const stocked = computed(() => props.line.stock_position_id !== null)
const conditionOptions = CONDITIONS.map((code) => ({ value: code, label: CONDITION_LABELS[code] }))
const creditNoteOptions = computed(() =>
  props.invoice.credit_notes
    .filter((note) => note.status === 'ISSUED')
    .map((note) => ({ value: note.id, label: `Avoir ${note.number ?? ''}` })),
)

function setCondition(value: string | null): void {
  condition.value = (CONDITIONS as readonly string[]).includes(value ?? '')
    ? (value as PhysicalReturnsConditionCode)
    : null
  if (condition.value !== 'SELLABLE') restock.value = false
}

async function submit(): Promise<void> {
  if (saving.value) return
  const checked = validateReturn(
    props.line,
    {
      quantity: quantity.value,
      condition: condition.value,
      restock: restock.value,
      reason: reason.value,
      creditNoteId: creditNoteId.value,
    },
    props.integerOnly,
  )
  if (!checked.ok) {
    errors.value = checked.errors
    const first = (['quantity', 'condition', 'restock', 'reason'] as const).find(
      (field) => checked.errors[field] !== undefined,
    )
    if (first !== undefined) document.getElementById(ids[first])?.focus()
    return
  }
  errors.value = {}
  const account = knownAccountId()
  if (account === null) return
  occurredAt ??= currentInstant()
  const outcome = await command.send({
    name: 'RegisterPhysicalReturn',
    path: { id: props.deliveryId },
    payload: buildReturnPayload({
      returnId: mutation.effectId('physical_return_id'),
      deliveryLineId: props.line.id,
      itemId: props.line.item_id,
      quantity: checked.quantity,
      condition: checked.condition,
      restock: checked.restock,
      approvedBy: account,
      occurredAt,
      reason: checked.reason,
      creditNoteId: creditNoteId.value,
      movementId: mutation.effectId('stock_movement_id'),
    }),
    expectedVersion: null,
  })
  after(outcome.kind)
}

async function passwordConfirmed(): Promise<void> {
  after((await command.passwordConfirmed()).kind)
}

function after(kind: string): void {
  if (kind === 'synced') {
    emit(
      'done',
      restock.value
        ? `Retour de ${quantity.value} enregistré sur le serveur et remis en stock vendable. Aucun avoir n’a été émis.`
        : `Retour de ${quantity.value} enregistré sur le serveur, sans remise en stock vendable. Aucun avoir n’a été émis.`,
    )
    return
  }
  const fields = command.fields.value
  const next: ReturnErrors = {}
  if (fields['quantity'] !== undefined) next.quantity = fields['quantity']
  if (fields['condition_code'] !== undefined) next.condition = fields['condition_code']
  if (fields['accepted_for_restock'] !== undefined) next.restock = fields['accepted_for_restock']
  if (fields['reason'] !== undefined) next.reason = fields['reason']
  errors.value = next
  if (command.stale.value) emit('stale')
}
</script>

<template>
  <form
    class="space-y-4 rounded-xl border border-line bg-surface shadow-card p-4"
    novalidate
    @submit.prevent="submit"
  >
    <h3 class="text-lg font-semibold">Retour de « {{ props.line.description }} »</h3>
    <p class="max-w-prose text-base">
      Remis {{ quantityLabel(props.line.quantity) }}, déjà retourné
      {{ quantityLabel(props.line.returned) }}. Un retour ne crée pas d’avoir et ne rend pas
      d’argent : le remboursement éventuel se fait ensuite par un avoir.
    </p>

    <TextField
      v-model="quantity"
      label="Quantité retournée"
      :control-id="ids.quantity"
      inputmode="decimal"
      :hint="`Au plus ${quantityLabel(props.line.returnable)}.`"
      :error="errors.quantity ?? null"
      :disabled="saving"
      required
    />
    <SelectField
      :model-value="condition"
      label="État constaté"
      :options="conditionOptions"
      placeholder="Choisir l’état"
      :control-id="ids.condition"
      :error="errors.condition ?? null"
      :disabled="saving"
      required
      @update:model-value="setCondition"
    />
    <CheckboxField
      v-if="stocked"
      v-model="restock"
      label="Remettre en stock vendable"
      hint="Seulement pour un article intact et vendable : il retourne dans son emplacement et son lot d’origine."
      :control-id="ids.restock"
      :error="errors.restock ?? null"
      :disabled="saving || condition !== 'SELLABLE'"
    />
    <p v-else class="text-sm text-muted">
      Article sans gestion de stock : le retour est tracé, rien n’entre en stock.
    </p>
    <SelectField
      v-if="creditNoteOptions.length > 0"
      v-model="creditNoteId"
      label="Avoir déjà émis à relier (facultatif)"
      :options="creditNoteOptions"
      placeholder="Aucun avoir relié"
      :control-id="ids.creditNote"
      :disabled="saving"
    />
    <TextAreaField
      v-model="reason"
      :control-id="ids.reason"
      label="Motif (obligatoire)"
      hint="Ce que le patient rapporte et pourquoi (par exemple : monture ne convenant pas, flacon intact non ouvert)."
      :maxlength="REASON_MAX_LENGTH"
      :error="errors.reason ?? null"
      :disabled="saving"
    />

    <StateMessage v-if="message" state="error" title="Retour non enregistré">{{
      message
    }}</StateMessage>

    <div class="flex flex-wrap gap-3">
      <AppButton type="submit" variant="primary" :pending="saving" pending-label="Enregistrement…"
        >Enregistrer le retour</AppButton
      >
      <AppButton variant="ghost" :disabled="saving" @click="emit('cancel')">Annuler</AppButton>
    </div>

    <PasswordConfirmDialog
      :open="command.askingPassword.value"
      action="Un retour physique"
      @confirmed="passwordConfirmed"
      @cancel="command.askingPassword.value = false"
    />
  </form>
</template>
