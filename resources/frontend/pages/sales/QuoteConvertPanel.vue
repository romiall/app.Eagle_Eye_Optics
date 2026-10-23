<script setup lang="ts">
/**
 * Conversion d'une révision CHOISIE d'un devis en BROUILLON de facture (L1D07). Avant tout envoi, l'écran montre,
 * ligne par ligne, le prix du devis et le prix ACTUEL du catalogue (lu sur le serveur) ; la personne choisit
 * explicitement la base de prix de la facture et coche la confirmation. Pour un prospect, elle choisit le dossier
 * patient qui recevra la facture (le prospect le rejoint). Rien d'automatique au-delà : la facture reste un
 * brouillon (ni numéro, ni créance, ni réserve de stock, ni paiement), qui s'ouvre aussitôt pour être vérifié puis
 * émis par le parcours habituel. Une reprise (coupure, double clic) rejoue la même conversion, jamais une seconde.
 */
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { searchCatalog } from '@/app/services/billing'
import type { MutationOutcome } from '@/app/services/mutations'
import { searchPatients } from '@/app/services/patients'
import { quotePath } from '@/app/services/quotes'
import { useMutation } from '@/app/services/useMutation'
import { canOnScreen, moduleOpenOnScreen, revalidateSession } from '@/app/session'
import StateMessage from '@/components/feedback/StateMessage.vue'
import CheckboxField from '@/components/form/CheckboxField.vue'
import ComboboxField, {
  type ComboboxOption,
  type ComboboxSearchResult,
} from '@/components/form/ComboboxField.vue'
import SelectField from '@/components/form/SelectField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import {
  buildConvertPayload,
  latestRevision,
  type PriceBasis,
  priceComparison,
} from '@/domain/billing/quote'
import type { InvoiceResponse, Quote } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'

import { salesFailureView } from './failureView'
import { setSalesFlash } from './flash'
import { formatMoney, quantityLabel } from './format'

const props = defineProps<{ quote: Quote }>()
const emit = defineEmits<{ stale: [] }>()

const router = useRouter()
const mutation = useMutation()

const latest = latestRevision(props.quote)
const revisionNo = ref<string | null>(latest === null ? null : String(latest.revision_no))
const revisionOptions = computed(() =>
  props.quote.revisions.map((revision) => ({
    value: String(revision.revision_no),
    label: `Révision ${revision.revision_no} · ${formatMoney(revision.snapshot.total_gross)}`,
  })),
)
const revision = computed(
  () =>
    props.quote.revisions.find((candidate) => String(candidate.revision_no) === revisionNo.value) ??
    null,
)

const prospect = computed(() => props.quote.recipient.kind === 'PROSPECT')
const patientId = ref<string | null>(null)
const canSearchPatients = computed(
  () => canOnScreen('patient.read') && moduleOpenOnScreen('patients'),
)

async function searchPatient(query: string): Promise<ComboboxSearchResult> {
  const result = await searchPatients(query)
  const options: ComboboxOption[] = result.data.data
    .filter((summary) => !summary.archived && summary.merged_into_patient_id === null)
    .map((summary) => ({
      value: summary.id,
      label: patientDisplayName(summary.last_name, summary.first_names),
      description: `Dossier ${summary.record_number}`,
    }))
  return result.data.meta.last_page > 1
    ? { options, notice: 'D’autres patients correspondent : précisez la recherche.' }
    : options
}

// --- Prix actuels du catalogue ---------------------------------------------------------------------

const catalogPrices = ref<ReadonlyMap<string, number>>(new Map())
const pricesState = ref<'idle' | 'loading' | 'ready' | 'partial'>('idle')

/** Prix actuels des articles de la révision, retrouvés par leur référence (recherche du serveur). */
async function loadPrices(): Promise<void> {
  const current = revision.value
  if (current === null || !canOnScreen('catalog.read')) {
    pricesState.value = 'partial'
    return
  }
  pricesState.value = 'loading'
  const found = new Map<string, number>()
  let complete = true
  for (const line of current.snapshot.lines) {
    if (line.catalog_item_id === null) continue
    if (line.reference === null || line.reference.trim().length < 2) {
      complete = false
      continue
    }
    try {
      const page = await searchCatalog(line.reference)
      const item = page.data.data.find((candidate) => candidate.id === line.catalog_item_id)
      if (item === undefined || !item.active) complete = false
      else found.set(item.id, item.sale_price)
    } catch {
      complete = false
    }
  }
  catalogPrices.value = found
  pricesState.value = complete ? 'ready' : 'partial'
}

watch(revisionNo, () => void loadPrices(), { immediate: true })

const rows = computed(() =>
  revision.value === null ? [] : priceComparison(revision.value, catalogPrices.value),
)
const anyDifference = computed(() => rows.value.some((row) => row.differs))

const basis = ref<string | null>('QUOTE')
const basisOptions = [
  { value: 'QUOTE', label: 'Prix et remises du devis (ce qui a été proposé au client)' },
  { value: 'CATALOG', label: 'Prix actuels du catalogue, sans remise' },
]
const confirmed = ref(false)

const busy = computed(() => mutation.state.value === 'saving')
const failure = computed(() => salesFailureView(mutation.state.value))
const refusal = ref<string | null>(null)
const existingInvoice = ref<string | null>(null)

const ready = computed(
  () =>
    revision.value !== null &&
    (basis.value === 'QUOTE' || basis.value === 'CATALOG') &&
    confirmed.value &&
    (!prospect.value || patientId.value !== null),
)

async function after(result: MutationOutcome): Promise<void> {
  switch (result.kind) {
    case 'synced': {
      const invoice = (result.result as InvoiceResponse).data
      setSalesFlash(
        'Brouillon de facture créé depuis le devis : vérifiez-le, puis émettez-le. Aucun paiement n’a été enregistré.',
      )
      await router.push({ name: 'facture', params: { id: invoice.id } })
      return
    }
    case 'conflict':
      refusal.value = 'Le devis a changé entre-temps : relisez-le avant de le convertir.'
      emit('stale')
      return
    case 'refused': {
      const invoiceId = result.error.details['invoice_id']
      if (typeof invoiceId === 'string') {
        existingInvoice.value = invoiceId
        refusal.value = result.error.message
        return
      }
      if (result.error.code === 'STATE_TRANSITION_REFUSED') {
        refusal.value = result.error.message
        emit('stale')
        return
      }
      const fieldMessages = Object.values(result.error.fields).flat()
      refusal.value = fieldMessages.length > 0 ? fieldMessages.join(' ') : result.error.message
      return
    }
    case 'session-expired':
    case 'reauthentication-required':
      void revalidateSession().catch(() => undefined)
      return
    default:
      return
  }
}

async function convert(): Promise<void> {
  const current = revision.value
  if (busy.value || !ready.value || current === null) return
  refusal.value = null
  existingInvoice.value = null
  await after(
    await mutation.submit({
      name: 'ConvertQuote',
      route: {
        method: 'POST',
        path: quotePath(props.quote.id, 'conversions'),
        expectedVersion: 'entity',
      },
      payload: buildConvertPayload({
        quoteId: props.quote.id,
        revisionNo: current.revision_no,
        invoiceId: mutation.effectId('invoice_id'),
        patientId: prospect.value ? patientId.value : null,
        priceBasis: basis.value as PriceBasis,
      }),
      expectedVersion: props.quote.version,
    }),
  )
}

async function retry(): Promise<void> {
  if (busy.value) return
  await after(await mutation.retry())
}
</script>

<template>
  <section
    aria-labelledby="titre-conversion"
    class="space-y-4 rounded-xl border border-line bg-surface p-4"
  >
    <h2 id="titre-conversion" class="text-lg font-semibold">Convertir en facture</h2>
    <p class="text-base text-muted">
      La conversion crée un BROUILLON de facture lié à ce devis : rien n’est émis ni encaissé, aucun
      article n’est réservé tant que la facture n’est pas émise.
    </p>

    <SelectField
      v-model="revisionNo"
      label="Révision à convertir"
      :options="revisionOptions"
      control-id="conversion-revision"
      required
      :disabled="busy"
    />

    <template v-if="prospect">
      <ComboboxField
        v-if="canSearchPatients"
        v-model="patientId"
        label="Dossier patient qui recevra la facture"
        hint="Le prospect rejoint ce dossier ; créez d’abord la fiche du patient si elle n’existe pas."
        :search="searchPatient"
        control-id="conversion-patient"
        required
        unavailable-text="Recherche serveur indisponible : cela ne prouve pas que le patient n’existe pas."
      />
      <StateMessage v-else state="forbidden" title="Choix du patient impossible">
        Ce compte ne peut pas rechercher les patients : la facture d’un prospect exige son dossier.
      </StateMessage>
    </template>

    <div v-if="revision" class="space-y-2">
      <h3 class="text-base font-semibold">Prix de la révision et du catalogue actuel</h3>
      <StateMessage
        v-if="pricesState === 'loading'"
        state="loading"
        title="Lecture des prix du catalogue…"
      />
      <ul class="space-y-2">
        <li
          v-for="row in rows"
          :key="row.position"
          class="rounded-lg border border-line p-3"
          :class="row.differs ? 'border-accent bg-accent-soft' : ''"
        >
          <p class="font-medium break-words">{{ row.description }}</p>
          <p class="text-sm">
            Quantité {{ quantityLabel(row.quantity) }} · prix du devis
            <span class="tabular-nums">{{ formatMoney(row.quotePrice) }}</span>
            <template v-if="row.quoteDiscount > 0">
              (remise <span class="tabular-nums">{{ formatMoney(row.quoteDiscount) }}</span
              >)</template
            >
          </p>
          <p class="text-sm">
            Prix actuel du catalogue :
            <span class="tabular-nums">{{
              row.catalogPrice === null
                ? 'non lu ou ligne hors catalogue'
                : formatMoney(row.catalogPrice)
            }}</span>
            <strong v-if="row.differs"> — différent du devis</strong>
          </p>
        </li>
      </ul>
      <p v-if="pricesState === 'partial'" class="text-sm text-muted">
        Certains prix actuels n’ont pas pu être lus : vérifiez-les dans le catalogue avant de
        choisir la base de prix.
      </p>
      <p v-if="anyDifference" class="text-sm font-medium">
        <span aria-hidden="true">⚠ </span>Des prix du catalogue ont changé depuis ce devis :
        choisissez explicitement la base de prix de la facture.
      </p>
    </div>

    <SelectField
      v-model="basis"
      label="Base de prix de la facture"
      :options="basisOptions"
      control-id="conversion-base-prix"
      required
      :disabled="busy"
    />
    <CheckboxField
      v-model="confirmed"
      label="J’ai vérifié les prix de chaque ligne"
      hint="Obligatoire : le serveur refuse une conversion sans cette confirmation."
      control-id="conversion-confirmation"
      :disabled="busy"
    />

    <StateMessage v-if="failure" :state="failure.state" :title="failure.title">
      {{ mutation.failureMessage.value }}
      <template v-if="failure.retry" #action>
        <AppButton variant="secondary" :pending="busy" @click="retry"
          >Réessayer la conversion</AppButton
        >
      </template>
    </StateMessage>
    <StateMessage v-if="refusal" state="error" title="Conversion refusée">
      {{ refusal }}
      <template v-if="existingInvoice" #action>
        <RouterLink
          :to="{ name: 'facture', params: { id: existingInvoice } }"
          class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
          >Ouvrir la facture existante</RouterLink
        >
      </template>
    </StateMessage>

    <AppButton
      variant="primary"
      :disabled="!ready"
      :pending="busy"
      pending-label="Conversion en cours…"
      @click="convert"
      >Créer le brouillon de facture</AppButton
    >
  </section>
</template>
