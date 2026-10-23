<script setup lang="ts">
/**
 * Réinitialiser le mot de passe d'un compte (F05) : par la gérante, pour un compte qui n'est pas gérante,
 * avec un motif ; les sessions du compte sont fermées. Le nouveau mot de passe se transmet EN PERSONNE et la
 * personne le change à sa connexion. Le mot de passe d'une gérante ne se réinitialise pas ici (procédure de
 * secours sur le serveur) ; la gérante change le sien depuis son propre compte. Rien n'est conservé.
 */
import { computed, ref } from 'vue'

import { resetStaffPassword } from '@/app/services/staff'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { generatePassword, passwordError } from '@/domain/access/staff'
import type { StaffMember } from '@/domain/contracts/generated'

import { useAdminWrite } from './useAdminWrite'

const props = defineProps<{ member: StaffMember }>()
const emit = defineEmits<{ reset: [] }>()

const IDS = {
  password: 'reinit-mot-de-passe',
  confirmation: 'reinit-confirmation',
  reason: 'reinit-motif',
} as const

const writer = useAdminWrite()
const password = ref('')
const confirmation = ref('')
const reason = ref('')
const submitted = ref(false)
/** Mot de passe généré, montré une fois après l'enregistrement, puis effacé. */
const shown = ref<string | null>(null)
const generated = ref(false)
const done = ref(false)

const localErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  const problem = passwordError(password.value, props.member.login)
  if (problem !== null) errors[IDS.password] = problem
  if (confirmation.value !== password.value) {
    errors[IDS.confirmation] = 'La confirmation est différente du mot de passe.'
  }
  if (reason.value.trim().length < 3)
    errors[IDS.reason] = 'Indiquez le motif (3 caractères au moins).'
  return errors
})

const errors = computed<Record<string, string>>(() => {
  const server: Record<string, string> = {}
  for (const [field, id] of [
    ['password', IDS.password],
    ['password_confirmation', IDS.confirmation],
    ['reason', IDS.reason],
  ] as const) {
    const message = writer.fieldError(field)
    if (message !== null) server[id] = message
  }
  return submitted.value ? { ...server, ...localErrors.value } : server
})

const summary = computed<FieldErrorItem[]>(() =>
  Object.entries(errors.value).map(([fieldId, message]) => ({ fieldId, message })),
)

function generate(): void {
  const value = generatePassword()
  password.value = value
  confirmation.value = value
  generated.value = true
}

function typed(field: 'password' | 'confirmation', value: string): void {
  if (field === 'password') password.value = value
  else confirmation.value = value
  generated.value = false
}

async function submit(): Promise<void> {
  submitted.value = true
  done.value = false
  if (Object.keys(localErrors.value).length > 0) return
  const value = password.value
  const show = generated.value
  await writer.run(
    () =>
      resetStaffPassword(props.member.id, {
        expected_version: props.member.version,
        password: value,
        password_confirmation: value,
        reason: reason.value.trim(),
      }),
    () => {
      done.value = true
      shown.value = show ? value : null
      password.value = ''
      confirmation.value = ''
      reason.value = ''
      submitted.value = false
      generated.value = false
      emit('reset')
    },
  )
}

function forget(): void {
  shown.value = null
}
</script>

<template>
  <SectionCard title="Réinitialiser le mot de passe" title-id="titre-reinit" icon="key">
    <div class="space-y-5">
      <StateMessage v-if="done" state="success" title="Mot de passe réinitialisé sur le serveur">
        Les sessions ouvertes de ce compte ont été fermées. Transmettez le nouveau mot de passe en
        personne ; la personne le changera à sa connexion.
      </StateMessage>
      <div v-if="shown" class="space-y-3 rounded-xl border border-accent bg-accent-soft p-4">
        <p class="font-medium">Nouveau mot de passe (affiché une seule fois) :</p>
        <p
          class="rounded-lg border border-line bg-surface px-3 py-2 font-mono text-lg tracking-wide break-all"
        >
          {{ shown }}
        </p>
        <AppButton variant="secondary" @click="forget">J’ai noté, masquer</AppButton>
      </div>

      <form class="space-y-5" novalidate @submit.prevent="submit">
        <ErrorSummary :errors="summary" />
        <StateMessage
          v-if="writer.message.value && Object.keys(writer.fieldErrors.value).length === 0"
          :state="
            writer.state.value === 'conflict'
              ? 'conflict'
              : writer.state.value === 'uncertain'
                ? 'offline'
                : 'error'
          "
          title="Mot de passe non réinitialisé"
        >
          {{ writer.message.value }}
        </StateMessage>

        <div class="max-w-md space-y-5">
          <TextField
            :model-value="password"
            label="Nouveau mot de passe"
            hint="12 caractères au moins, avec des lettres et des chiffres, différent de l’identifiant."
            type="password"
            autocomplete="new-password"
            :control-id="IDS.password"
            :error="errors[IDS.password] ?? null"
            required
            @update:model-value="typed('password', $event)"
          />
          <TextField
            :model-value="confirmation"
            label="Confirmation du mot de passe"
            type="password"
            autocomplete="new-password"
            :control-id="IDS.confirmation"
            :error="errors[IDS.confirmation] ?? null"
            required
            @update:model-value="typed('confirmation', $event)"
          />
          <AppButton variant="secondary" @click="generate">
            <AppIcon name="refresh" :size="18" />
            Générer un mot de passe
          </AppButton>
        </div>
        <TextAreaField
          v-model="reason"
          label="Motif (obligatoire)"
          :control-id="IDS.reason"
          :rows="2"
          :maxlength="1000"
          :error="errors[IDS.reason] ?? null"
          required
        />

        <div class="form-actions">
          <AppButton
            type="submit"
            variant="danger"
            :pending="writer.state.value === 'saving'"
            pending-label="Réinitialisation en cours…"
            >Réinitialiser le mot de passe</AppButton
          >
        </div>
      </form>
    </div>

    <PasswordConfirmDialog
      :open="writer.state.value === 'reauthentication'"
      action="La réinitialisation d’un mot de passe"
      @confirmed="writer.resume()"
      @cancel="writer.cancel()"
    />
  </SectionCard>
</template>
