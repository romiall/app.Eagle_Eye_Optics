<script setup lang="ts">
/**
 * Cabinet et documents (F05 côté serveur, écran F09) : nom, coordonnées, identifiant fiscal et mentions
 * professionnelles imprimés sur les reçus, factures et ordonnances, plus les lignes d'en-tête, de pied et les
 * conditions de règlement des documents (`document_settings` : `header_lines`, `footer_lines`,
 * `payment_terms`, lus par le moteur de documents). Tout est SAISI par la gérante : rien n'est proposé ni
 * inventé. Le fuseau, la monnaie et l'activation des documents officiels ne se modifient pas ici (procédure
 * de bascule) : l'écran les montre en lecture seule et le dit. Enregistrement après réauthentification.
 *
 * Une facture déjà émise garde l'identité figée à son émission : un changement ici ne vaut que pour les
 * pièces suivantes.
 */
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { clinicTimeZone } from '@/app/clinicTime'
import {
  getClinicSettings,
  updateClinicSettings,
  type UpdateClinicSettingsInput,
} from '@/app/services/staff'
import ErrorSummary, { type FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import ReadNotice from '@/components/feedback/ReadNotice.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import type { ClinicSettings } from '@/domain/contracts/generated'
import { readDocumentSettings } from '@/documents/data/clinic'
import { useServerRead } from '@/pages/today/useServerRead'

import { useAdminWrite } from './useAdminWrite'

const IDS = {
  name: 'cabinet-nom',
  address: 'cabinet-adresse',
  phone: 'cabinet-telephone',
  email: 'cabinet-courriel',
  tax: 'cabinet-identifiant-fiscal',
  mentions: 'cabinet-mentions',
  header: 'cabinet-entete',
  footer: 'cabinet-pied',
  terms: 'cabinet-conditions',
} as const

/** Limites du moteur de documents (`readDocumentSettings`) : au-delà, le texte serait tronqué à l'impression. */
const LINES_MAX = 6
const LINE_LENGTH = 200
const TERMS_LENGTH = 1000

const timeZone = clinicTimeZone()
const read = useServerRead<ClinicSettings>(() => getClinicSettings())
const writer = useAdminWrite()

const name = ref('')
const address = ref('')
const phone = ref('')
const email = ref('')
const taxIdentifier = ref('')
const mentions = ref('')
const headerLines = ref('')
const footerLines = ref('')
const paymentTerms = ref('')
const submitted = ref(false)
const saved = ref(false)

function fill(settings: ClinicSettings): void {
  const documents = readDocumentSettings(settings.document_settings)
  name.value = settings.name
  address.value = settings.address ?? ''
  phone.value = settings.phone ?? ''
  email.value = settings.email ?? ''
  taxIdentifier.value = settings.tax_identifier ?? ''
  mentions.value = settings.professional_mentions ?? ''
  headerLines.value = documents.headerLines.join('\n')
  footerLines.value = documents.footerLines.join('\n')
  paymentTerms.value = documents.paymentTerms ?? ''
  submitted.value = false
}

watch(
  () => read.data.value,
  (settings) => {
    if (settings !== null) fill(settings)
  },
  { immediate: true },
)

function lines(text: string): string[] {
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

function nullable(text: string): string | null {
  const trimmed = text.trim()
  return trimmed === '' ? null : trimmed
}

const localErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  const clinicName = name.value.trim()
  if (clinicName.length < 2)
    errors[IDS.name] = 'Saisissez le nom du cabinet (2 caractères au moins).'
  else if (clinicName.length > 180) errors[IDS.name] = 'Le nom ne dépasse pas 180 caractères.'
  if (phone.value.trim().length > 32)
    errors[IDS.phone] = 'Le téléphone ne dépasse pas 32 caractères.'
  const mail = email.value.trim()
  if (mail !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) {
    errors[IDS.email] = 'L’adresse électronique n’est pas valide.'
  }
  for (const [text, id] of [
    [headerLines.value, IDS.header],
    [footerLines.value, IDS.footer],
  ] as const) {
    const list = lines(text)
    if (list.length > LINES_MAX) errors[id] = `${LINES_MAX} lignes au plus.`
    else if (list.some((line) => line.length > LINE_LENGTH)) {
      errors[id] = `Chaque ligne compte ${LINE_LENGTH} caractères au plus.`
    }
  }
  if (paymentTerms.value.trim().length > TERMS_LENGTH) {
    errors[IDS.terms] = `${TERMS_LENGTH} caractères au plus.`
  }
  return errors
})

const errors = computed<Record<string, string>>(() => {
  const server: Record<string, string> = {}
  for (const [field, id] of [
    ['name', IDS.name],
    ['address', IDS.address],
    ['phone', IDS.phone],
    ['email', IDS.email],
    ['tax_identifier', IDS.tax],
    ['professional_mentions', IDS.mentions],
    ['document_settings', IDS.header],
  ] as const) {
    const message = writer.fieldError(field)
    if (message !== null) server[id] = message
  }
  return submitted.value ? { ...server, ...localErrors.value } : server
})

const summary = computed<FieldErrorItem[]>(() =>
  Object.entries(errors.value).map(([fieldId, message]) => ({ fieldId, message })),
)

function payload(current: ClinicSettings): UpdateClinicSettingsInput {
  // Les autres clés éventuelles de document_settings sont conservées telles quelles.
  const base =
    typeof current.document_settings === 'object' && current.document_settings !== null
      ? { ...(current.document_settings as Record<string, unknown>) }
      : {}
  const header = lines(headerLines.value)
  const footer = lines(footerLines.value)
  const terms = nullable(paymentTerms.value)
  return {
    name: name.value.trim(),
    address: nullable(address.value),
    phone: nullable(phone.value),
    email: nullable(email.value),
    tax_identifier: nullable(taxIdentifier.value),
    professional_mentions: nullable(mentions.value),
    document_settings: {
      ...base,
      header_lines: header,
      footer_lines: footer,
      payment_terms: terms,
    },
  }
}

async function submit(): Promise<void> {
  submitted.value = true
  saved.value = false
  const current = read.data.value
  if (current === null || Object.keys(localErrors.value).length > 0) return
  const input = payload(current)
  await writer.run(
    () => updateClinicSettings(input),
    (settings) => {
      read.data.value = settings
      saved.value = true
    },
  )
}
</script>

<template>
  <section aria-labelledby="titre-cabinet" class="max-w-2xl space-y-6">
    <div class="space-y-1">
      <h1 id="titre-cabinet" class="text-2xl font-semibold tracking-tight">Cabinet et documents</h1>
      <p class="text-muted">
        <RouterLink :to="{ name: 'parametres' }" class="text-primary underline underline-offset-4"
          >Paramètres</RouterLink
        >
      </p>
    </div>

    <ReadNotice
      v-if="
        read.data.value === null || (read.state.value !== 'ready' && read.state.value !== 'loading')
      "
      :state="read.state.value"
      subject="les paramètres du cabinet"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <template v-if="read.data.value !== null">
      <StateMessage
        :state="read.data.value.production_documents_enabled ? 'success' : 'empty'"
        :title="
          read.data.value.production_documents_enabled
            ? 'Documents officiels activés'
            : 'Documents de test'
        "
      >
        <template v-if="read.data.value.production_documents_enabled">
          Les reçus, factures et ordonnances portent l’identité ci-dessous, sans mention de test.
        </template>
        <template v-else>
          Tant que les mentions réelles ne sont pas validées lors de la mise en service, chaque
          document porte « DOCUMENT DE TEST ». Cette activation ne se fait pas depuis cet écran.
        </template>
      </StateMessage>

      <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-[auto_1fr]">
        <dt class="font-medium">Fuseau horaire</dt>
        <dd>{{ read.data.value.timezone }}</dd>
        <dt class="font-medium">Monnaie</dt>
        <dd>{{ read.data.value.currency }}</dd>
      </dl>
      <p class="text-sm text-muted">
        Le fuseau et la monnaie ne se modifient pas : les dates et montants déjà enregistrés en
        dépendent.
      </p>

      <form class="space-y-5" novalidate @submit.prevent="submit">
        <ErrorSummary :errors="summary" />
        <StateMessage
          v-if="writer.message.value && Object.keys(writer.fieldErrors.value).length === 0"
          :state="writer.state.value === 'uncertain' ? 'offline' : 'error'"
          title="Paramètres non enregistrés"
        >
          {{ writer.message.value }}
        </StateMessage>
        <StateMessage
          v-if="saved && writer.state.value === 'saved'"
          state="success"
          title="Paramètres enregistrés sur le serveur"
        >
          Ils valent pour les documents produits à partir de maintenant ; une facture déjà émise
          garde l’identité de son émission.
        </StateMessage>

        <TextField
          v-model="name"
          label="Nom du cabinet"
          :control-id="IDS.name"
          :maxlength="180"
          :error="errors[IDS.name] ?? null"
          required
          autocomplete="organization"
        />
        <TextAreaField
          v-model="address"
          label="Adresse"
          :control-id="IDS.address"
          :rows="3"
          :maxlength="2000"
          :error="errors[IDS.address] ?? null"
          autocomplete="street-address"
        />
        <TextField
          v-model="phone"
          label="Téléphone"
          type="tel"
          :control-id="IDS.phone"
          :maxlength="32"
          :error="errors[IDS.phone] ?? null"
          autocomplete="tel"
        />
        <TextField
          v-model="email"
          label="Adresse électronique"
          type="email"
          :control-id="IDS.email"
          :maxlength="254"
          :error="errors[IDS.email] ?? null"
          autocomplete="email"
        />
        <TextField
          v-model="taxIdentifier"
          label="Identifiant fiscal"
          hint="Tel qu’il doit figurer sur les factures (à fournir par la cliente)."
          :control-id="IDS.tax"
          :maxlength="100"
          :error="errors[IDS.tax] ?? null"
          autocomplete="off"
        />
        <TextAreaField
          v-model="mentions"
          label="Mentions professionnelles"
          hint="Mentions légales ou professionnelles imprimées sur les documents, telles que validées par la cliente."
          :control-id="IDS.mentions"
          :rows="4"
          :maxlength="4000"
          :error="errors[IDS.mentions] ?? null"
        />

        <fieldset class="space-y-4 rounded-lg border-2 border-line p-3">
          <legend class="px-1 text-base font-semibold">Documents</legend>
          <TextAreaField
            v-model="headerLines"
            label="Lignes d’en-tête"
            :hint="`Une ligne par information, ${LINES_MAX} lignes au plus, sous les coordonnées du cabinet.`"
            :control-id="IDS.header"
            :rows="3"
            :error="errors[IDS.header] ?? null"
          />
          <TextAreaField
            v-model="footerLines"
            label="Lignes de pied de page"
            :hint="`Une ligne par information, ${LINES_MAX} lignes au plus.`"
            :control-id="IDS.footer"
            :rows="3"
            :error="errors[IDS.footer] ?? null"
          />
          <TextAreaField
            v-model="paymentTerms"
            label="Conditions de règlement des factures"
            hint="Texte validé par la cliente ; laissé vide, rien n’est imprimé."
            :control-id="IDS.terms"
            :rows="3"
            :maxlength="TERMS_LENGTH"
            :error="errors[IDS.terms] ?? null"
          />
        </fieldset>

        <AppButton
          type="submit"
          variant="primary"
          :pending="writer.state.value === 'saving'"
          pending-label="Enregistrement en cours…"
          >Enregistrer les paramètres</AppButton
        >
      </form>

      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>

    <PasswordConfirmDialog
      :open="writer.state.value === 'reauthentication'"
      action="La modification des paramètres du cabinet"
      @confirmed="writer.resume()"
      @cancel="writer.cancel()"
    />
  </section>
</template>
