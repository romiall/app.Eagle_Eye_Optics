<script setup lang="ts">
/**
 * Créer un compte nominatif (F05 côté serveur, écran F09) : nom, identifiant, courriel facultatif, un ou
 * plusieurs profils parmi les quatre profils seedés, mot de passe initial. Le mot de passe est saisi par la
 * gérante ou généré par le navigateur (aléa cryptographique) ; il est montré une seule fois, à l'écran, pour
 * être transmis EN PERSONNE, puis oublié : jamais conservé, ni dans l'adresse, ni dans le stockage du
 * navigateur. Le compte change ensuite son mot de passe lui-même.
 *
 * L'écran contrôle les règles avant l'envoi (mêmes règles que le serveur) ; le serveur tranche. Création
 * après réauthentification (423) : le mot de passe de la gérante est demandé, puis la création reprend.
 */
import { computed, nextTick, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { createStaffMember } from '@/app/services/staff'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import CheckboxGroup from '@/components/form/CheckboxGroup.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import {
  generatePassword,
  loginError,
  normalizeLogin,
  passwordError,
  ROLE_LABELS,
  STAFF_ROLES,
} from '@/domain/access/staff'
import type { StaffMember } from '@/domain/contracts/generated'

import { setSettingsFlash } from './flash'
import { useAdminWrite } from './useAdminWrite'

const IDS = {
  name: 'compte-nom',
  login: 'compte-identifiant',
  email: 'compte-courriel',
  roles: 'compte-profils',
  password: 'compte-mot-de-passe',
} as const

const router = useRouter()
const writer = useAdminWrite()

const displayName = ref('')
const login = ref('')
const email = ref('')
const roles = ref<string[]>([])
const password = ref('')
const generated = ref(false)
const submitted = ref(false)
const created = ref<StaffMember | null>(null)
/** Mot de passe initial montré UNE fois après la création, puis effacé. */
const shownPassword = ref<string | null>(null)

const roleOptions = STAFF_ROLES.map((role) => ({ value: role, label: ROLE_LABELS[role] }))

const localErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  const name = displayName.value.trim()
  if (name.length < 2) errors[IDS.name] = 'Saisissez le nom affiché (2 caractères au moins).'
  else if (name.length > 180) errors[IDS.name] = 'Le nom affiché ne dépasse pas 180 caractères.'
  const loginProblem = loginError(login.value)
  if (loginProblem !== null) errors[IDS.login] = loginProblem
  const mail = email.value.trim()
  if (mail !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) {
    errors[IDS.email] = 'L’adresse électronique n’est pas valide.'
  }
  if (roles.value.length === 0) errors[IDS.roles] = 'Choisissez au moins un profil.'
  const passwordProblem = passwordError(password.value, normalizeLogin(login.value))
  if (passwordProblem !== null) errors[IDS.password] = passwordProblem
  return errors
})

const serverErrors = computed<Record<string, string>>(() => {
  const map: Record<string, string> = {}
  const pairs: [string, string][] = [
    ['display_name', IDS.name],
    ['login', IDS.login],
    ['email', IDS.email],
    ['roles', IDS.roles],
    ['password', IDS.password],
  ]
  for (const [field, id] of pairs) {
    const message = writer.fieldError(field)
    if (message !== null) map[id] = message
  }
  return map
})

const errors = computed<Record<string, string>>(() =>
  submitted.value ? { ...serverErrors.value, ...localErrors.value } : serverErrors.value,
)

const summary = computed<FieldErrorItem[]>(() =>
  Object.entries(errors.value).map(([fieldId, message]) => ({ fieldId, message })),
)

function generate(): void {
  password.value = generatePassword()
  generated.value = true
}

function onPasswordInput(value: string): void {
  password.value = value
  generated.value = false
}

async function submit(): Promise<void> {
  submitted.value = true
  if (Object.keys(localErrors.value).length > 0) return
  const initialPassword = password.value
  const showAfter = generated.value
  await writer.run(
    () =>
      createStaffMember({
        login: normalizeLogin(login.value),
        display_name: displayName.value.trim(),
        email: email.value.trim() === '' ? null : email.value.trim(),
        password: initialPassword,
        roles: [...roles.value],
      }),
    async (member) => {
      created.value = member
      // Le mot de passe saisi par la gérante n'est pas réaffiché ; un mot de passe généré l'est une fois.
      shownPassword.value = showAfter ? initialPassword : null
      password.value = ''
      await nextTick()
      document.getElementById('compte-cree')?.focus()
    },
  )
}

async function done(): Promise<void> {
  const member = created.value
  shownPassword.value = null
  if (member === null) return
  setSettingsFlash(`Compte « ${member.display_name} » créé.`)
  await router.push({ name: 'parametres-compte', params: { id: member.id } })
}
</script>

<template>
  <section aria-labelledby="titre-nouveau-compte" class="max-w-2xl space-y-6">
    <div class="space-y-1">
      <h1 id="titre-nouveau-compte" class="text-2xl font-semibold tracking-tight">
        Créer un compte
      </h1>
      <p class="text-muted">
        <RouterLink
          :to="{ name: 'parametres-personnel' }"
          class="text-primary underline underline-offset-4"
          >Personnel</RouterLink
        >
      </p>
    </div>

    <div
      v-if="created"
      id="compte-cree"
      tabindex="-1"
      class="space-y-3 rounded-lg border-2 border-accent bg-surface p-4 focus:outline-none"
    >
      <h2 class="text-xl font-semibold">Compte créé sur le serveur</h2>
      <p>
        <strong>{{ created.display_name }}</strong> peut se connecter avec l’identifiant
        <strong class="break-all">{{ created.login }}</strong
        >.
      </p>
      <template v-if="shownPassword">
        <p>Mot de passe initial (affiché une seule fois) :</p>
        <p
          class="rounded-lg border-2 border-line bg-primary-soft px-3 py-2 font-mono text-lg break-all"
        >
          {{ shownPassword }}
        </p>
        <p class="text-sm text-muted">
          Transmettez-le en personne, jamais par message. La personne le changera à sa première
          connexion. Il ne sera plus affiché après cet écran.
        </p>
      </template>
      <p v-else class="text-sm text-muted">
        Transmettez en personne le mot de passe que vous avez saisi ; la personne le changera à sa
        première connexion.
      </p>
      <AppButton variant="primary" @click="done">J’ai noté, ouvrir le compte</AppButton>
    </div>

    <form v-else class="space-y-5" novalidate @submit.prevent="submit">
      <ErrorSummary :errors="summary" />

      <StateMessage
        v-if="writer.message.value && Object.keys(writer.fieldErrors.value).length === 0"
        :state="writer.state.value === 'uncertain' ? 'offline' : 'error'"
        title="Compte non créé"
      >
        {{ writer.message.value }}
      </StateMessage>

      <TextField
        v-model="displayName"
        label="Nom affiché"
        hint="Nom et prénom tels qu’ils apparaîtront dans le journal et sur les pièces."
        :control-id="IDS.name"
        :maxlength="180"
        :error="errors[IDS.name] ?? null"
        required
        autocomplete="off"
      />
      <TextField
        v-model="login"
        label="Identifiant de connexion"
        hint="Minuscules sans accent, chiffres, « . », « _ » ou « - » ; par exemple marie.ngono."
        :control-id="IDS.login"
        :maxlength="64"
        :error="errors[IDS.login] ?? null"
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
        hint="Un compte peut cumuler plusieurs profils. Les droits de chaque profil viennent du référentiel."
        :options="roleOptions"
        :control-id="IDS.roles"
        :error="errors[IDS.roles] ?? null"
      />
      <div class="space-y-2">
        <TextField
          :model-value="password"
          label="Mot de passe initial"
          hint="12 caractères au moins, avec des lettres et des chiffres, différent de l’identifiant."
          type="password"
          autocomplete="new-password"
          :control-id="IDS.password"
          :error="errors[IDS.password] ?? null"
          required
          @update:model-value="onPasswordInput"
        />
        <AppButton variant="secondary" @click="generate">Générer un mot de passe</AppButton>
        <p v-if="generated" class="text-sm text-muted">
          Un mot de passe aléatoire a été placé dans le champ ; il vous sera montré une fois après la
          création, pour le transmettre en personne.
        </p>
      </div>

      <div class="flex flex-wrap gap-3">
        <AppButton
          type="submit"
          variant="primary"
          :pending="writer.state.value === 'saving'"
          pending-label="Création en cours…"
          >Créer le compte</AppButton
        >
      </div>
    </form>

    <PasswordConfirmDialog
      :open="writer.state.value === 'reauthentication'"
      action="La création d’un compte"
      @confirmed="writer.resume()"
      @cancel="writer.cancel()"
    />
  </section>
</template>
