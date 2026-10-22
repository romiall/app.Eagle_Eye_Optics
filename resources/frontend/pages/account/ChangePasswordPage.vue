<script setup lang="ts">
/**
 * Changer son propre mot de passe (F05 : `POST /auth/password`) : tout compte connecté, avec son mot de
 * passe actuel. C'est ici que la personne remplace le mot de passe initial transmis par la gérante. La
 * session courante reste ouverte, les autres sessions du compte sont fermées par le serveur. Les mots de
 * passe ne sont ni conservés ni réaffichés ; mêmes règles que le serveur (12 caractères, lettres et chiffres,
 * différent de l'identifiant), le serveur tranche. Ne dépend d'aucun module.
 */
import { computed, nextTick, ref } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { changeOwnPassword } from '@/app/services/staff'
import { revalidateSession, session } from '@/app/session'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import TextField from '@/components/form/TextField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { passwordError } from '@/domain/access/staff'

const IDS = {
  current: 'mdp-actuel',
  password: 'mdp-nouveau',
  confirmation: 'mdp-confirmation',
} as const

const current = ref('')
const password = ref('')
const confirmation = ref('')
const submitted = ref(false)
const pending = ref(false)
const done = ref(false)
const failure = ref<string | null>(null)
const serverFields = ref<Record<string, string>>({})

const localErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  if (current.value === '') errors[IDS.current] = 'Saisissez votre mot de passe actuel.'
  const problem = passwordError(password.value, session.account?.login ?? '')
  if (problem !== null) errors[IDS.password] = problem
  else if (password.value === current.value) {
    errors[IDS.password] = 'Le nouveau mot de passe est différent de l’actuel.'
  }
  if (confirmation.value !== password.value) {
    errors[IDS.confirmation] = 'La confirmation est différente du nouveau mot de passe.'
  }
  return errors
})

// Le refus du serveur prime sur le contrôle local du même champ : après un 422, le mot de passe actuel
// est effacé, et « Saisissez votre mot de passe actuel » ne doit pas masquer « incorrect ».
const errors = computed<Record<string, string>>(() =>
  submitted.value ? { ...localErrors.value, ...serverFields.value } : serverFields.value,
)
const summary = computed<FieldErrorItem[]>(() =>
  Object.entries(errors.value).map(([fieldId, message]) => ({ fieldId, message })),
)

async function send(): Promise<void> {
  await changeOwnPassword({
    current_password: current.value,
    password: password.value,
    password_confirmation: confirmation.value,
  })
}

async function submit(): Promise<void> {
  submitted.value = true
  failure.value = null
  serverFields.value = {}
  if (Object.keys(localErrors.value).length > 0 || pending.value) return
  pending.value = true
  try {
    await send()
    current.value = ''
    password.value = ''
    confirmation.value = ''
    submitted.value = false
    done.value = true
    await nextTick()
    document.getElementById('mdp-change')?.focus()
  } catch (error) {
    if (error instanceof NetworkUnavailable) {
      failure.value =
        'Le serveur n’a pas répondu : le mot de passe a pu être changé ou non. Essayez de vous reconnecter avec le nouveau, sinon avec l’actuel.'
    } else if (error instanceof ApiError) {
      if (error.status === 401) {
        void revalidateSession().catch(() => undefined)
        failure.value = 'Votre session a pris fin : reconnectez-vous, puis recommencez.'
      } else if (error.status === 422) {
        const map: Record<string, string> = {}
        const pairs: [string, string][] = [
          ['current_password', IDS.current],
          ['password', IDS.password],
          ['password_confirmation', IDS.confirmation],
        ]
        for (const [field, id] of pairs) {
          const message = error.fields[field]?.[0]
          if (message !== undefined) map[id] = message
        }
        serverFields.value = map
        if (Object.keys(map).length === 0) failure.value = error.message
        current.value = ''
      } else if (error.status === 429) {
        failure.value = 'Trop d’essais : patientez une minute avant de recommencer.'
      } else {
        failure.value = error.message
      }
    } else {
      throw error
    }
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <section aria-labelledby="titre-mdp" class="max-w-xl space-y-6">
    <h1 id="titre-mdp" class="text-2xl font-semibold tracking-tight">Changer mon mot de passe</h1>

    <div v-if="done" id="mdp-change" tabindex="-1" class="focus:outline-none">
      <StateMessage state="success" title="Mot de passe changé sur le serveur">
        Votre session reste ouverte ; vos autres sessions ont été fermées.
      </StateMessage>
    </div>

    <form class="space-y-5" novalidate @submit.prevent="submit">
      <ErrorSummary :errors="summary" />
      <StateMessage v-if="failure" state="error" title="Mot de passe non changé">
        {{ failure }}
      </StateMessage>

      <TextField
        v-model="current"
        label="Mot de passe actuel"
        type="password"
        autocomplete="current-password"
        :control-id="IDS.current"
        :error="errors[IDS.current] ?? null"
        required
      />
      <TextField
        v-model="password"
        label="Nouveau mot de passe"
        hint="12 caractères au moins, avec des lettres et des chiffres, différent de votre identifiant."
        type="password"
        autocomplete="new-password"
        :control-id="IDS.password"
        :error="errors[IDS.password] ?? null"
        required
      />
      <TextField
        v-model="confirmation"
        label="Confirmation du nouveau mot de passe"
        type="password"
        autocomplete="new-password"
        :control-id="IDS.confirmation"
        :error="errors[IDS.confirmation] ?? null"
        required
      />
      <AppButton
        type="submit"
        variant="primary"
        :pending="pending"
        pending-label="Changement en cours…"
        >Changer le mot de passe</AppButton
      >
    </form>
  </section>
</template>
