<script setup lang="ts">
/**
 * Consultation par étapes (C01 ; fichier 02, « Consultation et antécédents » ; fichier 04,
 * « Consultation guidée » ; AT18, AT19). Première tranche, EN LIGNE : motif et lunettes, antécédents,
 * conclusion et orientation. Acuités, réfraction et centrage arrivent avec C02, la finalisation et la
 * correction avec C03 : l'écran le dit et ne propose NI « Terminer » NI faux état « Terminé ».
 *
 * - En-tête patient fixe sur écran large (identité, dossier, date et heure, professionnel) ; la saisie
 *   ne s'ouvre qu'une fois le patient identifié.
 * - Précédent / Enregistrer / Suivant : « Suivant » valide et enregistre l'étape avant d'avancer. Une
 *   étape n'est dite enregistrée qu'après la réponse du serveur (« Enregistré sur le serveur à
 *   10h42 ») ; rien n'est dit « enregistré sur ce poste ».
 * - Conflit de version (409) : saisie conservée, « Recharger la consultation » la rebase sur la
 *   version actuelle et signale les valeurs modifiées des deux côtés.
 * - Sans le droit d'écriture (clinical.write) : lecture seule, aucun bouton d'enregistrement.
 * - Hors ligne : « Consultation indisponible », jamais une consultation vide.
 * Une instance par consultation (l'écran la recrée quand l'adresse change).
 */
import { computed, nextTick, ref, watch } from 'vue'

import { clinicTimeZone } from '@/app/clinicTime'
import { useUnsavedChangesGuard } from '@/app/unsavedChanges'
import ErrorSummary from '@/components/feedback/ErrorSummary.vue'
import SaveStatus from '@/components/feedback/SaveStatus.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'
import {
  DELIVERED_STEPS,
  NEXT_VERSION_NOTICE,
  REFERRAL_BADGE,
  STEP_TITLES,
  urgencyLabel,
  workStatusSymbol,
} from '@/domain/clinical/labels'
import { CONTROL_IDS } from '@/domain/clinical/controls'

import AnamnesisStep from './AnamnesisStep.vue'
import ConclusionStep from './ConclusionStep.vue'
import ConsultationReadMessage from './ConsultationReadMessage.vue'
import HistoriesStep from './HistoriesStep.vue'
import PatientBanner from './PatientBanner.vue'
import { useConsultationEditor } from './useConsultationEditor'

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
  readOnly,
  busy,
  dirtySteps,
  currentDirty,
  anyDirty,
  stepIndex,
  isFirst,
  isLast,
  failure,
  saveStatus,
  statusLabel,
} = editor
const { failureMessage, recordedAt, state } = mutation

/** L'identité du patient est lue : la saisie peut s'ouvrir. */
const identified = ref(false)
const heading = ref<HTMLElement | null>(null)
const timeZone = clinicTimeZone()

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
const pendingStepTitle = computed(() =>
  pending.value === null ? '' : STEP_TITLES[pending.value.step],
)
const completed = computed(() => baseline.value?.status === 'COMPLETED')

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
      <PatientBanner :consultation="baseline" @identity="identified = $event !== null">
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

      <StateMessage v-if="readOnly && !completed" state="empty" title="Lecture seule">
        Ce compte peut consulter l’examen mais pas le modifier.
      </StateMessage>
      <StateMessage v-else-if="completed" state="empty" title="Examen terminé">
        La modification d’un examen terminé n’est pas disponible dans cette version.
      </StateMessage>

      <template v-if="identified">
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
          <template v-if="pendingStepTitle">Étape « {{ pendingStepTitle }} » : </template
          >{{ failureMessage }}
          <template v-if="failure.retry" #action>
            <AppButton variant="secondary" :pending="busy" @click="editor.retry"
              >Réessayer l’enregistrement</AppButton
            >
          </template>
        </StateMessage>

        <nav aria-label="Étapes de la consultation">
          <ol class="grid gap-2 sm:grid-cols-3">
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

        <form class="space-y-6" novalidate @submit.prevent="save">
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

          <div class="flex flex-wrap items-center gap-3">
            <AppButton variant="secondary" :disabled="busy || isFirst" @click="editor.previous"
              >Précédent</AppButton
            >
            <template v-if="!readOnly">
              <AppButton
                id="consultation-enregistrer"
                type="submit"
                :variant="isLast ? 'primary' : 'secondary'"
                :pending="saving && pending !== null && !pending.advance"
                pending-label="Enregistrement en cours…"
                :disabled="busy || needsReload"
                >Enregistrer</AppButton
              >
              <AppButton
                v-if="!isLast"
                variant="primary"
                :pending="saving && pending !== null && pending.advance"
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
          <p v-if="!readOnly" class="max-w-prose text-sm text-muted">
            « Suivant » enregistre l’étape sur le serveur avant de passer à la suivante.
          </p>
        </form>

        <aside
          aria-label="Étapes non disponibles dans cette version"
          class="rounded-lg border-2 border-line bg-surface p-3 text-base text-ink"
        >
          <p><span aria-hidden="true">○ </span>{{ NEXT_VERSION_NOTICE }}</p>
        </aside>
      </template>
    </template>
  </section>
</template>
