import type { ConsultationsReferralUrgency, ConsultationsStatus } from '../contracts/generated'

/**
 * Libellés français des rubriques narratives du dossier DP (C01 ; fichier 02, « Consultation et
 * antécédents » ; DP §4, §5, §10 et §14). Règles pures : ni Vue, ni réseau, ni stockage.
 */
export type Urgency = ConsultationsReferralUrgency

/** Niveaux d'urgence de l'orientation, dans l'ordre du DP §10 : pas urgent, recommandée, rapide, urgence. */
export const URGENCY_LEVELS: readonly Urgency[] = [
  'NOT_URGENT',
  'RECOMMENDED',
  'RAPID',
  'EMERGENCY',
]

export const URGENCY_LABELS: Readonly<Record<Urgency, string>> = {
  NOT_URGENT: 'Pas urgent',
  RECOMMENDED: 'Consultation recommandée',
  RAPID: 'Consultation rapide',
  EMERGENCY: 'Urgence',
}

export function isUrgency(value: string | null): value is Urgency {
  return value !== null && (URGENCY_LEVELS as readonly string[]).includes(value)
}

export function urgencyLabel(urgency: string): string {
  return isUrgency(urgency) ? URGENCY_LABELS[urgency] : urgency
}

/**
 * État du travail clinique (fichier 02 : « brouillon, en cours, terminé »). « À revoir » et
 * « Rendez-vous programmé » sont des badges dérivés de l'agenda (S01, S02) : cette version ne les
 * affiche pas, elle ne fait pas comme si leur valeur était « aucun ».
 */
export const WORK_STATUS_LABELS: Readonly<Record<ConsultationsStatus, string>> = {
  DRAFT: 'Brouillon',
  IN_PROGRESS: 'En cours',
  COMPLETED: 'Terminé',
}

/** Symbole de chaque état : la couleur n'est jamais le seul signal. */
export const WORK_STATUS_SYMBOLS: Readonly<Record<ConsultationsStatus, string>> = {
  DRAFT: '○',
  IN_PROGRESS: '●',
  COMPLETED: '✓',
}

function isWorkStatus(value: string): value is ConsultationsStatus {
  return Object.prototype.hasOwnProperty.call(WORK_STATUS_LABELS, value)
}

/** Libellé d'un état ; un état que cette version ne connaît pas est dit tel quel, jamais deviné. */
export function workStatusLabel(status: string): string {
  return isWorkStatus(status) ? WORK_STATUS_LABELS[status] : `État inconnu (${status})`
}

export function workStatusSymbol(status: string): string {
  return isWorkStatus(status) ? WORK_STATUS_SYMBOLS[status] : '?'
}

/** Badge d'orientation (DP §14 : « Orienté vers ophtalmologiste »), dérivé de l'étape Conclusion. */
export const REFERRAL_BADGE = 'Orienté vers ophtalmologiste'

export const HISTORY_GROUPS = ['OCULAR_HISTORY', 'MEDICAL_HISTORY', 'FAMILY_HISTORY'] as const

export type HistoryGroup = (typeof HISTORY_GROUPS)[number]

/** Titres des groupes d'antécédents (DP §5). */
export const HISTORY_GROUP_LABELS: Readonly<Record<HistoryGroup, string>> = {
  OCULAR_HISTORY: 'Antécédents oculaires',
  MEDICAL_HISTORY: 'Antécédents médicaux',
  FAMILY_HISTORY: 'Antécédents familiaux oculaires',
}

/** Précision demandée quand « Autre » est coché, propre à chaque liste (un lecteur d'écran l'entend). */
export const OTHER_PRECISION_LABELS = {
  COMPLAINT: 'Autre motif : précisez',
  OCULAR_HISTORY: 'Autre antécédent oculaire : précisez',
  MEDICAL_HISTORY: 'Autre antécédent médical : précisez',
  FAMILY_HISTORY: 'Autre antécédent familial oculaire : précisez',
  REFERRAL_REASON: 'Autre motif d’orientation : précisez',
  AMETROPIA: 'Autre amétropie : précisez',
  OPTICAL_USAGE: 'Autre usage : précisez',
  LENS_TYPE: 'Autre type de verre : précisez',
  LENS_TREATMENT: 'Autre traitement : précisez',
} as const

/**
 * Rubriques du dossier, dans l'ordre de l'examen (DP §18 : motif, antécédents, acuité, réfraction,
 * centrage, médicaments, conclusion). Les sept sont livrées en ligne (C01, C02, C04).
 */
export const DELIVERED_STEPS = [
  'ANAMNESIS',
  'HISTORIES',
  'VISUAL_ACUITY',
  'REFRACTION',
  'CENTERING',
  'MEDICATIONS',
  'CONCLUSION',
] as const

export type DeliveredStep = (typeof DELIVERED_STEPS)[number]

export const STEP_TITLES: Readonly<Record<DeliveredStep, string>> = {
  ANAMNESIS: 'Motif et lunettes',
  HISTORIES: 'Antécédents',
  VISUAL_ACUITY: 'Acuité visuelle',
  REFRACTION: 'Réfraction',
  CENTERING: 'Centrage',
  MEDICATIONS: 'Médicaments',
  CONCLUSION: 'Conclusion et orientation',
}

/** Valeurs de la rubrique « oui / non / non renseigné » (DP §4 et §10 ; fichier 02). */
export const YES_NO_LABELS = { yes: 'Oui', no: 'Non', unknown: 'Non renseigné' } as const

/** Yeux (DP §6) : « OD » œil droit, « OG » œil gauche, « OU » les deux yeux. */
export const EYE_LABELS = {
  OD: 'Œil droit (OD)',
  OG: 'Œil gauche (OG)',
  OU: 'Les deux yeux (OU)',
} as const
export const EYE_SHORT_LABELS = { OD: 'OD', OG: 'OG', OU: 'OU' } as const

/** Stades de mesure (DP §6 et §7) : trois mesures DISTINCTES, jamais fusionnées. */
export const STAGE_LABELS = {
  AUTOREFRACTOR: 'Autoréfracteur',
  OBJECTIVE: 'Réfraction objective',
  SUBJECTIVE: 'Réfraction subjective',
} as const

/** Formes d'un médicament (DP §9) et unités de durée structurée. */
export const MEDICATION_FORM_LABELS = {
  DROPS: 'Collyre',
  OINTMENT: 'Pommade',
  TABLET: 'Comprimé',
  OTHER: 'Autre',
} as const

export const DURATION_UNIT_LABELS = { DAY: 'jour(s)', WEEK: 'semaine(s)', MONTH: 'mois' } as const
