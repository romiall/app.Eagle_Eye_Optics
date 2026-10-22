<script setup lang="ts">
/**
 * Consultation par étapes (C01 à C04 ; fichier 02, « Consultation et antécédents », « Examens et réfraction »,
 * « Médicaments et orientation » ; fichier 04, « Consultation guidée » ; AT18, AT19). EN LIGNE : motif et
 * lunettes, antécédents, acuité visuelle, réfraction, centrage, médicaments, conclusion et orientation, puis
 * « Terminer l'examen » (révision 1 figée) et « Corriger » (nouvelle révision motivée).
 *
 * - En-tête patient fixe sur écran large (identité, dossier, date et heure, professionnel), lu dans la
 *   consultation elle-même.
 * - Précédent / Enregistrer / Suivant : « Suivant » valide et enregistre l'étape avant d'avancer. Une
 *   étape n'est dite enregistrée qu'après la réponse du serveur (« Enregistré sur le serveur à
 *   10h42 ») ; rien n'est dit « enregistré sur ce poste ».
 * - Conflit de version (409) : saisie conservée, « Recharger la consultation » la rebase sur la
 *   version actuelle et signale les valeurs modifiées des deux côtés.
 * - Terminer l'examen : confirmation, récapitulatif des rubriques non renseignées (jamais transformées en
 *   « Aucun »), justification d'absence si le motif/les plaintes ou la conclusion manquent.
 * - Examen terminé : lecture seule, avec l'historique des révisions et la correction motivée (`clinical.amend`).
 * - Sans le droit d'écriture (clinical.write) : lecture seule, aucun bouton d'enregistrement.
 * - Hors ligne : « Consultation indisponible », jamais une consultation vide.
 * Une instance par consultation (l'écran la recrée quand l'adresse change).
 */
import { computed, nextTick, ref, watch } from 'vue'

import { clinicTimeZone, formatInstantFr } from '@/app/clinicTime'
import { useUnsavedChangesGuard } from '@/app/unsavedChanges'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import ErrorSummary from '@/components/feedback/ErrorSummary.vue'
import SaveStatus from '@/components/feedback/SaveStatus.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import { missingDataText } from '@/domain/clinical/completion'
import { CONTROL_IDS } from '@/domain/clinical/controls'
import {
  DELIVERED_STEPS,
  REFERRAL_BADGE,
  STEP_TITLES,
  urgencyLabel,
  workStatusSymbol,
} from '@/domain/clinical/labels'

import AnamnesisStep from './AnamnesisStep.vue'
import CenteringStep from './CenteringStep.vue'
import ConclusionStep from './ConclusionStep.vue'
import ConsultationDocuments from './ConsultationDocuments.vue'
import ConsultationReadMessage from './ConsultationReadMessage.vue'
import HistoriesStep from './HistoriesStep.vue'
import MedicationsStep from './MedicationsStep.vue'
import PatientBanner from './PatientBanner.vue'
import RefractionStep from './RefractionStep.vue'
import { AMEND_REASON_ID, FINISH_REASON_ID, useConsultationEditor } from './useConsultationEditor'
import VisualAcuityStep from './VisualAcuityStep.vue'

const props = defineProps<{ consultationId: string }>()

const editor = useConsultationEditor(props.consultationId)
const {
  read,
  mutation,
  baseline,
  drafts,
  current,
  errorMap,
  summary,
  summaryBox,
  notice,
  announcement,
  needsReload,
  reloading,
  rebased,
  pending,
  operation,
  operationTitle,
  completed,
  canAmend,
  readOnly,
  busy,
  scales,
  scalesReady,
  dirtySteps,
  currentDirty,
  anyDirty,
  stepIndex,
  isFirst,
  isLast,
  failure,
  saveStatus,
  statusLabel,
  completionRecap,
  finishBlocked,
  canFinish,
  finishOpen,
  finishReason,
  finishError,
  amending,
  amendReason,
  amendReasonError,
} = editor
const { failureMessage, recordedAt, state } = mutation

const heading = ref<HTMLElement | null>(null)
const timeZone = clinicTimeZone()
const confirmingCancel = ref(false)

useUnsavedChangesGuard(anyDirty)

// Le titre de l'étape reçoit le focus à chaque changement d'étape (annoncé aux lecteurs d'écran).
watch(current, async () => {
  await nextTick()
  heading.value?.focus()
})

/** Une relecture a échoué alors que la consultation est déjà affichée : le message et « Réessayer ». */
const readProblem = computed(
  () => baseline.value !== null && read.state.value !== 'ready' && read.state.value !== 'loading',
)

const referral = computed(() => {
  const conclusion = baseline.value?.steps.CONCLUSION
  if (conclusion === undefined || conclusion.referral !== true) return null
  return conclusion.referral_urgency === null ? null : urgencyLabel(conclusion.referral_urgency)
})
const referred = computed(() => baseline.value?.steps.CONCLUSION.referral === true)

const saving = computed(() => state.value === 'saving')
const savingStep = computed(() => saving.value && operation.value === null && pending.value !== null)
const readOnlyNotCompleted = computed(() => readOnly.value && !completed.value)
const finishDisabled = computed(() => busy.value || finishBlocked.value !== null)

/** Le résumé d'erreurs est focalisé par l'éditeur (clic « Enregistrer » ou « Suivant » refusé). */
function bindSummary(element: unknown): void {
  summaryBox.value = element as { focus: () => Promise<void> } | null
}

async function save(): Promise<void> {
  await editor.save()
  await nextTick()
  // Le bouton était désactivé pendant l'envoi : le focus lui est rendu (jamais perdu dans la page).
  if (document.activeElement === document.body) {
    document.getElementById('consultation-enregistrer')?.focus()
  }
}

async function onSubmit(): Promise<void> {
  if (amending.value) {
    await editor.submitAmend()
    return
  }
  await save()
}

function cancelAmend(): void {
  if (anyDirty.value) {
    confirmingCancel.value = true
    return
  }
  editor.cancelAmend()
}

function confirmCancelAmend(): void {
  confirmingCancel.value = false
  editor.cancelAmend()
}

const completedAt = computed(() => {
  const at = baseline.value?.completed_at
  return at === null || at === undefined ? null : formatInstantFr(at)
})
const revisionCount = computed(() => baseline.value?.revisions.length ?? 0)
</script>

<template>
  <section aria-labelledby="titre-consultation" class="max-w-3xl space-y-6">
    <h1 id="titre-consultation" class="text-2xl font-semibold tracking-tight">Consultation</h1>

    <ConsultationReadMessage
      v-if="baseline === null"
      :state="read.state.value"
      subject="consultation"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <template v-else>
      <PatientBanner :consultation="baseline">
        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
          <span class="inline-flex items-center gap-1 rounded-lg border-2 border-line px-2 py-1"
            ><span aria-hidden="true">{{ workStatusSymbol(baseline.status) }}</span
            ><span class="sr-only">État du travail : </span>{{ statusLabel }}</span
          >
          <span
            v-if="referred"
            class="inline-flex items-center gap-1 rounded-lg border-2 border-warning bg-warning-soft px-2 py-1"
            ><span aria-hidden="true">⚠</span>{{ REFERRAL_BADGE
            }}<template v-if="referral"
              ><span aria-hidden="true"> · </span><span class="sr-only">, niveau : </span
              >{{ referral }}</template
            ></span
          >
        </p>
      </PatientBanner>

      <ConsultationReadMessage
        v-if="readProblem"
        :state="read.state.value"
        subject="consultation"
        :error="read.error.value"
        @retry="editor.reloadFromServer"
      />

      <StateMessage v-if="readOnlyNotCompleted" state="empty" title="Lecture seule">
        Ce compte peut consulter l’examen mais pas le modifier.
      </StateMessage>
      <StateMessage v-else-if="completed && !amending" state="success" title="Examen terminé">
        <p>
          Terminé<template v-if="completedAt"> le {{ completedAt }}</template> ; révision
          {{ baseline.current_revision }} sur {{ revisionCount }} enregistrée(s). Les rubriques sont en
          lecture seule : une correction crée une nouvelle révision motivée, les précédentes restent
          lisibles.
        </p>
        <p v-if="baseline.finalization_missing_data_reason" class="mt-2">
          Justification d’absence enregistrée : {{ baseline.finalization_missing_data_reason }}
        </p>
        <template #action>
          <div class="flex flex-wrap gap-3">
            <LinkButton
              :to="{ name: 'consultation-historique', params: { id: props.consultationId } }"
              variant="secondary"
              >Révisions et comparaison</LinkButton
            >
            <LinkButton
              :to="{ name: 'consultation-prescriptions', params: { id: props.consultationId } }"
              variant="secondary"
              >Prescription optique</LinkButton
            >
            <AppButton v-if="canAmend" variant="primary" :disabled="busy" @click="editor.startAmend"
              >Corriger l’examen</AppButton
            >
          </div>
        </template>
      </StateMessage>
      <StateMessage v-else-if="amending" state="conflict" title="Correction en cours">
        Vous corrigez un examen terminé : vos modifications seront enregistrées ensemble comme la
        révision {{ baseline.current_revision + 1 }}, avec le motif ci-dessous. Les révisions
        précédentes ne changent pas.
      </StateMessage>

      <!-- Documents imprimables : examen terminé seulement, depuis la révision figée (jamais en correction). -->
      <ConsultationDocuments
        v-if="completed && !amending && !needsReload"
        :consultation="baseline"
        :scales="scales"
      />

      <div class="space-y-6">
        <StateMessage
          v-if="needsReload"
          state="conflict"
          title="La consultation a changé entre-temps"
        >
          Quelqu’un a modifié cette consultation pendant votre saisie. Vos saisies sont conservées.
          Rechargez la consultation : vos modifications seront reportées sur la version actuelle et
          les valeurs modifiées des deux côtés vous seront signalées avant tout nouvel
          enregistrement.
          <template #action>
            <AppButton
              variant="primary"
              :pending="reloading"
              pending-label="Rechargement…"
              @click="editor.reloadFromServer"
              >Recharger la consultation</AppButton
            >
          </template>
        </StateMessage>

        <StateMessage
          v-else-if="rebased"
          :state="rebased.conflicts.length > 0 ? 'conflict' : 'success'"
          :title="
            rebased.conflicts.length > 0
              ? 'Valeurs modifiées aussi par une autre personne'
              : 'Consultation rechargée'
          "
        >
          <p>
            La consultation a été relue (version {{ rebased.version }}) : vos modifications sont
            reportées dessus, les autres valeurs ont repris ce qui est enregistré.
          </p>
          <ul v-if="rebased.conflicts.length > 0" class="mt-2 list-disc space-y-1 pl-5">
            <li v-for="conflict in rebased.conflicts" :key="`${conflict.step}-${conflict.key}`">
              {{ STEP_TITLES[conflict.step] }} · {{ conflict.label }} : valeur actuelle «
              {{ conflict.current }} » ; votre saisie est conservée tant que vous ne la changez pas.
            </li>
          </ul>
        </StateMessage>

        <ErrorSummary :ref="bindSummary" :errors="summary" />

        <StateMessage v-if="failure" :state="failure.state" :title="failure.title">
          <template v-if="operationTitle">{{ operationTitle }} : </template>{{ failureMessage }}
          <template v-if="failure.retry" #action>
            <AppButton variant="secondary" :pending="busy" @click="editor.retry"
              >Réessayer l’enregistrement</AppButton
            >
          </template>
        </StateMessage>

        <nav aria-label="Étapes de la consultation">
          <ol class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="(step, index) in DELIVERED_STEPS" :key="step">
              <button
                type="button"
                :aria-current="step === current ? 'step' : undefined"
                :disabled="busy"
                :class="[
                  'flex min-h-11 w-full items-center gap-2 rounded-lg border-2 px-3 py-2 text-left text-base font-medium',
                  'disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-ink',
                  step === current
                    ? 'border-primary bg-primary-soft text-primary'
                    : 'border-line bg-surface text-ink hover:bg-primary-soft',
                ]"
                @click="editor.goTo(step)"
              >
                <span class="font-semibold">{{ index + 1 }}.</span>
                <span class="min-w-0 break-words">{{ STEP_TITLES[step] }}</span>
                <span v-if="dirtySteps.includes(step)" class="text-sm font-normal text-warning"
                  ><span aria-hidden="true">● </span>non enregistrée</span
                >
              </button>
            </li>
          </ol>
        </nav>

        <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

        <form class="space-y-6" novalidate @submit.prevent="onSubmit">
          <h2
            :id="CONTROL_IDS.step"
            ref="heading"
            tabindex="-1"
            class="text-xl font-semibold focus:outline-none"
          >
            Étape {{ stepIndex + 1 }} sur {{ DELIVERED_STEPS.length }} :
            {{ STEP_TITLES[current] }}
          </h2>

          <AnamnesisStep
            v-if="current === 'ANAMNESIS'"
            v-model="drafts.ANAMNESIS"
            :errors="errorMap"
            :disabled="readOnly || busy"
          />
          <HistoriesStep
            v-else-if="current === 'HISTORIES'"
            v-model="drafts.HISTORIES"
            :errors="errorMap"
            :disabled="readOnly || busy"
          />
          <VisualAcuityStep
            v-else-if="current === 'VISUAL_ACUITY'"
            v-model="drafts.VISUAL_ACUITY"
            :errors="errorMap"
            :disabled="readOnly || busy"
            :scales="scales"
            :scales-ready="scalesReady"
          />
          <RefractionStep
            v-else-if="current === 'REFRACTION'"
            v-model="drafts.REFRACTION"
            :errors="errorMap"
            :disabled="readOnly || busy"
          />
          <CenteringStep
            v-else-if="current === 'CENTERING'"
            v-model="drafts.CENTERING"
            :errors="errorMap"
            :disabled="readOnly || busy"
          />
          <MedicationsStep
            v-else-if="current === 'MEDICATIONS'"
            v-model="drafts.MEDICATIONS"
            :errors="errorMap"
            :disabled="readOnly || busy"
          />
          <ConclusionStep
            v-else
            v-model="drafts.CONCLUSION"
            :errors="errorMap"
            :disabled="readOnly || busy"
          />

          <p v-if="notice" role="status" class="font-medium text-ink">
            <span aria-hidden="true">○ </span>{{ notice }}
          </p>
          <p v-if="currentDirty" class="text-sm font-medium text-warning">
            <span aria-hidden="true">● </span>Modifications non enregistrées sur cette étape.
          </p>

          <section
            v-if="amending"
            aria-labelledby="titre-motif-correction"
            class="space-y-3 rounded-lg border-2 border-warning bg-warning-soft p-4"
          >
            <h3 id="titre-motif-correction" class="text-lg font-semibold">Motif de la correction</h3>
            <TextAreaField
              v-model="amendReason"
              label="Pourquoi corrigez-vous cet examen ?"
              hint="Obligatoire. Conservé avec la révision, lisible par les profils cliniques ; jamais recopié dans le journal d’audit."
              :control-id="AMEND_REASON_ID"
              :rows="3"
              :maxlength="1000"
              :error="amendReasonError"
              :disabled="busy"
              required
            />
          </section>

          <div class="flex flex-wrap items-center gap-3">
            <AppButton variant="secondary" :disabled="busy || isFirst" @click="editor.previous"
              >Précédent</AppButton
            >
            <template v-if="amending">
              <AppButton
                id="consultation-enregistrer"
                type="submit"
                variant="primary"
                :pending="saving && operation === 'amend'"
                pending-label="Enregistrement de la correction…"
                :disabled="busy || needsReload"
                >Enregistrer la correction</AppButton
              >
              <AppButton v-if="!isLast" variant="secondary" :disabled="busy" @click="editor.advance"
                >Suivant</AppButton
              >
              <AppButton variant="ghost" :disabled="busy" @click="cancelAmend"
                >Abandonner la correction</AppButton
              >
            </template>
            <template v-else-if="!readOnly">
              <AppButton
                id="consultation-enregistrer"
                type="submit"
                :variant="isLast ? 'primary' : 'secondary'"
                :pending="savingStep && pending !== null && !pending.advance"
                pending-label="Enregistrement en cours…"
                :disabled="busy || needsReload"
                >Enregistrer</AppButton
              >
              <AppButton
                v-if="!isLast"
                variant="primary"
                :pending="savingStep && pending !== null && pending.advance"
                pending-label="Enregistrement en cours…"
                :disabled="busy || needsReload"
                @click="editor.saveAndAdvance"
                >Suivant</AppButton
              >
            </template>
            <AppButton v-else-if="!isLast" variant="primary" @click="editor.advance"
              >Suivant</AppButton
            >
            <SaveStatus :state="saveStatus" :at="recordedAt" :time-zone="timeZone" />
          </div>
          <p v-if="!readOnly && !amending" class="max-w-prose text-sm text-muted">
            « Suivant » enregistre l’étape sur le serveur avant de passer à la suivante.
          </p>
        </form>

        <section
          v-if="canFinish"
          aria-labelledby="titre-terminer"
          class="space-y-3 rounded-lg border-2 border-line bg-surface p-4"
        >
          <h2 id="titre-terminer" class="text-lg font-semibold">Terminer l’examen</h2>
          <p class="max-w-prose text-base text-ink">
            Terminer fige la première révision de l’examen : les rubriques deviennent lisibles mais ne
            se modifient plus que par une correction motivée. Les rubriques non renseignées restent
            « non renseignées ».
          </p>
          <p v-if="finishBlocked" class="text-sm font-medium text-warning">
            <span aria-hidden="true">● </span>{{ finishBlocked }}
          </p>
          <AppButton variant="primary" :disabled="finishDisabled" @click="editor.openFinish"
            >Terminer l’examen</AppButton
          >
        </section>

        <!-- Monté seulement quand la finalisation est possible : aucun bouton « Terminer » caché ailleurs. -->
        <ConfirmDialog
          v-if="canFinish || finishOpen"
          :open="finishOpen"
          title="Terminer l’examen ?"
          confirm-label="Terminer l’examen"
          :pending="busy && operation === 'complete'"
          pending-label="Finalisation en cours…"
          initial-focus="#terminer-justification"
          @confirm="editor.confirmFinish"
          @cancel="editor.closeFinish"
        >
          <div v-if="completionRecap" class="space-y-3">
            <p>
              La première révision sera figée. Une correction ultérieure demandera un motif et créera une
              nouvelle révision.
            </p>
            <div>
              <p class="font-semibold">Rubriques non renseignées</p>
              <ul v-if="completionRecap.emptySteps.length > 0" class="list-disc pl-5">
                <li v-for="step in completionRecap.emptySteps" :key="step">
                  {{ STEP_TITLES[step] }} : non renseigné
                </li>
              </ul>
              <p v-else>Aucune : toutes les rubriques portent une réponse.</p>
              <p class="mt-1 text-sm text-muted">
                Une rubrique non renseignée n’est pas « aucun » : elle est figée telle quelle. Les
                mesures non réalisées ne sont pas exigées.
              </p>
            </div>
            <p v-if="completionRecap.reasonRequired" class="font-medium text-warning">
              <span aria-hidden="true">⚠ </span>Il manque {{ missingDataText(completionRecap) }} :
              justifiez leur absence ci-dessous.
            </p>
            <TextAreaField
              v-model="finishReason"
              :label="completionRecap.reasonRequired ? 'Justification d’absence' : 'Justification d’absence (facultative)'"
              :control-id="FINISH_REASON_ID"
              :rows="3"
              :maxlength="4000"
              :error="finishError"
              :disabled="busy"
              :required="completionRecap.reasonRequired"
            />
          </div>
        </ConfirmDialog>

        <ConfirmDialog
          v-if="amending"
          :open="confirmingCancel"
          title="Abandonner la correction ?"
          confirm-label="Abandonner la correction"
          tone="danger"
          @confirm="confirmCancelAmend"
          @cancel="confirmingCancel = false"
        >
          Les modifications non envoyées seront perdues. L’examen terminé reste inchangé.
        </ConfirmDialog>
      </div>
    </template>
  </section>
</template>
