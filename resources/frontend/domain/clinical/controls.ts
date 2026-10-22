import type { HistoryGroup } from './labels'

/**
 * Identifiants des contrôles des rubriques (cibles du résumé d'erreurs et des erreurs du serveur).
 * Une liste de cases à cocher porte l'identifiant de son `fieldset` ; chaque case porte
 * `<identifiant>-<CODE>` ; la précision de « Autre » porte `<identifiant>-OTHER-precision`.
 */
export const CONTROL_IDS = {
  /** Titre de l'étape : cible des erreurs sans champ précis. */
  step: 'consultation-etape',

  complaints: 'motifs',
  complaint: (code: string): string => `motifs-${code}`,
  complaintOther: 'motifs-OTHER-precision',
  symptoms: 'symptomes',
  wearsGlasses: 'lunettes',
  glassesDuration: 'lunettes-duree',
  previousCorrection: 'lunettes-ancienne-correction',

  historyGroup: (group: HistoryGroup): string => `antecedents-${group}`,
  historyTerm: (group: HistoryGroup, code: string): string => `antecedents-${group}-${code}`,
  historyNone: (group: HistoryGroup): string => `antecedents-${group}-NONE`,
  historyOther: (group: HistoryGroup): string => `antecedents-${group}-OTHER-precision`,
  historyComments: (group: HistoryGroup): string => `antecedents-${group}-commentaires`,

  conclusion: 'conclusion-texte',
  referral: 'orientation',
  urgency: 'orientation-urgence',
  reasons: 'orientation-motifs',
  reason: (code: string): string => `orientation-motifs-${code}`,
  reasonOther: 'orientation-motifs-OTHER-precision',
} as const
