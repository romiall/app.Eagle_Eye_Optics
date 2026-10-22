<script setup lang="ts">
/**
 * Confirmation du mot de passe pour une opération sensible (fichier 09) : le serveur a répondu 423
 * `REAUTHENTICATION_REQUIRED` (règle fiscale, stock initial). Différente de `ReauthDialog` (session
 * expirée, reconnexion du même compte) : ici la session est ouverte, le mot de passe du compte connecté
 * est confirmé (`POST /auth/reauthenticate`), puis l'écran reprend la même commande avec la même clé.
 * La saisie de l'écran reste derrière la boîte ; le mot de passe est effacé à la fermeture.
 */
import { nextTick, ref, watch } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { confirmPassword } from '@/app/services/reauth'
import { session } from '@/app/session'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import TextField from '@/components/form/TextField.vue'

const props = withDefaults(defineProps<{ open: boolean; action?: string }>(), {
  action: 'Cette opération',
})

const emit = defineEmits<{ confirmed: []; cancel: [] }>()

const FIELD_ID = 'confirmation-mot-de-passe'

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

async function focusField(): Promise<void> {
  await nextTick()
  document.getElementById(FIELD_ID)?.focus()
}

async function confirm(): Promise<void> {
  if (pending.value) return
  if (password.value === '') {
    error.value = 'Saisissez votre mot de passe.'
    await focusField()
    return
  }
  pending.value = true
  error.value = null
  try {
    await confirmPassword(password.value)
    password.value = ''
    emit('confirmed')
  } catch (failure) {
    if (failure instanceof NetworkUnavailable) {
      error.value = 'Serveur injoignable : réessayez quand la connexion revient.'
    } else if (failure instanceof ApiError) {
      error.value = failure.fields['password']?.[0] ?? failure.message
      if (failure.status === 422) password.value = ''
    } else {
      throw failure
    }
    await focusField()
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <ConfirmDialog
    :open="props.open"
    title="Confirmer votre mot de passe"
    confirm-label="Confirmer et reprendre"
    pending-label="Vérification en cours…"
    :pending="pending"
    :initial-focus="`#${FIELD_ID}`"
    @confirm="confirm"
    @cancel="emit('cancel')"
  >
    <div class="space-y-3">
      <p>
        {{ props.action }} est une opération sensible : confirmez le mot de passe du compte
        <strong>{{ session.account?.display_name ?? 'connecté' }}</strong>. Vos saisies sont
        conservées et l’enregistrement reprendra ensuite, sans doublon.
      </p>
      <form novalidate @submit.prevent="confirm">
        <TextField
          v-model="password"
          :control-id="FIELD_ID"
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
