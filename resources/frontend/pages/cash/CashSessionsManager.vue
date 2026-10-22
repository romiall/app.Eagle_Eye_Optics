<script setup lang="ts">
/**
 * Sessions de caisse de tous les postes, pour la gérante (`cash.manage`, B05) : ouvertes d'abord, puis
 * clôturées, avec le nom actuel de la caissière et la caisse. Pour chaque session : relevé en PDF ; pour
 * une session OUVERTE d'une autre caissière : clôture par la gérante (compté, écart dit sans jugement,
 * motif obligatoire si l'écart n'est pas nul), avec l'attendu recalculé par le serveur sous verrou (409 si
 * un encaissement est survenu depuis l'affichage : le compté est conservé). EN LIGNE seulement.
 */
import { computed, ref, shallowRef } from 'vue'

import { clinicTimeZone, formatInstantFr } from '@/app/clinicTime'
import { listCashSessions } from '@/app/services/billing'
import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { knownAccountId, revalidateSession } from '@/app/session'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import MoneyField from '@/components/form/MoneyField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import {
  buildCloseSessionPayload,
  cashVariance,
  validateClosing,
  VARIANCE_REASON_MAX_LENGTH,
  varianceDescription,
} from '@/domain/billing/cash'
import { accountLabel } from '@/domain/billing/cashReport'
import type { CashSessionEntry, CashSessionPage } from '@/domain/contracts/generated'
import { currentInstant } from '@/domain/patients/visits'
import { formatMoney } from '@/domain/values/money'
import { salesFailureView } from '@/pages/sales/failureView'
import SalesReadMessage from '@/pages/sales/SalesReadMessage.vue'
import { useServerRead } from '@/pages/today/useServerRead'

import SessionReportButton from './SessionReportButton.vue'

const FIELD_IDS = { counted: 'gerante-caisse-compte', reason: 'gerante-caisse-motif' } as const

const timeZone = clinicTimeZone()
const page = ref(1)
const read = useServerRead<CashSessionPage>(() => listCashSessions({ page: page.value }), {
  isEmpty: (result) => result.data.length === 0,
})
const result = computed(() => read.data.value)
const me = knownAccountId()

function goToPage(next: number): void {
  page.value = next
  void read.reload()
}

function cashier(session: CashSessionEntry): string {
  return accountLabel(session.cashier_name, session.cashier_id)
}

function registerOf(session: CashSessionEntry): string {
  return session.register_name ?? session.register_code ?? 'Caisse'
}

function signed(amount: number): string {
  return amount > 0 ? `+${formatMoney(amount)}` : formatMoney(amount)
}

// --- Clôture d'une session d'une autre caissière ----------------------------------------------------

const selected = shallowRef<CashSessionEntry | null>(null)
const counted = ref<number | null>(null)
const reason = ref('')
const countedField = ref<{ commit: () => boolean } | null>(null)
const errors = ref<FieldErrorItem[]>([])
const summaryBox = ref<{ focus: () => Promise<void> } | null>(null)
const confirming = ref(false)
const expectedChanged = ref<{ before: number; after: number } | null>(null)
const closedMessage = ref<string | null>(null)
const mutation = useMutation()

const closing = computed(() => mutation.state.value === 'saving')
const failure = computed(() =>
  mutation.state.value === 'conflict' ? null : salesFailureView(mutation.state.value),
)
const variance = computed(() =>
  counted.value === null || selected.value === null
    ? null
    : cashVariance(counted.value, selected.value.theoretical_cash),
)

function startClosing(session: CashSessionEntry): void {
  if (closing.value) return
  if (selected.value?.id !== session.id) {
    mutation.reset()
    counted.value = null
    reason.value = ''
  }
  selected.value = session
  errors.value = []
  expectedChanged.value = null
  closedMessage.value = null
}

function cancelClosing(): void {
  if (closing.value) return
  selected.value = null
  counted.value = null
  reason.value = ''
  errors.value = []
  expectedChanged.value = null
}

async function requestClose(): Promise<void> {
  const session = selected.value
  if (closing.value || session === null) return
  errors.value = []
  const committed = countedField.value?.commit() ?? true
  const checked = validateClosing({
    counted: committed ? counted.value : null,
    expected: session.theoretical_cash,
    reason: reason.value,
  })
  if (!checked.ok) {
    errors.value = [
      ...(checked.errors.counted === undefined
        ? []
        : [{ fieldId: FIELD_IDS.counted, message: checked.errors.counted }]),
      ...(checked.errors.reason === undefined
        ? []
        : [{ fieldId: FIELD_IDS.reason, message: checked.errors.reason }]),
    ]
    await summaryBox.value?.focus()
    return
  }
  confirming.value = true
}

async function afterClose(outcome: MutationOutcome): Promise<void> {
  confirming.value = false
  switch (outcome.kind) {
    case 'synced': {
      const name = selected.value === null ? '' : ` de ${cashier(selected.value)}`
      closedMessage.value = `Session${name} clôturée. La session clôturée est figée.`
      selected.value = null
      counted.value = null
      reason.value = ''
      await read.reload()
      return
    }
    case 'conflict': {
      const after = outcome.error.details['expected_amount']
      const before = selected.value?.theoretical_cash ?? 0
      expectedChanged.value = { before, after: typeof after === 'number' ? after : before }
      await read.reload()
      const fresh = read.data.value?.data.find((session) => session.id === selected.value?.id)
      if (fresh !== undefined) selected.value = fresh
      return
    }
    case 'refused':
      errors.value = [{ fieldId: FIELD_IDS.counted, message: outcome.error.message }]
      await summaryBox.value?.focus()
      if (outcome.error.code === 'STATE_TRANSITION_REFUSED') await read.reload()
      return
    case 'session-expired':
    case 'reauthentication-required':
      void revalidateSession().catch(() => undefined)
      return
    default:
      return
  }
}

async function confirmClose(): Promise<void> {
  const session = selected.value
  if (closing.value || session === null || counted.value === null) return
  const checked = validateClosing({
    counted: counted.value,
    expected: session.theoretical_cash,
    reason: reason.value,
  })
  if (!checked.ok) {
    confirming.value = false
    return
  }
  expectedChanged.value = null
  await afterClose(
    await mutation.submit({
      name: 'CloseCashSession',
      path: { id: session.id },
      payload: buildCloseSessionPayload({
        cashSessionId: session.id,
        closedAt: currentInstant(),
        counted: counted.value,
        expected: session.theoretical_cash,
        variance: checked.variance,
        reason: checked.reason,
      }),
    }),
  )
}

async function retryClose(): Promise<void> {
  if (closing.value) return
  await afterClose(await mutation.retry())
}

/** Références de fonction : le formulaire est rendu dans une boucle (une seule session à la fois). */
function bindSummary(element: unknown): void {
  summaryBox.value = element as { focus: () => Promise<void> } | null
}

function bindCounted(element: unknown): void {
  countedField.value = element as { commit: () => boolean } | null
}

function fieldError(fieldId: string): string | null {
  return errors.value.find((item) => item.fieldId === fieldId)?.message ?? null
}
</script>

<template>
  <section aria-labelledby="titre-sessions-cabinet" class="space-y-4">
    <h2 id="titre-sessions-cabinet" class="text-lg font-semibold">
      Sessions de toutes les caisses
    </h2>
    <p class="text-base text-muted">
      Sessions ouvertes d’abord, puis clôturées. La gérante peut clôturer la session d’une autre
      caissière et lire le relevé de toute session.
    </p>

    <StateMessage v-if="closedMessage" state="success" :title="closedMessage" />

    <SalesReadMessage
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      subject="les sessions de caisse"
      :error="read.error.value"
      @retry="read.reload()"
    />
    <StateMessage v-else-if="read.state.value === 'empty'" state="empty" title="Aucune session">
      Aucune session de caisse n’a encore été ouverte dans ce cabinet.
    </StateMessage>

    <template v-else-if="result">
      <ul class="space-y-3">
        <li
          v-for="session in result.data"
          :key="session.id"
          class="space-y-2 rounded-lg border-2 border-line bg-surface p-3"
        >
          <p class="flex flex-wrap items-baseline justify-between gap-x-3">
            <span class="font-semibold break-words">{{ cashier(session) }}</span>
            <span class="text-base">
              <span aria-hidden="true">{{ session.closed_at === null ? '● ' : '✓ ' }}</span
              >{{ session.closed_at === null ? 'Ouverte' : 'Clôturée' }}
            </span>
          </p>
          <p class="text-sm text-muted break-words">
            {{ registerOf(session) }} · ouverte le {{ formatInstantFr(session.opened_at) }}
            <template v-if="session.closed_at">
              · clôturée le {{ formatInstantFr(session.closed_at) }}
              <template v-if="session.closed_by_name"> par {{ session.closed_by_name }}</template>
            </template>
          </p>
          <p class="text-base">
            <template v-if="session.closed_at === null">
              Espèces attendues :
              <strong class="tabular-nums">{{ formatMoney(session.theoretical_cash) }}</strong>
            </template>
            <template v-else>
              Attendu {{ formatMoney(session.expected_amount ?? 0) }} · compté
              {{ formatMoney(session.counted_amount ?? 0) }} · écart
              <strong class="tabular-nums">{{ signed(session.variance ?? 0) }}</strong>
            </template>
          </p>
          <div class="flex flex-wrap gap-3">
            <SessionReportButton
              :session-id="session.id"
              label="Relevé (PDF)"
              :context="`de ${cashier(session)}`"
            />
            <AppButton
              v-if="session.closed_at === null && session.cashier_id !== me"
              variant="secondary"
              :disabled="closing"
              :accessible-name="`Clôturer la session de ${cashier(session)}`"
              @click="startClosing(session)"
              >Clôturer cette session</AppButton
            >
          </div>

          <form
            v-if="selected?.id === session.id"
            novalidate
            :aria-label="`Clôture de la session de ${cashier(session)}`"
            class="space-y-4 rounded-lg border-2 border-primary p-3"
            @submit.prevent="requestClose"
          >
            <ErrorSummary :ref="bindSummary" :errors="errors" />
            <StateMessage
              v-if="expectedChanged"
              state="conflict"
              title="Les espèces attendues ont changé depuis l’affichage"
            >
              Attendu affiché : {{ formatMoney(expectedChanged.before) }} ; attendu recalculé par le
              serveur : {{ formatMoney(expectedChanged.after) }}. Le compté saisi est conservé :
              recomptez puis clôturez de nouveau.
            </StateMessage>
            <StateMessage v-if="failure" :state="failure.state" :title="failure.title">
              {{ mutation.failureMessage.value }}
              <template v-if="failure.retry" #action>
                <AppButton variant="secondary" :pending="closing" @click="retryClose"
                  >Réessayer la clôture</AppButton
                >
              </template>
            </StateMessage>
            <p class="text-base">
              Espèces attendues :
              <strong class="tabular-nums">{{ formatMoney(session.theoretical_cash) }}</strong>
            </p>
            <MoneyField
              :ref="bindCounted"
              v-model="counted"
              label="Montant compté dans le tiroir"
              field="counted_amount"
              :control-id="FIELD_IDS.counted"
              :error="fieldError(FIELD_IDS.counted)"
              required
            />
            <p v-if="variance !== null" role="status" aria-live="polite" class="text-base">
              Écart : <strong class="tabular-nums">{{ signed(variance) }}</strong> —
              {{ varianceDescription(variance) }}
            </p>
            <TextAreaField
              v-model="reason"
              label="Motif de l’écart"
              hint="Obligatoire si le compté diffère de l’attendu."
              :maxlength="VARIANCE_REASON_MAX_LENGTH"
              :control-id="FIELD_IDS.reason"
              :error="fieldError(FIELD_IDS.reason)"
              :required="variance !== null && variance !== 0"
            />
            <div class="flex flex-wrap gap-3">
              <AppButton type="submit" :pending="closing" pending-label="Clôture en cours…"
                >Clôturer la session</AppButton
              >
              <AppButton variant="ghost" :disabled="closing" @click="cancelClosing"
                >Annuler</AppButton
              >
            </div>
          </form>
        </li>
      </ul>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        label="Pagination des sessions de caisse"
        unit="sessions"
        @page="goToPage"
      />
      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>

    <ConfirmDialog
      :open="confirming"
      title="Clôturer cette session de caisse ?"
      confirm-label="Clôturer la session"
      pending-label="Clôture en cours…"
      :pending="closing"
      tone="danger"
      @confirm="confirmClose"
      @cancel="confirming = false"
    >
      <p v-if="selected">
        Caissière : <strong>{{ cashier(selected) }}</strong> ; compté :
        <strong>{{ formatMoney(counted ?? 0) }}</strong> ; attendu :
        <strong>{{ formatMoney(selected.theoretical_cash) }}</strong> ; écart :
        <strong>{{ signed(variance ?? 0) }}</strong
        >.
      </p>
      <p class="mt-2">
        La session clôturée ne pourra plus être modifiée et aucun encaissement en espèces n’y sera
        plus admis.
      </p>
    </ConfirmDialog>
  </section>
</template>
