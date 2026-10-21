<script setup lang="ts">
/**
 * Reconnexion sur place quand la session a expiré pendant une saisie (F10 : formulaire préservé lors
 * d'une session expirée). La saisie reste à l'écran derrière la boîte. Seul le dernier compte connu
 * de cet écran peut se reconnecter ici : une saisie n'est jamais renvoyée au nom d'un autre profil
 * (l'identifiant du compte obtenu est comparé à celui de la saisie). Après la reconnexion, l'écran
 * reprend l'envoi avec la même clé d'idempotence (`useMutation().retry()`), qui relit la session et
 * refuse l'envoi si le compte a changé.
 */
import { computed, ref, watch } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { session, signIn, signOut } from '@/app/session'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import TextField from '@/components/form/TextField.vue'

const props = defineProps<{ open: boolean }>()

const emit = defineEmits<{ reauthenticated: []; cancel: [] }>()

const account = computed(() => session.lastAccount)
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
  const expected = account.value
  if (expected === null) {
    error.value = 'Aucun compte à reconnecter : ouvrez la page de connexion.'
    return
  }
  if (password.value === '') {
    error.value = 'Saisissez votre mot de passe.'
    return
  }
  pending.value = true
  error.value = null
  try {
    const me = await signIn({ login: expected.login, password: password.value })
    password.value = ''
    if (me.id !== expected.id) {
      // Défense en profondeur : l'identifiant obtenu n'est pas celui de la saisie.
      await signOut().catch(() => undefined)
      error.value = 'Ce n’est pas le compte qui a commencé cette saisie : rien n’a été envoyé.'
      return
    }
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
      <p v-if="account">
        Vos saisies sont conservées. Reconnectez-vous avec le compte
        <strong>{{ account.display_name }}</strong> pour les enregistrer.
      </p>
      <p v-else>Aucun compte connu sur cet écran : ouvrez la page de connexion.</p>
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
