<script setup lang="ts">
/**
 * Comparaison de deux examens d'un même patient (L1D08 ; fichier 02 « Historique » ; AT21) : rubriques de la
 * révision ACTUELLE de chaque examen terminé (mesures OD/OG, échelles, valeurs non mesurées) et dernière
 * prescription validée de chacun. Une acuité garde son échelle : deux échelles différentes sont « non
 * comparables », jamais converties. Lecture seule ; chaque lecture de dossier est tracée par le serveur.
 *
 * Les examens proposés viennent de l'activité du patient (section EXAMS : examens terminés, y compris ceux des
 * fiches fusionnées dans celle-ci). L'adresse peut désigner la paire (`?reference=…&compare=…`, identifiants
 * opaques seulement) ; sinon l'avant-dernier examen sert de référence et le dernier est comparé.
 */
import { computed, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { formatInstantFr } from '@/app/clinicTime'
import { prescriptionScreenModel } from '@/app/services/clinicalDocuments'
import {
  getConsultation,
  getConsultationPrescriptions,
  getRevision,
  getVisualAcuityScales,
} from '@/app/services/consultations'
import { getPatient, listPatientActivity } from '@/app/services/patients'
import StateMessage from '@/components/feedback/StateMessage.vue'
import SelectField from '@/components/form/SelectField.vue'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import {
  CHANGE_LABELS,
  CHANGE_SYMBOLS,
  compareSteps,
  type ComparisonRow,
  type StepsData,
} from '@/domain/clinical/compare'
import { DELIVERED_STEPS, STEP_TITLES } from '@/domain/clinical/labels'
import {
  compareFacts,
  completedExams,
  defaultPair,
  type FactRow,
  latestValidated,
  prescriptionFacts,
} from '@/domain/clinical/visitCompare'
import type {
  Patient,
  PatientActivityConsultation,
  PatientActivityItem,
  Prescription,
  VisualAcuityScale,
} from '@/domain/contracts/generated'

/** Pages d'activité lues au plus (25 éléments par page) : au-delà, la liste est dite partielle. */
const MAX_PAGES = 8

const route = useRoute()
const patientId = computed(() => String(route.params['id']))

function queryId(name: string): string | null {
  const value = route.query[name]
  return typeof value === 'string' && /^[0-9A-HJKMNP-TV-Z]{26}$/.test(value) ? value : null
}

function failure(error: unknown): string {
  if (error instanceof NetworkUnavailable) {
    return 'Le serveur n’a pas répondu : rien n’est affiché, aucune ancienne copie n’est présentée.'
  }
  if (error instanceof ApiError) return error.message
  return 'Une erreur inattendue est survenue.'
}

const patient = shallowRef<Patient | null>(null)
const exams = shallowRef<PatientActivityConsultation[]>([])
const truncated = ref(false)
const listState = ref<'loading' | 'ready' | 'error'>('loading')
const listError = ref<string | null>(null)
const scales = shallowRef<VisualAcuityScale[]>([])

const leftId = ref<string | null>(null)
const rightId = ref<string | null>(null)
const onlyDifferences = ref(true)

interface ExamSide {
  state: 'loading' | 'ready' | 'error'
  steps: StepsData | null
  revisionNo: number | null
  prescription: Prescription | null
  message: string | null
}

const sides = shallowRef<Readonly<Record<string, ExamSide>>>({})

async function loadList(): Promise<void> {
  listState.value = 'loading'
  listError.value = null
  try {
    const [patientRead] = await Promise.all([
      getPatient(patientId.value),
      getVisualAcuityScales()
        .then((result) => {
          scales.value = result.data
        })
        .catch(() => undefined),
    ])
    patient.value = patientRead.data.id === patientId.value ? patientRead.data : null
    const items: PatientActivityItem[] = []
    let more = false
    for (let page = 1; page <= MAX_PAGES; page += 1) {
      const result = await listPatientActivity(patientId.value, 'EXAMS', page)
      items.push(...result.data.data)
      if (result.data.meta.current_page >= result.data.meta.last_page) break
      if (page === MAX_PAGES) more = true
    }
    exams.value = completedExams(items)
    truncated.value = more
    const pair = defaultPair(exams.value, {
      left: queryId('reference'),
      right: queryId('compare'),
    })
    leftId.value = pair.left
    rightId.value = pair.right
    listState.value = 'ready'
  } catch (error) {
    listError.value = failure(error)
    listState.value = 'error'
  }
}

async function ensure(consultationId: string | null): Promise<void> {
  if (consultationId === null) return
  const known = sides.value[consultationId]
  if (known !== undefined && known.state !== 'error') return
  const loading: ExamSide = {
    state: 'loading',
    steps: null,
    revisionNo: null,
    prescription: null,
    message: null,
  }
  sides.value = { ...sides.value, [consultationId]: loading }
  try {
    const consultation = await getConsultation(consultationId)
    const revisionNo = consultation.data.current_revision
    const [revision, prescriptions] = await Promise.all([
      getRevision(consultationId, revisionNo),
      getConsultationPrescriptions(consultationId),
    ])
    const ready: ExamSide = {
      state: 'ready',
      steps: revision.data.snapshot.steps as unknown as StepsData,
      revisionNo,
      prescription: latestValidated(prescriptions.data),
      message: null,
    }
    sides.value = { ...sides.value, [consultationId]: ready }
  } catch (error) {
    const failed: ExamSide = { ...loading, state: 'error', message: failure(error) }
    sides.value = { ...sides.value, [consultationId]: failed }
  }
}

watch(patientId, () => void loadList(), { immediate: true })
watch(
  [leftId, rightId],
  ([left, right]) => {
    void ensure(left)
    void ensure(right)
  },
  { immediate: true },
)

const examOptions = computed(() =>
  exams.value.map((exam) => ({
    value: exam.id,
    label: `${formatInstantFr(exam.occurred_at)} · ${exam.clinician_name}`,
  })),
)

const left = computed(() => (leftId.value === null ? null : (sides.value[leftId.value] ?? null)))
const right = computed(() => (rightId.value === null ? null : (sides.value[rightId.value] ?? null)))
const sameExam = computed(() => leftId.value !== null && leftId.value === rightId.value)
const ready = computed(
  () => !sameExam.value && left.value?.state === 'ready' && right.value?.state === 'ready',
)

function scaleLabel(scaleId: string): string {
  return scales.value.find((scale) => scale.id === scaleId)?.label ?? 'échelle inconnue'
}

const stepRows = computed<ComparisonRow[]>(() => {
  const before = left.value?.steps
  const after = right.value?.steps
  if (!ready.value || !before || !after) return []
  return compareSteps(before, after, scaleLabel).rows
})

/** Rubriques dans l'ordre du dossier, avec leurs lignes (filtrées si « seulement les différences »). */
const stepGroups = computed(() =>
  DELIVERED_STEPS.map((step) => ({
    step,
    title: STEP_TITLES[step],
    rows: stepRows.value.filter(
      (row) => row.step === step && (!onlyDifferences.value || row.kind !== 'same'),
    ),
  })).filter((group) => group.rows.length > 0),
)

const prescriptionComparison = computed(() => {
  if (!ready.value) return { rows: [] as FactRow[], differences: 0 }
  const facts = (prescription: Prescription | null | undefined) =>
    prescription ? prescriptionFacts(prescriptionScreenModel(prescription)) : null
  return compareFacts(facts(left.value?.prescription), facts(right.value?.prescription))
})
const prescriptionRows = computed(() =>
  prescriptionComparison.value.rows.filter((row) => !onlyDifferences.value || row.kind !== 'same'),
)
const differences = computed(
  () =>
    stepRows.value.filter((row) => row.kind !== 'same').length +
    prescriptionComparison.value.differences,
)
const incomparable = computed(() => stepRows.value.filter((row) => row.note !== null).length)

function examLabel(id: string | null): string {
  const exam = exams.value.find((entry) => entry.id === id)
  return exam ? formatInstantFr(exam.occurred_at) : ''
}

const KIND_CLASSES: Readonly<Record<ComparisonRow['kind'], string>> = {
  same: 'text-muted',
  changed: 'text-warning font-semibold',
  added: 'text-accent font-semibold',
  removed: 'text-danger font-semibold',
}
</script>

<template>
  <section aria-labelledby="titre-comparaison" class="max-w-5xl space-y-6">
    <header class="space-y-2">
      <h1 id="titre-comparaison" class="text-2xl font-semibold tracking-tight">
        Comparer deux examens
      </h1>
      <p class="max-w-prose text-muted">
        Révision actuelle de chaque examen terminé et dernière prescription validée. Une acuité est
        toujours lue avec son échelle : deux échelles différentes ne sont jamais converties.
      </p>
    </header>

    <PatientIdentityHeader
      v-if="patient"
      :patient="patient"
      as="h2"
      title-id="comparaison-patient"
    />

    <StateMessage v-if="listState === 'loading'" state="loading" title="Lecture des examens…" />
    <StateMessage v-else-if="listState === 'error'" state="offline" title="Examens non lus">
      {{ listError }}
    </StateMessage>

    <template v-else>
      <StateMessage v-if="exams.length < 2" state="empty" title="Pas assez d’examens terminés">
        Il faut au moins deux examens terminés pour ce patient (fiches fusionnées comprises).
      </StateMessage>

      <template v-else>
        <p v-if="truncated" class="text-sm text-muted">
          Seuls les examens les plus récents sont proposés : la liste complète est plus longue.
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
          <SelectField
            v-model="leftId"
            label="Examen de référence"
            :options="examOptions"
            control-id="comparaison-reference"
          />
          <SelectField
            v-model="rightId"
            label="Examen comparé"
            :options="examOptions"
            control-id="comparaison-compare"
          />
        </div>

        <StateMessage v-if="sameExam" state="empty" title="Même examen choisi deux fois">
          Choisissez deux examens différents.
        </StateMessage>

        <StateMessage
          v-if="!sameExam && left?.state === 'error'"
          state="offline"
          title="Examen de référence non lu"
        >
          {{ left.message }}
        </StateMessage>
        <StateMessage
          v-if="!sameExam && right?.state === 'error'"
          state="offline"
          title="Examen comparé non lu"
        >
          {{ right.message }}
        </StateMessage>
        <StateMessage
          v-if="!sameExam && (left?.state === 'loading' || right?.state === 'loading')"
          state="loading"
          title="Lecture des deux examens…"
        />

        <template v-if="ready">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p role="status" class="font-medium">
              <template v-if="differences === 0"
                >Aucune différence entre les deux examens.</template
              >
              <template v-else>{{ differences }} différence(s)</template>
              <template v-if="incomparable > 0">
                · {{ incomparable }} acuité(s) d’échelles différentes, non comparées</template
              >
            </p>
            <label class="flex min-h-11 items-center gap-3 text-base">
              <input v-model="onlyDifferences" type="checkbox" class="size-5 accent-primary" />
              Seulement les différences
            </label>
          </div>

          <section
            v-for="group in stepGroups"
            :key="group.step"
            :aria-labelledby="`comparaison-${group.step}`"
            class="overflow-x-auto rounded-xl border border-line bg-surface shadow-card"
          >
            <h2 :id="`comparaison-${group.step}`" class="px-4 pt-4 text-lg font-semibold">
              {{ group.title }}
            </h2>
            <table class="mt-2 w-full min-w-[36rem] text-left text-sm">
              <caption class="sr-only">
                {{
                  group.title
                }}
                : examen du
                {{
                  examLabel(leftId)
                }}
                comparé à l’examen du
                {{
                  examLabel(rightId)
                }}
              </caption>
              <thead class="bg-primary-soft">
                <tr>
                  <th scope="col" class="px-4 py-2 font-semibold">Élément</th>
                  <th scope="col" class="px-4 py-2 font-semibold">{{ examLabel(leftId) }}</th>
                  <th scope="col" class="px-4 py-2 font-semibold">{{ examLabel(rightId) }}</th>
                  <th scope="col" class="px-4 py-2 font-semibold">Écart</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in group.rows" :key="row.key" class="border-t border-line align-top">
                  <th scope="row" class="px-4 py-2 font-medium">{{ row.label }}</th>
                  <td class="px-4 py-2 break-words tabular-nums">{{ row.left }}</td>
                  <td class="px-4 py-2 break-words tabular-nums">{{ row.right }}</td>
                  <td class="px-4 py-2">
                    <span :class="KIND_CLASSES[row.kind]"
                      ><span aria-hidden="true">{{ CHANGE_SYMBOLS[row.kind] }} </span
                      >{{ CHANGE_LABELS[row.kind] }}</span
                    >
                    <span v-if="row.note" class="block text-warning">{{ row.note }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </section>

          <section
            aria-labelledby="comparaison-prescription"
            class="overflow-x-auto rounded-xl border border-line bg-surface shadow-card"
          >
            <h2 id="comparaison-prescription" class="px-4 pt-4 text-lg font-semibold">
              Prescription validée
            </h2>
            <p
              v-if="!left?.prescription || !right?.prescription"
              class="px-4 pt-1 text-sm text-muted"
            >
              <template v-if="!left?.prescription && !right?.prescription"
                >Aucun des deux examens n’a de prescription validée.</template
              >
              <template v-else-if="!left?.prescription"
                >L’examen de référence n’a pas de prescription validée.</template
              >
              <template v-else>L’examen comparé n’a pas de prescription validée.</template>
            </p>
            <table
              v-if="prescriptionRows.length > 0"
              class="mt-2 w-full min-w-[36rem] text-left text-sm"
            >
              <caption class="sr-only">
                Prescriptions validées des deux examens
              </caption>
              <thead class="bg-primary-soft">
                <tr>
                  <th scope="col" class="px-4 py-2 font-semibold">Élément</th>
                  <th scope="col" class="px-4 py-2 font-semibold">
                    {{ examLabel(leftId) }}
                    <template v-if="left?.prescription">
                      (version {{ left.prescription.version_no }})</template
                    >
                  </th>
                  <th scope="col" class="px-4 py-2 font-semibold">
                    {{ examLabel(rightId) }}
                    <template v-if="right?.prescription">
                      (version {{ right.prescription.version_no }})</template
                    >
                  </th>
                  <th scope="col" class="px-4 py-2 font-semibold">Écart</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in prescriptionRows"
                  :key="row.key"
                  class="border-t border-line align-top"
                >
                  <th scope="row" class="px-4 py-2 font-medium">{{ row.label }}</th>
                  <td class="px-4 py-2 break-words tabular-nums">{{ row.left }}</td>
                  <td class="px-4 py-2 break-words tabular-nums">{{ row.right }}</td>
                  <td class="px-4 py-2">
                    <span :class="KIND_CLASSES[row.kind]"
                      ><span aria-hidden="true">{{ CHANGE_SYMBOLS[row.kind] }} </span
                      >{{ CHANGE_LABELS[row.kind] }}</span
                    >
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-else class="px-4 py-3 text-sm text-muted">Aucune différence de prescription.</p>
          </section>

          <div class="flex flex-wrap gap-3">
            <LinkButton
              v-if="leftId"
              :to="{ name: 'consultation', params: { id: leftId } }"
              variant="secondary"
              >Ouvrir l’examen de référence</LinkButton
            >
            <LinkButton
              v-if="rightId"
              :to="{ name: 'consultation', params: { id: rightId } }"
              variant="secondary"
              >Ouvrir l’examen comparé</LinkButton
            >
          </div>
        </template>
      </template>
    </template>

    <LinkButton :to="{ name: 'patient', params: { id: patientId } }" variant="ghost"
      >Retour à la fiche</LinkButton
    >
  </section>
</template>
