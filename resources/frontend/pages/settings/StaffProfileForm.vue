<script setup lang="ts">
/**
 * Modifier un compte (F05) : nom affiché, courriel, profils, activation. Désactiver un compte ou lui retirer
 * un profil exige un motif (le serveur le refuse sinon). La gérante ne modifie ni ses propres profils ni sa
 * propre activation (le serveur le refuse : l'écran ne le propose pas et le dit). Version attendue du compte :
 * une modification faite ailleurs entre-temps est refusée (409), la saisie reste à l'écran.
 */
import { computed, ref, watch } from 'vue'

import { updateStaffMember, type UpdateStaffInput } from '@/app/services/staff'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import CheckboxField from '@/components/form/CheckboxField.vue'
import CheckboxGroup from '@/components/form/CheckboxGroup.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { changeNeedsReason, ROLE_LABELS, STAFF_ROLES } from '@/domain/access/staff'
import type { StaffMember } from '@/domain/contracts/generated'

import { useAdminWrite } from './useAdminWrite'

const props = defineProps<{ member: StaffMember; self: boolean }>()
const emit = defineEmits<{ updated: [member: StaffMember] }>()

const IDS = {
  name: 'profil-nom',
  email: 'profil-courriel',
  roles: 'profil-profils',
  active: 'profil-actif',
  reason: 'profil-motif',
} as const

const writer = useAdminWrite()
const displayName = ref('')
const email = ref('')
const roles = ref<string[]>([])
const active = ref(true)
const reason = ref('')
const submitted = ref(false)
const saved = ref(false)

function fill(member: StaffMember): void {
  displayName.value = member.display_name
  email.value = member.email ?? ''
  roles.value = [...member.roles]
  active.value = member.active
  reason.value = ''
  submitted.value = false
}

watch(
  () => props.member,
  (member) => fill(member),
  { immediate: true },
)

const roleOptions = computed(() =>
  STAFF_ROLES.map((role) => ({ value: role, label: ROLE_LABELS[role], disabled: props.self })),
)

const needsReason = computed(() =>
  changeNeedsReason(
    { active: props.member.active, roles: props.member.roles },
    { active: active.value, roles: roles.value },
  ),
)

const changes = computed<UpdateStaffInput>(() => {
  const input: UpdateStaffInput = { expected_version: props.member.version }
  const name = displayName.value.trim()
  if (name !== props.member.display_name) input.display_name = name
  const mail = email.value.trim() === '' ? null : email.value.trim()
  if (mail !== props.member.email) input.email = mail
  const sameRoles =
    roles.value.length === props.member.roles.length &&
    roles.value.every((role) => (props.member.roles as readonly string[]).includes(role))
  if (!props.self && !sameRoles) input.roles = [...roles.value]
  if (!props.self && active.value !== props.member.active) input.active = active.value
  if (reason.value.trim() !== '') input.reason = reason.value.trim()
  return input
})

const hasChanges = computed(() =>
  Object.keys(changes.value).some((key) => key !== 'expected_version' && key !== 'reason'),
)

const localErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  const name = displayName.value.trim()
  if (name.length < 2) errors[IDS.name] = 'Saisissez le nom affiché (2 caractères au moins).'
  const mail = email.value.trim()
  if (mail !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) {
    errors[IDS.email] = 'L’adresse électronique n’est pas valide.'
  }
  if (roles.value.length === 0) errors[IDS.roles] = 'Un compte garde au moins un profil.'
  if (needsReason.value && reason.value.trim().length < 3) {
    errors[IDS.reason] = 'Indiquez le motif de la désactivation ou du retrait de profil.'
  }
  return errors
})

const errors = computed<Record<string, string>>(() => {
  const server: Record<string, string> = {}
  for (const [field, id] of [
    ['display_name', IDS.name],
    ['email', IDS.email],
    ['roles', IDS.roles],
    ['active', IDS.active],
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

async function submit(): Promise<void> {
  submitted.value = true
  saved.value = false
  if (Object.keys(localErrors.value).length > 0 || !hasChanges.value) return
  const input = changes.value
  await writer.run(
    () => updateStaffMember(props.member.id, input),
    (member) => {
      saved.value = true
      emit('updated', member)
    },
  )
}
</script>

<template>
  <section aria-labelledby="titre-profil" class="space-y-4">
    <h2 id="titre-profil" class="text-xl font-semibold">Compte et profils</h2>

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
        title="Modification non enregistrée"
      >
        {{ writer.message.value }}
      </StateMessage>
      <StateMessage
        v-if="saved && writer.state.value === 'saved'"
        state="success"
        title="Compte enregistré sur le serveur"
      />

      <TextField
        v-model="displayName"
        label="Nom affiché"
        :control-id="IDS.name"
        :maxlength="180"
        :error="errors[IDS.name] ?? null"
        required
        autocomplete="off"
      />
      <TextField
        v-model="email"
        label="Adresse électronique (facultative)"
        type="email"
        :control-id="IDS.email"
        :maxlength="254"
        :error="errors[IDS.email] ?? null"
        autocomplete="off"
      />
      <CheckboxGroup
        v-model="roles"
        legend="Profils"
        :hint="
          props.self
            ? 'Vous ne modifiez pas vos propres profils : une autre gérante le fait.'
            : 'Retirer un profil exige un motif.'
        "
        :options="roleOptions"
        :control-id="IDS.roles"
        :error="errors[IDS.roles] ?? null"
        :disabled="props.self"
      />
      <CheckboxField
        v-model="active"
        label="Compte actif"
        :hint="
          props.self
            ? 'Vous ne désactivez pas votre propre compte.'
            : 'Un compte désactivé ne peut plus se connecter ; ses sessions sont fermées. Il n’est jamais supprimé.'
        "
        :control-id="IDS.active"
        :error="errors[IDS.active] ?? null"
        :disabled="props.self"
      />
      <TextAreaField
        v-model="reason"
        :label="needsReason ? 'Motif (obligatoire)' : 'Motif (facultatif)'"
        hint="Conservé dans le journal d’audit."
        :control-id="IDS.reason"
        :rows="2"
        :maxlength="1000"
        :error="errors[IDS.reason] ?? null"
        :required="needsReason"
      />

      <p
        v-if="submitted && !hasChanges && Object.keys(localErrors).length === 0"
        class="text-muted"
      >
        Aucune modification à enregistrer.
      </p>

      <AppButton
        type="submit"
        variant="primary"
        :pending="writer.state.value === 'saving'"
        pending-label="Enregistrement en cours…"
        >Enregistrer le compte</AppButton
      >
    </form>

    <PasswordConfirmDialog
      :open="writer.state.value === 'reauthentication'"
      action="La modification d’un compte"
      @confirmed="writer.resume()"
      @cancel="writer.cancel()"
    />
  </section>
</template>
