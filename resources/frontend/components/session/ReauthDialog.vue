<script setup lang="ts">
/**
 * Reconnexion sur place quand la session a expiré pendant une saisie (F10 : formulaire préservé lors
 * d'une session expirée). La saisie reste à l'écran derrière la boîte ; seul le même compte peut se
 * reconnecter ici : une saisie n'est jamais renvoyée au nom d'un autre profil. Après la reconnexion,
 * l'écran reprend l'envoi avec la même clé d'idempotence (`useMutation().retry()`).
 */
import { ref, watch } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { signIn } from '@/app/session'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import TextField from '@/components/form/TextField.vue'

const props = defineProps<{
  open: boolean
  /** Identifiant du compte dont la session a expiré. */
  login: string
  displayName: string
}>()

const emit = defineEmits<{ reauthenticated: []; cancel: [] }>()

const password = ref('')
const error = ref<string | null>(null)
const pending = ref(false)

watch(
  () => props.open,
  (open) => {
    if (!open) {
      password.value = ''
      error.value = null
    }
  },
)

async function confirm(): Promise<void> {
  if (password.value === '') {
    error.value = 'Saisissez votre mot de passe.'
    return
  }
  pending.value = true
  error.value = null
  try {
    await signIn({ login: props.login, password: password.value })
    password.value = ''
    emit('reauthenticated')
  } catch (failure) {
    if (failure instanceof NetworkUnavailable) {
      error.value = 'Serveur injoignable : réessayez quand la connexion revient.'
    } else if (failure instanceof ApiError) {
      error.value = failure.fields['login']?.[0] ?? failure.message
      password.value = ''
    } else {
      throw failure
    }
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <ConfirmDialog
    :open="props.open"
    title="Session expirée"
    confirm-label="Se reconnecter"
    pending-label="Reconnexion en cours…"
    :pending="pending"
    @confirm="confirm"
    @cancel="emit('cancel')"
  >
    <div class="space-y-3">
      <p>
        Vos saisies sont conservées. Reconnectez-vous avec le compte
        <strong>{{ props.displayName }}</strong> pour les enregistrer.
      </p>
      <form novalidate @submit.prevent="confirm">
        <TextField
          v-model="password"
          label="Mot de passe"
          type="password"
          autocomplete="current-password"
          :error="error"
          required
        />
      </form>
    </div>
  </ConfirmDialog>
</template>
