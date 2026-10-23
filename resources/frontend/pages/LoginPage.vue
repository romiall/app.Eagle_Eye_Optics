<script setup lang="ts">
/**
 * Connexion du personnel (session SPA Sanctum, F05). Un compte nominatif, aucun mot de passe
 * universel. Les erreurs du serveur s'affichent sous leur champ et dans le résumé focalisé
 * (identifiants refusés : 422 sur « login » ; trop de tentatives : 429). Hors réseau, la connexion
 * au serveur est impossible : le message le dit, sans prétendre à un mode hors-ligne qui n'existe
 * pas encore (déverrouillage local d'un poste préparé : O01 et O02).
 *
 * Présentation : carte centrée, logo du cabinet à côté du formulaire (au-dessus sur téléphone),
 * en-tête d'écran commun (`PageHeader`) et rappel pour un mot de passe oublié (réinitialisation
 * par la gérante ; celui d'une gérante, sur le serveur, comme le dit l'écran du personnel). Les
 * champs, leurs libellés et le flux restent ceux de F05.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { signIn } from '@/app/session'
import logoUrl from '@/assets/brand/eeo-logo.svg'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import TextField from '@/components/form/TextField.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
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
  <section
    aria-labelledby="titre-connexion"
    class="mx-auto grid w-full max-w-4xl overflow-hidden rounded-2xl border border-line bg-surface shadow-raised md:mt-6 md:grid-cols-2"
  >
    <div
      class="flex flex-col items-center justify-center gap-4 border-b border-line bg-surface px-6 py-6 text-center sm:py-8 md:border-r md:border-b-0 md:px-10 md:py-14"
    >
      <img
        :src="logoUrl"
        alt="Eagle’s Eye Optics"
        width="380"
        height="417"
        class="h-auto w-28 sm:w-44 md:w-60"
      />
      <span aria-hidden="true" class="h-0.5 w-16 rounded-full bg-gold"></span>
      <p class="max-w-xs text-sm font-medium text-muted">Espace réservé au personnel du cabinet</p>
    </div>
    <div class="space-y-6 bg-canvas px-6 py-8 sm:px-8 md:px-10 md:py-14">
      <PageHeader
        title-id="titre-connexion"
        title="Connexion"
        subtitle="Saisissez l’identifiant et le mot de passe de votre compte personnel."
      />
      <ErrorSummary ref="summaryBox" :errors="summary" title="Connexion impossible" />
      <form class="space-y-5" novalidate @submit.prevent="submit">
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
        <AppButton
          type="submit"
          :pending="pending"
          pending-label="Connexion en cours…"
          class="w-full"
        >
          Se connecter
        </AppButton>
      </form>
      <p class="border-t border-line pt-4 text-sm text-muted">
        Mot de passe oublié : demandez à la gérante de le réinitialiser. Celui d’une gérante se
        réinitialise sur le serveur (guide d’exploitation).
      </p>
    </div>
  </section>
</template>
