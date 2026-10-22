import { cleanText } from './fields'
import { type DeliveredStep, DELIVERED_STEPS } from './labels'
import type { StepDataByName } from './steps'

/**
 * Finalisation d'une consultation (C03 ; fichier 02 : « à la finalisation, exiger motif ou plaintes, conclusion ou
 * justification d'absence, et résolution des erreurs de validation. Afficher les rubriques non renseignées ; ne pas
 * exiger des mesures non réalisées »). Jumeau des règles de `CompleteConsultationHandler`.
 *
 * - Une rubrique « non renseignée » est dite telle : elle est figée comme non renseignée, jamais transformée en
 *   « Aucun » ni en zéro.
 * - Si le motif ou les plaintes, OU la conclusion, manquent, une justification d'absence est obligatoire.
 * - Les rubriques mesurées vides ne sont JAMAIS exigées.
 *
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
export type StepsData = { [S in DeliveredStep]: StepDataByName[S] }

/** Vrai si la rubrique ne porte aucune réponse (tout est « non renseigné »). */
export function isStepEmpty<S extends DeliveredStep>(step: S, steps: StepsData): boolean {
  switch (step) {
    case 'ANAMNESIS': {
      const data = steps.ANAMNESIS
      return (
        cleanText(data.symptoms ?? '') === null &&
        data.wears_glasses === null &&
        cleanText(data.glasses_duration ?? '') === null &&
        data.previous_correction_available === null &&
        data.complaints.length === 0
      )
    }
    case 'HISTORIES':
      return steps.HISTORIES.histories.length === 0
    case 'VISUAL_ACUITY':
      return steps.VISUAL_ACUITY.acuities.length === 0
    case 'REFRACTION': {
      const data = steps.REFRACTION
      return (
        data.measurements.length === 0 &&
        cleanText(data.refraction_observations ?? '') === null &&
        data.ametropias.length === 0
      )
    }
    case 'CENTERING':
      return steps.CENTERING.centering_id === null
    case 'MEDICATIONS':
      return steps.MEDICATIONS.orders.length === 0
    default: {
      const data = steps.CONCLUSION
      return (
        cleanText(data.conclusion ?? '') === null &&
        data.referral === null &&
        data.referral_urgency === null &&
        data.referral_reasons.length === 0
      )
    }
  }
}

export interface CompletionSummary {
  /** Rubriques sans aucune réponse, dans l'ordre du dossier. */
  emptySteps: DeliveredStep[]
  /** Ni motif de consultation ni plaintes. */
  missingMotif: boolean
  /** Pas de conclusion. */
  missingConclusion: boolean
  /** Une justification d'absence est obligatoire (motif/plaintes ou conclusion manquants). */
  reasonRequired: boolean
}

export function completionSummary(steps: StepsData): CompletionSummary {
  const anamnesis = steps.ANAMNESIS
  const missingMotif =
    anamnesis.complaints.length === 0 && cleanText(anamnesis.symptoms ?? '') === null
  const missingConclusion = cleanText(steps.CONCLUSION.conclusion ?? '') === null
  return {
    emptySteps: DELIVERED_STEPS.filter((step) => isStepEmpty(step, steps)),
    missingMotif,
    missingConclusion,
    reasonRequired: missingMotif || missingConclusion,
  }
}

/** Ce qui manque, en clair : « le motif ou les plaintes et la conclusion ». */
export function missingDataText(summary: CompletionSummary): string {
  const parts: string[] = []
  if (summary.missingMotif) parts.push('le motif ou les plaintes')
  if (summary.missingConclusion) parts.push('la conclusion')
  return parts.join(' et ')
}

/** Message d'erreur de la justification d'absence (null si admise). */
export function completionReasonError(summary: CompletionSummary, reason: string): string | null {
  if (summary.reasonRequired && cleanText(reason) === null) {
    return `Il manque ${missingDataText(summary)} : renseignez-les ou justifiez leur absence avant de terminer.`
  }
  return null
}
