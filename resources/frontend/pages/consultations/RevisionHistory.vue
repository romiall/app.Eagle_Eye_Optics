<script setup lang="ts">
/**
 * Historique des révisions d'une consultation terminée et comparaison côte à côte (C03 ; fichier 02,
 * « Historique et statistiques » ; AT19, AT21). Chaque révision est immuable et lisible telle qu'enregistrée ;
 * une correction n'écrase jamais la précédente.
 *
 * - Deux révisions se comparent fait par fait. Le changement est dit en texte ET par un symbole (« ≠ Modifié »,
 *   « + Ajouté », « − Retiré », « = Identique ») : la couleur n'est jamais le seul signal.
 * - Une valeur absente est « non renseigné » ou « non mesuré », jamais « aucun » ni zéro.
 * - Une acuité est toujours rendue avec son échelle ; des échelles différentes sont déclarées non comparables.
 * - Mise en page : sur grand écran, trois colonnes (élément, révision de référence, révision comparée) ; sur
 *   téléphone, des blocs empilés, sans défilement horizontal.
 * - Hors ligne : « indisponible », jamais une révision vide ni une ancienne copie.
 */
import { computed, ref, shallowRef, watch } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { formatInstantFr } from '@/app/clinicTime'
import { getConsultation, getRevision, getVisualAcuityScales } from '@/app/services/consultations'
import StateMessage from '@/components/feedback/StateMessage.vue'
import SelectField from '@/components/form/SelectField.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import {
  CHANGE_LABELS,
  CHANGE_SYMBOLS,
  compareSteps,
  type ComparisonRow,
  describeSteps,
  type StepsData,
} from '@/domain/clinical/compare'
import { DELIVERED_STEPS, STEP_TITLES } from '@/domain/clinical/labels'
import type { ClinicalRevisionDetail, Consultation, VisualAcuityScale } from '@/domain/contracts/generated'

import ConsultationReadMessage from './ConsultationReadMessage.vue'
import { useClinicalRead } from './useClinicalRead'

const props = defineProps<{ consultationId: string }>()

const consultationRead = useClinicalRead<Consultation>(() => getConsultation(props.consultationId))
const scalesRead = useClinicalRead<VisualAcuityScale[]>(() => getVisualAcuityScales())

type Loaded =
  | { state: 'loading' }
  | { state: 'ready'; detail: ClinicalRevisionDetail }
  | { state: 'error'; message: string }

const loaded = shallowRef<Readonly<Record<number, Loaded>>>({})
const leftNo = ref<number | null>(null)
const rightNo = ref<number | null>(null)
const onlyDifferences = ref(true)

const consultation = computed(() => consultationRead.data.value)
const revisions = computed(() => consultation.value?.revisions ?? [])
const scales = computed(() => scalesRead.data.value ?? [])

watch(
  consultation,
  (value) => {
    if (value === null || rightNo.value !== null) return
    const list = value.revisions
    const last = list[list.length - 1]
    const before = list[list.length - 2]
    rightNo.value = last?.revision_no ?? null
    leftNo.value = before?.revision_no ?? null
  },
  { immediate: true },
)

function message(error: unknown): string {
  if (error instanceof NetworkUnavailable) {
    return 'Le serveur n’a pas répondu : cette révision n’a pas pu être lue. Aucune ancienne copie n’est présentée.'
  }
  if (error instanceof ApiError) return error.message
  return 'Une erreur inattendue est survenue.'
}

async function ensure(revisionNo: number | null): Promise<void> {
  if (revisionNo === null) return
  const known = loaded.value[revisionNo]
  if (known !== undefined && known.state !== 'error') return
  loaded.value = { ...loaded.value, [revisionNo]: { state: 'loading' } }
  try {
    const result = await getRevision(props.consultationId, revisionNo)
    loaded.value = { ...loaded.value, [revisionNo]: { state: 'ready', detail: result.data } }
  } catch (error) {
    loaded.value = { ...loaded.value, [revisionNo]: { state: 'error', message: message(error) } }
  }
}

watch([leftNo, rightNo], ([left, right]) => {
  void ensure(left)
  void ensure(right)
}, { immediate: true })

function detailOf(revisionNo: number | null): ClinicalRevisionDetail | null {
  if (revisionNo === null) return null
  const entry = loaded.value[revisionNo]
  return entry?.state === 'ready' ? entry.detail : null
}

function stateOf(revisionNo: number | null): Loaded | null {
  return revisionNo === null ? null : (loaded.value[revisionNo] ?? null)
}

function retry(revisionNo: number | null): void {
  void ensure(revisionNo)
}

const selected = computed(() =>
  [leftNo.value, rightNo.value].filter((value): value is number => value !== null),
)
const loadingAny = computed(() => selected.value.some((no) => stateOf(no)?.state === 'loading'))
const failures = computed(() =>
  selected.value.flatMap((revisionNo) => {
    const entry = stateOf(revisionNo)
    return entry?.state === 'error' ? [{ revisionNo, message: entry.message }] : []
  }),
)

const left = computed(() => detailOf(leftNo.value))
const right = computed(() => detailOf(rightNo.value))

function scaleLabel(scaleId: string): string {
  return scales.value.find((scale) => scale.id === scaleId)?.label ?? 'échelle inconnue'
}

function stepsOf(detail: ClinicalRevisionDetail): StepsData {
  return detail.snapshot.steps as unknown as StepsData
}

/** Comparaison de deux révisions, ou description de la seule révision choisie. */
const rows = computed<ComparisonRow[]>(() => {
  if (right.value === null) return []
  if (left.value === null) {
    if (leftNo.value !== null) return []
    // Une seule révision : son contenu, sans colonne de référence.
    return describeSteps(stepsOf(right.value), scaleLabel).map((fact) => ({
      step: fact.step,
      key: fact.key,
      label: fact.label,
      left: '',
      right: fact.value,
      kind: 'same' as const,
      note: null,
    }))
  }
  return compareSteps(stepsOf(left.value), stepsOf(right.value), scaleLabel).rows
})

const comparing = computed(() => leftNo.value !== null)
const differences = computed(() => rows.value.filter((row) => row.kind !== 'same').length)
const visibleRows = computed(() =>
  comparing.value && onlyDifferences.value ? rows.value.filter((row) => row.kind !== 'same') : rows.value,
)
const stepsWithRows = computed(() =>
  DELIVERED_STEPS.map((step) => ({ step, rows: visibleRows.value.filter((row) => row.step === step) })).filter(
    (group) => group.rows.length > 0,
  ),
)

const options = computed(() =>
  revisions.value.map((revision) => ({
    value: String(revision.revision_no),
    label: `Révision ${revision.revision_no} · ${formatInstantFr(revision.recorded_at)}`,
  })),
)

function choose(which: 'left' | 'right', value: string | null): void {
  const next = value === null ? null : Number(value)
  if (which === 'left') leftNo.value = next
  else if (next !== null) rightNo.value = next
}

function authorName(detail: ClinicalRevisionDetail | null): string {
  return detail?.snapshot.author.display_name ?? 'auteur inconnu'
}

function shortHash(hash: string): string {
  return hash.slice(0, 12)
}
</script>

<template>
  <section aria-labelledby="titre-revisions" class="max-w-4xl space-y-6">
    <h1 id="titre-revisions" class="text-2xl font-semibold tracking-tight">
      Révisions de la consultation
    </h1>

    <ConsultationReadMessage
      v-if="consultation === null"
      :state="consultationRead.state.value"
      subject="consultation"
      :error="consultationRead.error.value"
      @retry="consultationRead.reload()"
    />

    <template v-else>
      <p class="flex flex-wrap gap-3">
        <LinkButton :to="{ name: 'consultation', params: { id: props.consultationId } }" variant="secondary"
          >Retour à la consultation</LinkButton
        >
      </p>

      <StateMessage
        v-if="revisions.length === 0"
        state="empty"
        title="Aucune révision enregistrée"
      >
        Cet examen n’est pas terminé : la première révision est figée quand on le termine. Les
        révisions suivantes sont créées par les corrections motivées.
      </StateMessage>

      <template v-else>
        <p class="max-w-prose text-base text-muted">
          Chaque révision est figée telle qu’enregistrée : elle ne change jamais. Choisissez deux
          révisions pour les comparer ; une valeur absente est « non renseigné » ou « non mesuré »,
          jamais « aucun ».
        </p>

        <ol class="space-y-2">
          <li
            v-for="revision in revisions"
            :key="revision.id"
            class="rounded-lg border-2 border-line bg-surface p-3 text-base"
          >
            <p class="font-semibold">
              Révision {{ revision.revision_no }}
              <span class="font-normal text-muted">· {{ formatInstantFr(revision.recorded_at) }}</span>
            </p>
            <p>Motif : {{ revision.reason }}</p>
            <p class="text-sm text-muted">
              Empreinte SHA-256 : <span class="tabular-nums">{{ shortHash(revision.sha256) }}…</span>
            </p>
          </li>
        </ol>

        <div class="grid gap-4 sm:grid-cols-2">
          <SelectField
            :model-value="leftNo === null ? null : String(leftNo)"
            label="Révision de référence"
            placeholder="Aucune (voir une seule révision)"
            :options="options"
            @update:model-value="choose('left', $event)"
          />
          <SelectField
            :model-value="rightNo === null ? null : String(rightNo)"
            label="Révision à comparer"
            placeholder="Choisir une révision…"
            :options="options"
            required
            @update:model-value="choose('right', $event)"
          />
        </div>

        <StateMessage v-if="loadingAny" state="loading" title="Chargement de la révision…" />
        <StateMessage
          v-for="failure in failures"
          :key="failure.revisionNo"
          state="offline"
          title="Révision indisponible"
        >
          Révision {{ failure.revisionNo }} : {{ failure.message }}
          <template #action>
            <button
              type="button"
              class="min-h-11 rounded-lg border-2 border-primary bg-surface px-4 py-2 text-base font-semibold text-primary"
              @click="retry(failure.revisionNo)"
            >
              Réessayer
            </button>
          </template>
        </StateMessage>

        <div v-if="right !== null && (!comparing || left !== null)" class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <p v-if="comparing && left" class="rounded-lg border-2 border-line bg-surface p-3 text-sm">
              <strong>Référence : révision {{ left.revision_no }}</strong><br />
              Enregistrée par {{ authorName(left) }} le {{ formatInstantFr(left.recorded_at) }}.
              <br />Motif : {{ left.reason }}
            </p>
            <p class="rounded-lg border-2 border-line bg-surface p-3 text-sm">
              <strong>{{ comparing ? 'Comparée' : 'Révision' }} : révision {{ right.revision_no }}</strong
              ><br />
              Enregistrée par {{ authorName(right) }} le {{ formatInstantFr(right.recorded_at) }}.
              <br />Motif : {{ right.reason }}
            </p>
          </div>

          <p v-if="comparing" role="status" class="text-base font-medium text-ink">
            {{ differences === 0 ? 'Aucune différence entre ces deux révisions.' : `${differences} différence(s) entre ces deux révisions.` }}
          </p>
          <label v-if="comparing" class="flex min-h-11 items-center gap-3 text-base">
            <input v-model="onlyDifferences" type="checkbox" class="size-5" />
            Afficher seulement les différences
          </label>

          <section v-for="group in stepsWithRows" :key="group.step" class="space-y-2">
            <h2 class="text-lg font-semibold">{{ STEP_TITLES[group.step] }}</h2>
            <ul class="space-y-2">
              <li
                v-for="row in group.rows"
                :key="`${row.step}-${row.key}`"
                :class="[
                  'rounded-lg border-2 bg-surface p-3',
                  row.kind === 'same' ? 'border-line' : 'border-primary',
                ]"
              >
                <dl class="grid gap-2 sm:grid-cols-[minmax(8rem,1fr)_2fr_2fr] sm:gap-4">
                  <div>
                    <dt class="font-semibold">{{ row.label }}</dt>
                    <dd v-if="comparing" class="text-sm font-medium">
                      <span aria-hidden="true">{{ CHANGE_SYMBOLS[row.kind] }} </span
                      >{{ CHANGE_LABELS[row.kind] }}
                    </dd>
                  </div>
                  <div v-if="comparing">
                    <dt class="text-sm text-muted">Révision {{ left?.revision_no }}</dt>
                    <dd class="break-words">{{ row.left }}</dd>
                  </div>
                  <div>
                    <dt class="text-sm text-muted">Révision {{ right.revision_no }}</dt>
                    <dd class="break-words">{{ row.right }}</dd>
                  </div>
                </dl>
                <p v-if="row.note" class="mt-2 text-sm font-medium text-warning">
                  <span aria-hidden="true">⚠ </span>{{ row.note }}
                </p>
              </li>
            </ul>
          </section>
        </div>
      </template>
    </template>
  </section>
</template>
