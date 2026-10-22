import { computed, ref, shallowRef, watch } from 'vue'

import { getConsultation } from '@/app/services/consultations'
import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { canOnScreen, revalidateSession } from '@/app/session'
import type { FieldErrorItem } from '@/components/feedback/ErrorSummary.vue'
import type { Consultation } from '@/domain/contracts/generated'
import {
  DELIVERED_STEPS,
  type DeliveredStep,
  STEP_TITLES,
  workStatusLabel,
} from '@/domain/clinical/labels'
import { buildSaveStepPayload, consultationFromResult } from '@/domain/clinical/payload'
import { type RebaseConflict, rebaseConsultation } from '@/domain/clinical/rebase'
import { serverFieldErrors } from '@/domain/clinical/serverErrors'
import {
  blankDrafts,
  draftFromData,
  isStepDirty,
  type StepDataByName,
  type StepDrafts,
  type StepError,
  validateStep,
} from '@/domain/clinical/steps'

import { failureView } from './failureView'
import { useClinicalRead } from './useClinicalRead'

/** Conflit signalé après « Recharger la consultation » : valeur modifiée des deux côtés. */
export interface RebaseNotice {
  version: number
  conflicts: (RebaseConflict & { step: DeliveredStep })[]
}

function draftsFrom(consultation: Consultation): StepDrafts {
  const steps = consultation.steps
  return {
    ANAMNESIS: draftFromData('ANAMNESIS', steps.ANAMNESIS),
    HISTORIES: draftFromData('HISTORIES', steps.HISTORIES),
    CONCLUSION: draftFromData('CONCLUSION', steps.CONCLUSION),
  }
}

/**
 * Saisie d'une consultation par étapes (C01), EN LIGNE : lecture de la consultation, brouillons des
 * trois étapes livrées, enregistrement étape par étape et conflit de version.
 *
 * - Une étape n'est dite enregistrée qu'après la RÉPONSE du serveur (`synced`) : le brouillon repart
 *   alors de ce que le serveur a renvoyé. Rien n'est conservé dans le navigateur.
 * - « Suivant » valide et enregistre l'étape avant d'avancer ; « Précédent » et le choix d'une étape
 *   ne perdent aucune saisie (les brouillons restent en mémoire).
 * - L'étape est REMPLACÉE en entier par le serveur : la version attendue est celle de la
 *   consultation lue (une version pour tout l'agrégat). Un conflit (409) garde la saisie ; « Recharger
 *   la consultation » la rebase valeur par valeur sur la version actuelle (voir `domain/clinical/rebase`)
 *   et signale ce qui a changé des deux côtés.
 * - Un refus 422 par champ (`step.data.complaints.0.other_text`) s'affiche sous le champ et dans le
 *   résumé d'erreurs.
 */
export function useConsultationEditor(consultationId: string) {
  const read = useClinicalRead<Consultation>(() => getConsultation(consultationId))
  const mutation = useMutation()

  /** Consultation telle que le serveur l'a donnée (à l'ouverture, puis à chaque réponse). */
  const baseline = shallowRef<Consultation | null>(null)
  const drafts = ref<StepDrafts>(blankDrafts())
  const current = ref<DeliveredStep>('ANAMNESIS')
  const errors = ref<StepError[]>([])
  const notice = ref<string | null>(null)
  const announcement = ref('')
  const needsReload = ref(false)
  const reloading = ref(false)
  const rebased = ref<RebaseNotice | null>(null)
  /** Étape en cours d'enregistrement (ou en attente d'une reprise), et si « Suivant » l'a demandée. */
  const pending = ref<{ step: DeliveredStep; advance: boolean } | null>(null)
  /** Résumé d'erreurs (composant `ErrorSummary`, exposé par l'écran pour le focaliser). */
  const summaryBox = ref<{ focus: () => Promise<void> } | null>(null)

  watch(
    read.data,
    (consultation) => {
      if (consultation === null || baseline.value !== null || consultation.id !== consultationId) {
        return
      }
      baseline.value = consultation
      drafts.value = draftsFrom(consultation)
    },
    { immediate: true },
  )

  const readOnly = computed(
    () => !canOnScreen('clinical.write') || baseline.value?.status === 'COMPLETED',
  )
  const busy = computed(() => mutation.state.value === 'saving' || reloading.value)

  function dirtyOf(step: DeliveredStep): boolean {
    const saved = baseline.value
    return saved !== null && isStepDirty(step, drafts.value[step], saved.steps[step])
  }

  const dirtySteps = computed(() => DELIVERED_STEPS.filter((step) => dirtyOf(step)))
  const currentDirty = computed(() => !readOnly.value && dirtyOf(current.value))
  const anyDirty = computed(() => !readOnly.value && dirtySteps.value.length > 0)

  const stepIndex = computed(() => DELIVERED_STEPS.indexOf(current.value))
  const isFirst = computed(() => stepIndex.value === 0)
  const isLast = computed(() => stepIndex.value === DELIVERED_STEPS.length - 1)

  // --- Erreurs ------------------------------------------------------------------------------------

  /** Message par contrôle, pour les champs de l'étape affichée. */
  const errorMap = computed<Record<string, string>>(() => {
    const map: Record<string, string> = {}
    for (const error of errors.value) {
      map[error.controlId] =
        map[error.controlId] === undefined
          ? error.message
          : `${map[error.controlId]} ${error.message}`
    }
    return map
  })
  const summary = computed<FieldErrorItem[]>(() =>
    errors.value.map((error) => ({ fieldId: error.controlId, message: error.message })),
  )

  // Une erreur affichée se met à jour à mesure que la saisie change ; sans erreur, rien n'est évalué.
  watch(
    () => drafts.value[current.value],
    () => {
      notice.value = null
      if (errors.value.length === 0) return
      const result = validateStep(current.value, drafts.value[current.value])
      errors.value = result.ok ? [] : result.errors
    },
    { deep: true },
  )

  async function showErrors(items: StepError[]): Promise<void> {
    errors.value = items
    await summaryBox.value?.focus()
  }

  // --- Navigation ---------------------------------------------------------------------------------

  function goTo(step: DeliveredStep): void {
    if (busy.value || step === current.value) return
    current.value = step
    errors.value = []
    notice.value = null
    announcement.value = `Étape ${DELIVERED_STEPS.indexOf(step) + 1} sur ${DELIVERED_STEPS.length} : ${STEP_TITLES[step]}`
  }

  function previous(): void {
    const target = DELIVERED_STEPS[stepIndex.value - 1]
    if (target !== undefined) goTo(target)
  }

  function advance(): void {
    const target = DELIVERED_STEPS[stepIndex.value + 1]
    if (target !== undefined) goTo(target)
  }

  // --- Enregistrement -----------------------------------------------------------------------------

  /** Reprend ce que le serveur a renvoyé : l'étape enregistrée repart de sa réponse, les autres gardent leur saisie. */
  function adopt(next: Consultation, savedStep: DeliveredStep | null): void {
    const before = baseline.value
    const fresh = draftsFrom(next)
    const take = (step: DeliveredStep): boolean =>
      step === savedStep ||
      before === null ||
      !isStepDirty(step, drafts.value[step], before.steps[step])
    baseline.value = next
    drafts.value = {
      ANAMNESIS: take('ANAMNESIS') ? fresh.ANAMNESIS : drafts.value.ANAMNESIS,
      HISTORIES: take('HISTORIES') ? fresh.HISTORIES : drafts.value.HISTORIES,
      CONCLUSION: take('CONCLUSION') ? fresh.CONCLUSION : drafts.value.CONCLUSION,
    }
  }

  /** Relit la consultation sur le serveur quand la réponse d'une commande n'a pas la forme attendue. */
  async function reconcile(savedStep: DeliveredStep): Promise<void> {
    await read.reload()
    const latest = read.data.value
    if (read.state.value === 'ready' && latest !== null && latest.id === consultationId) {
      adopt(latest, savedStep)
    }
  }

  /** Donnée envoyée par la dernière commande : les erreurs 422 du serveur s'y rapportent (indices de lignes). */
  let lastSent: { step: DeliveredStep; data: StepDataByName[DeliveredStep] } | null = null

  async function afterOutcome(outcome: MutationOutcome): Promise<void> {
    const step = pending.value
    switch (outcome.kind) {
      case 'synced': {
        const next = consultationFromResult(outcome.result)
        if (step !== null) {
          if (next !== null && next.id === consultationId) adopt(next, step.step)
          else await reconcile(step.step)
        }
        errors.value = []
        rebased.value = null
        pending.value = null
        if (step?.advance === true) advance()
        return
      }
      case 'conflict':
        needsReload.value = true
        return
      case 'refused': {
        if (outcome.error.status === 422 && lastSent !== null) {
          const items = serverFieldErrors(lastSent.step, lastSent.data, outcome.error.fields)
          if (items.length > 0) {
            await showErrors(
              items.map((item) => ({ path: '', controlId: item.controlId, message: item.message })),
            )
          }
        }
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

  async function run(step: DeliveredStep, advanceAfter: boolean): Promise<void> {
    const saved = baseline.value
    if (busy.value || saved === null || readOnly.value || needsReload.value) return
    notice.value = null
    const validation = validateStep(step, drafts.value[step])
    if (!validation.ok) {
      await showErrors(validation.errors)
      return
    }
    errors.value = []
    if (!dirtyOf(step)) {
      if (advanceAfter) advance()
      else notice.value = 'Aucune modification à enregistrer sur cette étape.'
      return
    }
    pending.value = { step, advance: advanceAfter }
    lastSent = { step, data: validation.data }
    rebased.value = null
    await afterOutcome(
      await mutation.submit({
        name: 'SaveClinicalStep',
        path: { id: consultationId, step },
        payload: buildSaveStepPayload(consultationId, step, validation.data),
        expectedVersion: saved.version,
      }),
    )
  }

  /** « Enregistrer » : valide et enregistre l'étape affichée. */
  function save(): Promise<void> {
    return run(current.value, false)
  }

  /** « Suivant » : valide et enregistre l'étape affichée, puis avance seulement si le serveur a répondu. */
  function saveAndAdvance(): Promise<void> {
    return run(current.value, true)
  }

  /** « Réessayer l'enregistrement » : même commande, même clé d'idempotence. */
  async function retry(): Promise<void> {
    if (busy.value || pending.value === null) return
    await afterOutcome(await mutation.retry())
  }

  /** Relit la consultation et rebase la saisie sur la version actuelle (voir `rebaseConsultation`). */
  async function reloadFromServer(): Promise<void> {
    if (reloading.value) return
    reloading.value = true
    try {
      await read.reload()
      const latest = read.data.value
      const base = baseline.value
      if (read.state.value !== 'ready' || latest === null || latest.id !== consultationId) return
      if (base === null) return
      const result = rebaseConsultation(base.steps, latest.steps, drafts.value)
      baseline.value = latest
      drafts.value = result.drafts
      needsReload.value = false
      pending.value = null
      errors.value = []
      rebased.value = {
        version: latest.version,
        conflicts: DELIVERED_STEPS.flatMap((step) =>
          result.conflicts[step].map((conflict) => ({ ...conflict, step })),
        ),
      }
    } finally {
      reloading.value = false
    }
  }

  // --- État affiché -------------------------------------------------------------------------------

  const failure = computed(() => failureView(mutation.state.value, mutation.error.value))

  /** « Enregistré sur le serveur » seulement après la réponse, et tant qu'aucune saisie n'a suivi. */
  const saveStatus = computed(() => {
    switch (mutation.state.value) {
      case 'saving':
        return 'saving' as const
      case 'synced':
        return currentDirty.value ? ('idle' as const) : ('saved-server' as const)
      case 'idle':
        return 'idle' as const
      case 'saved-local':
        return 'saved-local' as const
      case 'conflict':
        return needsReload.value ? ('failed' as const) : ('idle' as const)
      default:
        return 'failed' as const
    }
  })

  const statusLabel = computed(() =>
    baseline.value === null ? '' : workStatusLabel(baseline.value.status),
  )

  return {
    read,
    mutation,
    baseline,
    drafts,
    current,
    errors,
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
    goTo,
    previous,
    advance,
    save,
    saveAndAdvance,
    retry,
    reloadFromServer,
  }
}
