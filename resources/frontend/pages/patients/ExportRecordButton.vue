<script setup lang="ts">
/**
 * « Exporter le dossier » d'un patient (X02, PATIENT_RECORD, droit clinical.export, module « settings ») : la
 * demande est confirmée par le mot de passe (423), puis l'archive est produite par le serveur et se
 * télécharge depuis Paramètres → Exports pendant 48 h. Rien n'est produit dans le navigateur.
 */
import { ref } from 'vue'
import { RouterLink } from 'vue-router'

import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'

const props = defineProps<{ patientId: string }>()

const mutation = useMutation()
const askingPassword = ref(false)
const done = ref(false)
const problem = ref<string | null>(null)

async function afterOutcome(outcome: MutationOutcome): Promise<void> {
  switch (outcome.kind) {
    case 'synced':
      done.value = true
      return
    case 'reauthentication-required':
      askingPassword.value = true
      return
    case 'refused':
      problem.value = Object.values(outcome.error.fields).flat()[0] ?? outcome.error.message
      return
    case 'session-expired':
      void revalidateSession().catch(() => undefined)
      return
    default:
      problem.value = mutation.failureMessage.value
      return
  }
}

async function request(): Promise<void> {
  problem.value = null
  done.value = false
  mutation.reset()
  await afterOutcome(
    await mutation.submit({
      name: 'RequestExport',
      route: { method: 'POST', path: '/exports', expectedVersion: 'omitted' },
      payload: { export_type: 'PATIENT_RECORD', patient_id: props.patientId },
    }),
  )
}

async function onPasswordConfirmed(): Promise<void> {
  askingPassword.value = false
  await afterOutcome(await mutation.retry())
}
</script>

<template>
  <div class="space-y-2">
    <AppButton
      variant="secondary"
      :pending="mutation.state.value === 'saving'"
      pending-label="Demande en cours…"
      @click="request"
      >Exporter le dossier</AppButton
    >
    <p v-if="done" role="status" class="max-w-prose text-sm">
      Export demandé : le serveur le prépare.
      <RouterLink
        :to="{ name: 'parametres-exports' }"
        class="font-medium text-primary underline underline-offset-4"
        >Le télécharger depuis Exports</RouterLink
      >.
    </p>
    <p v-if="problem" role="alert" class="max-w-prose text-sm font-medium text-danger">
      {{ problem }}
    </p>
    <PasswordConfirmDialog
      :open="askingPassword"
      action="L’export du dossier"
      @confirmed="onPasswordConfirmed"
      @cancel="askingPassword = false"
    />
  </div>
</template>
