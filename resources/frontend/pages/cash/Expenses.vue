<script setup lang="ts">
/**
 * Dépenses et mouvements de caisse hors vente d'une session (L1D05 ; fichier 03 : « Les sorties de caisse réelles
 * […] sont enregistrées et autorisées ») : liste des mouvements de la session, et sur une session OUVERTE, saisie
 * d'une dépense catégorisée, d'un dépôt, d'un retrait ou d'une correction de l'ouverture. Jamais un paiement : ni
 * facture, ni reçu. La caissière demande l'autorisation exacte de la gérante ; la gérante l'exécute directement.
 * Une session clôturée est figée : la liste reste lisible, aucun ajout.
 */
import { computed, ref, shallowRef, watch } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { formatInstantFr } from '@/app/clinicTime'
import { getCashSessionMovements, listExpenseCategories } from '@/app/services/billing'
import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import ApprovalRequestDialog from '@/components/approvals/ApprovalRequestDialog.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import MoneyField from '@/components/form/MoneyField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { CASH_MOVEMENT_LABELS, cashMovementSubject } from '@/domain/approvals/approvals'
import {
  type CashMovementKind,
  checkMovement,
  type CorrectionDirection,
  MOVEMENT_KINDS,
  MOVEMENT_REASON_MAX,
  MOVEMENT_REFERENCE_MAX,
} from '@/domain/cash/movements'
import type { Approval, CashSessionMovements, ExpenseCategory } from '@/domain/contracts/generated'
import { formatMoney } from '@/domain/values/money'

import { useCashCommand } from './useCashCommand'

const props = defineProps<{
  sessionId: string
  /** Session ouverte : la saisie est proposée. */
  open: boolean
  /** Espèces attendues du tiroir à l'affichage. */
  available: number
}>()
const emit = defineEmits<{ changed: [] }>()

const data = shallowRef<CashSessionMovements | null>(null)
const categories = shallowRef<ExpenseCategory[]>([])
const readProblem = ref<string | null>(null)
const loading = ref(false)

const kind = ref<string | null>(null)
const amount = ref<number | null>(null)
const direction = ref<string | null>(null)
const categoryId = ref<string | null>(null)
const reason = ref('')
const reference = ref('')
const errors = ref<Record<string, string>>({})
const asking = ref(false)
const done = ref<string | null>(null)

const command = useCashCommand()
const saving = computed(() => command.mutation.state.value === 'saving')

async function load(): Promise<void> {
  loading.value = true
  readProblem.value = null
  try {
    data.value = (await getCashSessionMovements(props.sessionId)).data
    if (props.open) categories.value = (await listExpenseCategories()).data
  } catch (error) {
    readProblem.value =
      error instanceof NetworkUnavailable
        ? 'Le serveur n’a pas répondu : les mouvements ne sont pas affichés (rien n’est conclu).'
        : error instanceof ApiError
          ? error.message
          : 'Une erreur inattendue est survenue.'
  } finally {
    loading.value = false
  }
}

watch(
  () => props.sessionId,
  () => void load(),
  { immediate: true },
)

const kindOptions = MOVEMENT_KINDS.map((value) => ({ value, label: CASH_MOVEMENT_LABELS[value] }))
const directionOptions = [
  { value: 'IN', label: 'Ajouter des espèces (ouverture sous-estimée)' },
  { value: 'OUT', label: 'Retirer des espèces (ouverture surestimée)' },
]
const categoryOptions = computed(() =>
  categories.value
    .filter((category) => category.active)
    .map((category) => ({ value: category.id, label: category.name })),
)

const checked = computed(() =>
  checkMovement(
    {
      kind: kind.value as CashMovementKind | null,
      amount: amount.value,
      direction: direction.value as CorrectionDirection | null,
      categoryId: categoryId.value,
      reason: reason.value,
      reference: reference.value,
    },
    props.available,
  ),
)

/** Sans `approval.decide`, un mouvement se DEMANDE d'abord (la gérante l'autorise). */
const requestOnly = computed(
  () => !canOnScreen('approval.decide') && command.requestedApproval.value === null,
)
const canRequest = computed(() => canOnScreen('approval.request') && moduleOpenOnScreen('settings'))
const subject = computed(() =>
  checked.value.ok
    ? cashMovementSubject(
        props.sessionId,
        checked.value.movement.kind,
        checked.value.movement.signedAmount,
        checked.value.movement.categoryId,
      )
    : null,
)
const summary = computed(() =>
  checked.value.ok
    ? `${CASH_MOVEMENT_LABELS[checked.value.movement.kind]} de ${formatMoney(Math.abs(checked.value.movement.signedAmount))} : ${checked.value.movement.reason}`
    : '',
)

async function submit(): Promise<void> {
  if (saving.value) return
  done.value = null
  const result = checked.value
  if (!result.ok) {
    errors.value = Object.fromEntries(
      Object.entries(result.errors).filter(
        (entry): entry is [string, string] => typeof entry[1] === 'string',
      ),
    )
    return
  }
  errors.value = {}
  if (requestOnly.value) {
    if (canRequest.value) asking.value = true
    else
      command.message.value =
        'Ce compte ne peut pas demander d’autorisation : la gérante enregistre ce mouvement.'
    return
  }
  const outcome = await command.send({
    name: 'RecordCashMovement',
    route: {
      method: 'POST',
      path: `/cash-sessions/${encodeURIComponent(props.sessionId)}/movements`,
      expectedVersion: 'none',
    },
    payload: {
      movement_id: command.mutation.effectId('movement_id'),
      cash_session_id: props.sessionId,
      movement_kind: result.movement.kind,
      signed_amount: result.movement.signedAmount,
      expense_category_id: result.movement.categoryId,
      reason: result.movement.reason,
      reference: result.movement.reference,
      approval_id: command.approvalId.value,
    },
  })
  if (outcome.kind === 'synced') {
    done.value = `${CASH_MOVEMENT_LABELS[result.movement.kind]} enregistré(e) : ${formatMoney(Math.abs(result.movement.signedAmount))}.`
    kind.value = null
    amount.value = null
    direction.value = null
    categoryId.value = null
    reason.value = ''
    reference.value = ''
    command.reset()
    await load()
    emit('changed')
  } else if (command.stale.value) {
    await load()
    emit('changed')
  }
}

function onRequested(approval: Approval): void {
  asking.value = false
  command.approvalRequested(approval)
}

async function passwordConfirmed(): Promise<void> {
  const outcome = await command.passwordConfirmed()
  if (outcome.kind === 'synced') {
    await load()
    emit('changed')
  }
}

function fieldError(name: string, server: string): string | null {
  return errors.value[name] ?? command.fields.value[server] ?? null
}
</script>

<template>
  <section aria-labelledby="titre-mouvements" class="space-y-4">
    <h2 id="titre-mouvements" class="text-lg font-semibold">Dépenses et mouvements de caisse</h2>
    <p class="max-w-prose text-sm text-muted">
      Sorties et entrées d’espèces hors vente : elles entrent dans les espèces attendues du tiroir.
      Ce ne sont jamais des paiements de patients (ni facture, ni reçu).
    </p>

    <StateMessage v-if="loading && !data" state="loading" title="Lecture des mouvements…" />
    <StateMessage v-if="readProblem" state="offline" title="Mouvements non lus">
      {{ readProblem }}
    </StateMessage>

    <template v-if="data">
      <p v-if="data.movements.length === 0" class="text-sm text-muted">
        Aucun mouvement hors vente dans cette session.
      </p>
      <div v-else class="overflow-x-auto rounded-xl border border-line bg-surface shadow-card">
        <table class="w-full min-w-[40rem] text-left text-sm">
          <caption class="sr-only">
            Mouvements hors vente de la session
          </caption>
          <thead class="bg-primary-soft">
            <tr>
              <th scope="col" class="px-3 py-2 font-semibold">Heure</th>
              <th scope="col" class="px-3 py-2 font-semibold">Nature</th>
              <th scope="col" class="px-3 py-2 font-semibold">Motif</th>
              <th scope="col" class="px-3 py-2 font-semibold">Autorisation</th>
              <th scope="col" class="px-3 py-2 text-right font-semibold">Montant</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="movement in data.movements"
              :key="movement.id"
              class="border-t border-line align-top"
            >
              <td class="px-3 py-2 whitespace-nowrap">
                {{ formatInstantFr(movement.occurred_at) }}
              </td>
              <td class="px-3 py-2">
                {{ CASH_MOVEMENT_LABELS[movement.kind] }}
                <span v-if="movement.expense_category" class="block text-muted">{{
                  movement.expense_category.name
                }}</span>
              </td>
              <td class="px-3 py-2 break-words">
                {{ movement.reason }}
                <span v-if="movement.reference" class="block text-muted"
                  >Justificatif : {{ movement.reference }}</span
                >
              </td>
              <td class="px-3 py-2">
                {{
                  movement.approval_mode === 'DIRECT'
                    ? 'Gérante (directe)'
                    : 'Accordée par la gérante'
                }}
                <span v-if="movement.created_by_name" class="block text-muted">{{
                  movement.created_by_name
                }}</span>
              </td>
              <td
                :class="[
                  'px-3 py-2 text-right font-semibold whitespace-nowrap tabular-nums',
                  movement.signed_amount < 0 ? 'text-danger' : 'text-accent',
                ]"
              >
                {{ movement.signed_amount < 0 ? '−' : '+' }}
                {{ formatMoney(Math.abs(movement.signed_amount)) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <StateMessage v-if="!props.open" state="empty" title="Session clôturée : mouvements figés">
        Une session clôturée ne reçoit plus de mouvement. Une erreur se corrige dans une session
        ouverte, avec son motif.
      </StateMessage>

      <form
        v-else
        novalidate
        aria-labelledby="titre-nouveau-mouvement"
        class="space-y-4 rounded-xl border border-line bg-surface p-4 shadow-card"
        @submit.prevent="submit"
      >
        <h3 id="titre-nouveau-mouvement" class="font-semibold">Enregistrer un mouvement</h3>
        <StateMessage v-if="done" state="success" :title="done" />
        <StateMessage v-if="command.notice.value" state="empty" title="Autorisation demandée">
          {{ command.notice.value }}
        </StateMessage>
        <p v-if="command.message.value" role="alert" class="text-sm font-medium text-danger">
          {{ command.message.value }}
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
          <SelectField
            v-model="kind"
            label="Nature"
            :options="kindOptions"
            :error="fieldError('kind', 'movement_kind')"
            required
            control-id="mouvement-nature"
          />
          <MoneyField
            v-model="amount"
            label="Montant (FCFA)"
            :error="fieldError('amount', 'signed_amount')"
            required
            control-id="mouvement-montant"
          />
          <SelectField
            v-if="kind === 'EXPENSE'"
            v-model="categoryId"
            label="Catégorie de la dépense"
            :options="categoryOptions"
            :hint="
              categoryOptions.length === 0
                ? 'Aucune catégorie active : la gérante les crée dans « Catégories de dépenses ».'
                : undefined
            "
            :error="fieldError('category', 'expense_category_id')"
            required
            control-id="mouvement-categorie"
          />
          <SelectField
            v-if="kind === 'OPENING_CORRECTION'"
            v-model="direction"
            label="Sens de la correction"
            :options="directionOptions"
            :error="fieldError('direction', 'signed_amount')"
            required
            control-id="mouvement-sens"
          />
        </div>
        <TextAreaField
          v-model="reason"
          label="Motif"
          :error="fieldError('reason', 'reason')"
          :maxlength="MOVEMENT_REASON_MAX"
          :rows="2"
          required
          control-id="mouvement-motif"
        />
        <TextField
          v-model="reference"
          label="Référence du justificatif (facultatif)"
          hint="Numéro du bon, de la facture du fournisseur ou du bordereau de versement."
          :error="fieldError('reference', 'reference')"
          :maxlength="MOVEMENT_REFERENCE_MAX"
          control-id="mouvement-reference"
        />
        <p class="text-sm text-muted">
          Espèces attendues dans le tiroir : {{ formatMoney(props.available) }}. Une sortie ne peut
          pas les dépasser.
        </p>
        <AppButton type="submit" :pending="saving" pending-label="Enregistrement…">
          {{ requestOnly ? 'Demander l’autorisation' : 'Enregistrer le mouvement' }}
        </AppButton>
      </form>
    </template>

    <ApprovalRequestDialog
      :open="asking"
      :subject="subject"
      :summary="summary"
      @requested="onRequested"
      @cancel="asking = false"
    />
    <PasswordConfirmDialog
      :open="command.askingPassword.value"
      action="Un mouvement de caisse"
      @confirmed="passwordConfirmed"
      @cancel="command.askingPassword.value = false"
    />
  </section>
</template>
