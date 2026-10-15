<script setup lang="ts">
/**
 * Connexion du personnel (session SPA Sanctum, F05). Un compte nominatif, aucun mot de passe
 * universel. Les erreurs du serveur s'affichent sous leur champ et dans le résumé focalisé
 * (identifiants refusés : 422 sur « login » ; trop de tentatives : 429). Hors réseau, la connexion
 * au serveur est impossible : le message le dit, sans prétendre à un mode hors-ligne qui n'existe
 * pas encore (déverrouillage local d'un poste préparé : O01 et O02).
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { signIn } from '@/app/session'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import TextField from '@/components/form/TextField.vue'
import AppButton from '@/components/ui/AppButton.vue'

const LOGIN_ID = 'connexion-identifiant'
const PASSWORD_ID = 'connexion-mot-de-passe'

const route = useRoute()
const router = useRouter()

const login = ref('')
const password = ref('')
const pending = ref(false)
const fieldErrors = ref<{ login: string | null; password: string | null }>({
  login: null,
  password: null,
})
const general = ref<string | null>(null)
const summaryBox = ref<{ focus: () => Promise<void> } | null>(null)

const summary = computed<FieldErrorItem[]>(() => {
  const items: FieldErrorItem[] = []
  if (fieldErrors.value.login !== null)
    items.push({ fieldId: LOGIN_ID, message: fieldErrors.value.login })
  if (fieldErrors.value.password !== null)
    items.push({ fieldId: PASSWORD_ID, message: fieldErrors.value.password })
  if (items.length === 0 && general.value !== null)
    items.push({ fieldId: LOGIN_ID, message: general.value })
  return items
})

/** Destination après connexion : un chemin interne seulement, jamais une autre origine. */
function destination(): string {
  const next = route.query['suite']
  return typeof next === 'string' && next.startsWith('/') && !next.startsWith('//') ? next : '/'
}

function firstMessage(messages: readonly string[] | undefined): string | null {
  return messages !== undefined && messages.length > 0 ? (messages[0] ?? null) : null
}

async function submit(): Promise<void> {
  fieldErrors.value = { login: null, password: null }
  general.value = null
  const missingLogin = login.value.trim() === ''
  const missingPassword = password.value === ''
  if (missingLogin || missingPassword) {
    fieldErrors.value = {
      login: missingLogin ? 'Saisissez votre identifiant.' : null,
      password: missingPassword ? 'Saisissez votre mot de passe.' : null,
    }
    // Même erreur qu'à l'envoi précédent : le résumé est de nouveau focalisé, donc annoncé.
    await summaryBox.value?.focus()
    return
  }

  pending.value = true
  try {
    await signIn({ login: login.value.trim(), password: password.value })
    password.value = ''
    await router.push(destination())
  } catch (error) {
    if (error instanceof NetworkUnavailable) {
      general.value =
        'Serveur injoignable : la connexion demande le réseau. Réessayez quand la connexion revient.'
    } else if (error instanceof ApiError) {
      fieldErrors.value = {
        login: firstMessage(error.fields['login']),
        password: firstMessage(error.fields['password']),
      }
      general.value = error.message
      // Identifiants refusés : le mot de passe est à ressaisir ; l'identifiant reste en place.
      if (error.status === 422) password.value = ''
    } else {
      throw error
    }
    await summaryBox.value?.focus()
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <section aria-labelledby="titre-connexion" class="mx-auto max-w-md space-y-6">
    <h1 id="titre-connexion" class="text-2xl font-semibold tracking-tight">Connexion</h1>
    <ErrorSummary ref="summaryBox" :errors="summary" title="Connexion impossible" />
    <form class="space-y-4" novalidate @submit.prevent="submit">
      <TextField
        v-model="login"
        label="Identifiant"
        :control-id="LOGIN_ID"
        autocomplete="username"
        :error="fieldErrors.login"
        required
      />
      <TextField
        v-model="password"
        label="Mot de passe"
        type="password"
        :control-id="PASSWORD_ID"
        autocomplete="current-password"
        :error="fieldErrors.password"
        required
      />
      <AppButton type="submit" :pending="pending" pending-label="Connexion en cours…">
        Se connecter
      </AppButton>
    </form>
  </section>
</template>
