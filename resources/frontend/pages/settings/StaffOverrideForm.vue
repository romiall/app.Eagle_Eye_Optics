<script setup lang="ts">
/**
 * Dérogation nominative de droit (F05, fichier 09) : AUTORISER un droit en plus du profil, le RETIRER
 * (le refus l'emporte sur le profil), ou SUPPRIMER une dérogation existante ; motif obligatoire, échéance
 * facultative (fin d'un jour civil du cabinet, jamais minuit UTC). « Gérer utilisateurs et droits » ne se
 * délègue pas et la gérante ne se déroge pas à elle-même : l'écran ne le propose pas (le serveur le refuse).
 */
import { computed, ref } from 'vue'

import { clinicTimeZone } from '@/app/clinicTime'
import { setPermissionOverride } from '@/app/services/staff'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import DateField from '@/components/form/DateField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import {
  endOfCivilDayUtc,
  OVERRIDABLE_PERMISSIONS,
  OVERRIDE_EFFECT_LABELS,
  type OverrideEffect,
  permissionLabel,
} from '@/domain/access/staff'
import type { StaffMember } from '@/domain/contracts/generated'

import { useAdminWrite } from './useAdminWrite'

const props = defineProps<{ member: StaffMember }>()
const emit = defineEmits<{ updated: [member: StaffMember] }>()

const IDS = {
  permission: 'derogation-droit',
  effect: 'derogation-effet',
  reason: 'derogation-motif',
  expires: 'derogation-echeance',
} as const

const writer = useAdminWrite()
const permission = ref<string | null>(null)
const effect = ref<string | null>(null)
const reason = ref('')
const expiresOn = ref<string | null>(null)
const submitted = ref(false)
const saved = ref<string | null>(null)

const permissionOptions = OVERRIDABLE_PERMISSIONS.map((entry) => ({
  value: entry.code,
  label: `${entry.label} (${entry.code})`,
}))
const effectOptions = (Object.keys(OVERRIDE_EFFECT_LABELS) as OverrideEffect[]).map((value) => ({
  value,
  label: OVERRIDE_EFFECT_LABELS[value],
}))

/** Le compte détient-il ce droit aujourd'hui (profils et dérogations compris) ? */
const holds = computed(() =>
  permission.value === null
    ? null
    : (props.member.permissions as readonly string[]).includes(permission.value),
)

const localErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  if (permission.value === null) errors[IDS.permission] = 'Choisissez le droit concerné.'
  if (effect.value === null) errors[IDS.effect] = 'Choisissez l’effet de la dérogation.'
  if (reason.value.trim().length < 3)
    errors[IDS.reason] = 'Indiquez le motif (3 caractères au moins).'
  return errors
})

const errors = computed<Record<string, string>>(() => {
  const server: Record<string, string> = {}
  for (const [field, id] of [
    ['permission_code', IDS.permission],
    ['effect', IDS.effect],
    ['reason', IDS.reason],
    ['expires_at', IDS.expires],
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
  saved.value = null
  if (Object.keys(localErrors.value).length > 0) return
  const code = permission.value as string
  const chosen = effect.value as OverrideEffect
  const expiresAt =
    chosen === 'CLEAR' || expiresOn.value === null
      ? null
      : endOfCivilDayUtc(expiresOn.value, clinicTimeZone())
  await writer.run(
    () =>
      setPermissionOverride(props.member.id, {
        expected_version: props.member.version,
        permission_code: code,
        effect: chosen,
        reason: reason.value.trim(),
        expires_at: expiresAt,
      }),
    (member) => {
      saved.value = `${OVERRIDE_EFFECT_LABELS[chosen]} : ${permissionLabel(code)}.`
      permission.value = null
      effect.value = null
      reason.value = ''
      expiresOn.value = null
      submitted.value = false
      emit('updated', member)
    },
  )
}
</script>

<template>
  <SectionCard
    title="Dérogation de droit"
    title-id="titre-derogation"
    icon="shield"
    description="Une dérogation s’ajoute aux profils du compte, pour une personne nommée, avec un motif conservé dans le journal. Elle s’applique dès la requête suivante."
  >
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
        title="Dérogation non enregistrée"
      >
        {{ writer.message.value }}
      </StateMessage>
      <StateMessage v-if="saved" state="success" title="Dérogation enregistrée sur le serveur">
        {{ saved }}
      </StateMessage>

      <div class="space-y-2">
        <SelectField
          v-model="permission"
          label="Droit"
          placeholder="Choisir un droit…"
          :options="permissionOptions"
          :control-id="IDS.permission"
          :error="errors[IDS.permission] ?? null"
          required
        />
        <p v-if="holds !== null" class="flex items-center gap-2 text-sm text-muted">
          <AppIcon :name="holds ? 'check-circle' : 'x-circle'" :size="16" />
          <span>Aujourd’hui, ce compte {{ holds ? 'détient' : 'ne détient pas' }} ce droit.</span>
        </p>
      </div>
      <SelectField
        v-model="effect"
        label="Effet"
        placeholder="Choisir l’effet…"
        :options="effectOptions"
        :control-id="IDS.effect"
        :error="errors[IDS.effect] ?? null"
        required
      />
      <DateField
        v-if="effect !== 'CLEAR'"
        v-model="expiresOn"
        label="Valable jusqu’au (facultatif)"
        hint="Jour inclus, à l’heure du cabinet. Vide : sans échéance."
        :control-id="IDS.expires"
        :error="errors[IDS.expires] ?? null"
      />
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
          variant="primary"
          :pending="writer.state.value === 'saving'"
          pending-label="Enregistrement en cours…"
          >Enregistrer la dérogation</AppButton
        >
      </div>
    </form>

    <PasswordConfirmDialog
      :open="writer.state.value === 'reauthentication'"
      action="Une dérogation de droit"
      @confirmed="writer.resume()"
      @cancel="writer.cancel()"
    />
  </SectionCard>
</template>
