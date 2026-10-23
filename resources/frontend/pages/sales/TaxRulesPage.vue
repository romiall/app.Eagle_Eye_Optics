<script setup lang="ts">
/**
 * Règles fiscales (B01), gérante (`catalog.manage`), EN LIGNE. Aucune taxe n'est préconfigurée ni
 * inventée : la gérante saisit code, libellé et taux, et valide la règle ; une règle n'est appliquée aux
 * documents que si elle est active ET validée. Changer le taux d'une règle validée retire la validation
 * (à redonner dans la même demande). Écriture sensible : le serveur exige un mot de passe confirmé
 * récemment (423) ; l'écran le demande (`PasswordConfirmDialog`) puis reprend la même commande avec la
 * même clé, la saisie restant affichée.
 */
import { computed, ref } from 'vue'

import { clinicTimeZone, formatInstantFr } from '@/app/clinicTime'
import { listTaxRules } from '@/app/services/billing'
import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'
import { useUnsavedChangesGuard } from '@/app/unsavedChanges'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import CheckboxField from '@/components/form/CheckboxField.vue'
import DecimalField from '@/components/form/DecimalField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import {
  blankTaxRuleForm,
  type TaxRuleErrors,
  type TaxRuleField,
  taxRuleFieldFromServer,
  type TaxRuleForm,
  taxRuleFormFromRule,
  taxRuleState,
  validateTaxRuleForm,
} from '@/domain/billing/catalog'
import type { TaxRule, TaxRuleResponse } from '@/domain/contracts/generated'
import { useServerRead } from '@/pages/today/useServerRead'

import { salesFailureView } from './failureView'
import { rateLabel } from './format'
import SalesReadMessage from './SalesReadMessage.vue'

const timeZone = clinicTimeZone()
const read = useServerRead<TaxRule[]>(() => listTaxRules(), {
  isEmpty: (rules) => rules.length === 0,
})
const mutation = useMutation()

/** Règle en cours de modification, « nouvelle », ou aucune. */
const editing = ref<TaxRule | 'new' | null>(null)
const form = ref<TaxRuleForm>(blankTaxRuleForm())
const startedFrom = ref(JSON.stringify(blankTaxRuleForm()))
const errors = ref<TaxRuleErrors>({})
const general = ref<string | null>(null)
const summaryBox = ref<{ focus: () => Promise<void> } | null>(null)
const rateField = ref<{ commit: () => boolean } | null>(null)
const notice = ref<string | null>(null)
const askingPassword = ref(false)
const needsReload = ref(false)

const saving = computed(() => mutation.state.value === 'saving')
const failure = computed(() =>
  mutation.state.value === 'conflict' || mutation.state.value === 'reauthentication-required'
    ? null
    : salesFailureView(mutation.state.value),
)
const current = computed(() => (editing.value === 'new' ? null : editing.value))
const dirty = computed(
  () => editing.value !== null && JSON.stringify(form.value) !== startedFrom.value,
)
useUnsavedChangesGuard(dirty)

const FIELD_PREFIX = 'regle'
function fieldId(field: TaxRuleField): string {
  return `${FIELD_PREFIX}-${field}`
}

const LABELS: Readonly<Record<TaxRuleField, string>> = {
  code: 'Code',
  label: 'Libellé',
  ratePercent: 'Taux',
  active: 'Active',
  validate: 'Validation',
  legalNote: 'Mention légale',
}

const summary = computed<FieldErrorItem[]>(() => {
  const items: FieldErrorItem[] = []
  for (const [field, message] of Object.entries(errors.value) as [TaxRuleField, string][]) {
    items.push({ fieldId: fieldId(field), message: `${LABELS[field]} : ${message}` })
  }
  if (general.value !== null) items.push({ fieldId: fieldId('code'), message: general.value })
  return items
})

function start(target: TaxRule | 'new'): void {
  if (saving.value) return
  mutation.reset()
  editing.value = target
  form.value = target === 'new' ? blankTaxRuleForm() : taxRuleFormFromRule(target)
  startedFrom.value = JSON.stringify(form.value)
  errors.value = {}
  general.value = null
  notice.value = null
  needsReload.value = false
}

function stop(): void {
  editing.value = null
  errors.value = {}
  general.value = null
}

function set<K extends keyof TaxRuleForm>(key: K, value: TaxRuleForm[K]): void {
  form.value = { ...form.value, [key]: value }
  if (errors.value[key] !== undefined) {
    const rest = { ...errors.value }
    delete rest[key]
    errors.value = rest
  }
}

async function afterOutcome(result: MutationOutcome): Promise<void> {
  switch (result.kind) {
    case 'synced': {
      const rule = (result.result as TaxRuleResponse).data
      notice.value = `Règle ${rule.code} enregistrée sur le serveur (${taxRuleState(rule).toLowerCase()}).`
      editing.value = null
      await read.reload()
      return
    }
    case 'reauthentication-required':
      askingPassword.value = true
      return
    case 'conflict':
      needsReload.value = true
      return
    case 'refused': {
      const byField: TaxRuleErrors = {}
      const others: string[] = []
      for (const [key, messages] of Object.entries(result.error.fields)) {
        const message = messages[0]
        if (message === undefined) continue
        const field = taxRuleFieldFromServer(key)
        if (field === null) others.push(message)
        else byField[field] = message
      }
      errors.value = byField
      general.value =
        others.length > 0
          ? others.join(' ')
          : Object.keys(byField).length === 0
            ? result.error.message
            : null
      await summaryBox.value?.focus()
      return
    }
    case 'session-expired':
      void revalidateSession().catch(() => undefined)
      return
    default:
      return
  }
}

async function submit(): Promise<void> {
  if (saving.value || editing.value === null) return
  errors.value = {}
  general.value = null
  const committed = rateField.value?.commit() ?? true
  const checked = validateTaxRuleForm(form.value, current.value)
  if (!committed || !checked.ok) {
    errors.value = checked.ok ? {} : checked.errors
    if (!committed && checked.ok) general.value = 'Le taux saisi est refusé : corrigez-le.'
    await summaryBox.value?.focus()
    return
  }
  const rule = current.value
  await afterOutcome(
    await mutation.submit(
      rule === null
        ? {
            name: 'CreateTaxRule',
            route: { method: 'POST', path: '/tax-rules' },
            payload: checked.payload,
          }
        : {
            name: 'UpdateTaxRule',
            route: {
              method: 'PATCH',
              path: `/tax-rules/${encodeURIComponent(rule.id)}`,
              expectedVersion: 'entity',
            },
            payload: checked.payload,
            expectedVersion: rule.version,
          },
    ),
  )
}

async function retry(): Promise<void> {
  if (saving.value) return
  await afterOutcome(await mutation.retry())
}

/** Mot de passe confirmé : la même commande repart avec la même clé (aucun doublon). */
async function onPasswordConfirmed(): Promise<void> {
  askingPassword.value = false
  await retry()
}

/** Version actuelle relue après un conflit : la saisie est conservée, la version attendue mise à jour. */
async function reloadRule(): Promise<void> {
  const rule = current.value
  await read.reload()
  if (rule === null) return
  const fresh = (read.data.value ?? []).find((candidate) => candidate.id === rule.id)
  if (fresh !== undefined) {
    editing.value = fresh
    needsReload.value = false
  }
}
</script>

<template>
  <section aria-labelledby="titre-taxes" class="max-w-3xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <h1 id="titre-taxes" class="text-2xl font-semibold tracking-tight">Règles fiscales</h1>
      <AppButton v-if="editing === null" variant="primary" @click="start('new')"
        >Nouvelle règle</AppButton
      >
    </div>
    <p class="text-base text-muted">
      Aucune taxe n’est préconfigurée par l’application : le régime réel est à fixer avec la gérante
      et le comptable. Tant qu’aucune règle n’est active et validée, les factures portent « aucune
      taxe configurée ».
    </p>

    <StateMessage v-if="notice" state="success" :title="notice" />

    <form
      v-if="editing !== null"
      novalidate
      aria-labelledby="titre-regle"
      class="space-y-4 rounded-xl border border-primary bg-surface p-4"
      @submit.prevent="submit"
    >
      <h2 id="titre-regle" class="text-lg font-semibold">
        {{ current === null ? 'Nouvelle règle fiscale' : `Modifier la règle ${current.code}` }}
      </h2>
      <ErrorSummary ref="summaryBox" :errors="summary" />

      <StateMessage v-if="needsReload" state="conflict" title="La règle a changé entre-temps">
        Votre saisie est conservée. Relisez la règle : votre saisie partira sur la version actuelle.
        <template #action>
          <AppButton variant="primary" @click="reloadRule">Relire la règle</AppButton>
        </template>
      </StateMessage>

      <StateMessage v-if="failure" :state="failure.state" :title="failure.title">
        {{ mutation.failureMessage.value }}
        <template v-if="failure.retry" #action>
          <AppButton variant="secondary" :pending="saving" @click="retry"
            >Réessayer l’enregistrement</AppButton
          >
        </template>
      </StateMessage>
      <StateMessage
        v-if="mutation.state.value === 'reauthentication-required' && !askingPassword"
        state="error"
        title="Confirmation du mot de passe demandée"
      >
        Rien n’a été enregistré : confirmez votre mot de passe pour reprendre l’enregistrement.
        <template #action>
          <AppButton variant="secondary" @click="askingPassword = true"
            >Confirmer mon mot de passe</AppButton
          >
        </template>
      </StateMessage>

      <div class="grid gap-4 sm:grid-cols-2">
        <TextField
          :model-value="form.code"
          label="Code"
          :control-id="fieldId('code')"
          :maxlength="40"
          :error="errors.code ?? null"
          required
          @update:model-value="set('code', $event)"
        />
        <TextField
          :model-value="form.label"
          label="Libellé"
          :control-id="fieldId('label')"
          :maxlength="180"
          :error="errors.label ?? null"
          required
          @update:model-value="set('label', $event)"
        />
        <DecimalField
          ref="rateField"
          :model-value="form.ratePercent"
          kind="rate"
          field="rate_percent"
          label="Taux, en %"
          hint="Taux fourni par la gérante ou le comptable ; aucun taux n’est proposé."
          :control-id="fieldId('ratePercent')"
          :error="errors.ratePercent ?? null"
          @update:model-value="set('ratePercent', $event)"
        />
      </div>
      <TextAreaField
        :model-value="form.legalNote"
        label="Mention légale (facultative)"
        :maxlength="4000"
        :control-id="fieldId('legalNote')"
        :error="errors.legalNote ?? null"
        @update:model-value="set('legalNote', $event)"
      />
      <CheckboxField
        :model-value="form.validate"
        label="Je valide ce taux (enregistré à mon nom, à l’instant de l’enregistrement)"
        :control-id="fieldId('validate')"
        :error="errors.validate ?? null"
        @update:model-value="set('validate', $event)"
      />
      <CheckboxField
        :model-value="form.active"
        label="Règle active (appliquée aux documents si elle est validée)"
        :control-id="fieldId('active')"
        :error="errors.active ?? null"
        @update:model-value="set('active', $event)"
      />
      <p class="text-sm text-muted">
        Opération sensible : votre mot de passe peut vous être demandé avant l’enregistrement.
      </p>
      <div class="flex flex-wrap gap-3">
        <AppButton type="submit" :pending="saving" pending-label="Enregistrement en cours…"
          >Enregistrer la règle</AppButton
        >
        <AppButton variant="ghost" :disabled="saving" @click="stop">Annuler</AppButton>
      </div>
    </form>

    <SalesReadMessage
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      subject="les règles fiscales"
      :error="read.error.value"
      @retry="read.reload()"
    />
    <StateMessage
      v-else-if="read.state.value === 'empty'"
      state="empty"
      title="Aucune règle fiscale"
    >
      Aucune règle n’a été saisie : les factures portent « aucune taxe configurée ».
    </StateMessage>
    <ul v-else-if="read.data.value" class="space-y-3">
      <li
        v-for="rule in read.data.value"
        :key="rule.id"
        class="space-y-1 rounded-xl border border-line bg-surface shadow-card p-3"
      >
        <p class="flex flex-wrap items-baseline justify-between gap-x-3">
          <span class="font-semibold break-words">{{ rule.label }} ({{ rule.code }})</span>
          <span class="tabular-nums">{{ rateLabel(rule.rate_percent) }}</span>
        </p>
        <p class="text-sm text-muted">
          {{ taxRuleState(rule) }}
          <template v-if="rule.validated_at">
            · validée le {{ formatInstantFr(rule.validated_at) }}</template
          >
        </p>
        <AppButton
          v-if="editing === null"
          variant="secondary"
          :accessible-name="`Modifier la règle ${rule.code}`"
          @click="start(rule)"
          >Modifier</AppButton
        >
      </li>
    </ul>

    <FreshnessNote
      v-if="read.freshness.value && read.state.value === 'ready'"
      :freshness="read.freshness.value"
      :time-zone="timeZone"
    />

    <PasswordConfirmDialog
      :open="askingPassword"
      action="L’enregistrement d’une règle fiscale"
      @confirmed="onPasswordConfirmed"
      @cancel="askingPassword = false"
    />
  </section>
</template>
