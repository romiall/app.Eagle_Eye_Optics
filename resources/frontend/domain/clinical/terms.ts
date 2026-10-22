/**
 * Termes cliniques du référentiel `clinical_terms` (database/02_reference_data.sql) utilisés par les
 * rubriques narratives du dossier DP (C01) : motifs de consultation, antécédents oculaires, médicaux
 * et familiaux, motifs d'orientation. Codes ET libellés français sont recopiés du SQL : aucun libellé
 * médical n'est inventé ici. `tests/frontend/domain/clinical-terms.test.ts` relit le SQL et vérifie
 * l'égalité exacte (groupes, codes, libellés, ordre) : toute dérive fait échouer le test.
 *
 * Groupes de C02 (amétropies) et de C04 (usage, type de verre et traitements de la prescription optique).
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
export const TERM_GROUPS = [
  'COMPLAINT',
  'OCULAR_HISTORY',
  'MEDICAL_HISTORY',
  'FAMILY_HISTORY',
  'REFERRAL_REASON',
  'AMETROPIA',
  'OPTICAL_USAGE',
  'LENS_TYPE',
  'LENS_TREATMENT',
] as const

export type TermGroup = (typeof TERM_GROUPS)[number]

/** Terme « Autre » : le texte de précision est obligatoire. */
export const OTHER_CODE = 'OTHER'

/**
 * Terme « Aucun connu » des groupes d'antécédents : il ne se stocke jamais comme terme (CHECK SQL
 * `term_code <> 'NONE'`), il se représente par `known_none`.
 */
export const NONE_CODE = 'NONE'

export interface ClinicalTerm {
  readonly code: string
  readonly label: string
}

export const CLINICAL_TERMS: Readonly<Record<TermGroup, readonly ClinicalTerm[]>> = {
  COMPLAINT: [
    { code: 'VISION_DECLINE', label: 'Baisse de vision' },
    { code: 'BLURRED_VISION', label: 'Vision floue' },
    { code: 'HEADACHE', label: 'Céphalées' },
    { code: 'VISUAL_FATIGUE', label: 'Fatigue visuelle' },
    { code: 'DISTANCE_DIFFICULTY', label: 'Difficulté de loin' },
    { code: 'NEAR_DIFFICULTY', label: 'Difficulté de près' },
    { code: 'GLASSES_RENEWAL', label: 'Renouvellement de lunettes' },
    { code: 'ROUTINE_CHECK', label: 'Contrôle de routine' },
    { code: 'REDNESS', label: 'Rougeur' },
    { code: 'TEARING', label: 'Larmoiement' },
    { code: 'ITCHING', label: 'Démangeaisons' },
    { code: 'PAIN', label: 'Douleur' },
    { code: 'PHOTOPHOBIA', label: 'Photophobie' },
    { code: 'OTHER', label: 'Autre' },
  ],
  OCULAR_HISTORY: [
    { code: 'NONE', label: 'Aucun connu' },
    { code: 'TRAUMA', label: 'Traumatisme' },
    { code: 'SURGERY', label: 'Chirurgie' },
    { code: 'GLAUCOMA', label: 'Glaucome' },
    { code: 'CATARACT', label: 'Cataracte' },
    { code: 'INFECTION', label: 'Infection' },
    { code: 'CONTACT_LENSES', label: 'Lentilles' },
    { code: 'OTHER', label: 'Autre' },
  ],
  MEDICAL_HISTORY: [
    { code: 'NONE', label: 'Aucun connu' },
    { code: 'DIABETES', label: 'Diabète' },
    { code: 'HYPERTENSION', label: 'Hypertension' },
    { code: 'ALLERGIES', label: 'Allergies' },
    { code: 'CURRENT_TREATMENT', label: 'Traitement en cours' },
    { code: 'OTHER', label: 'Autre' },
  ],
  FAMILY_HISTORY: [
    { code: 'NONE', label: 'Aucun connu' },
    { code: 'GLAUCOMA', label: 'Glaucome' },
    { code: 'CATARACT', label: 'Cataracte' },
    { code: 'HIGH_MYOPIA', label: 'Forte myopie' },
    { code: 'OTHER', label: 'Autre' },
  ],
  REFERRAL_REASON: [
    { code: 'SUSPECTED_GLAUCOMA', label: 'Suspicion de glaucome' },
    { code: 'CATARACT', label: 'Cataracte' },
    { code: 'UNEXPLAINED_ACUITY_LOSS', label: 'Baisse d’acuité inexpliquée' },
    { code: 'CORNEAL_DISEASE', label: 'Pathologie cornéenne' },
    { code: 'RED_EYE_INFLAMMATION', label: 'Œil rouge / inflammation' },
    { code: 'TRAUMA', label: 'Traumatisme' },
    { code: 'SUSPECTED_RETINAL_DISEASE', label: 'Pathologie rétinienne suspectée' },
    { code: 'OTHER', label: 'Autre' },
  ],
  AMETROPIA: [
    { code: 'EMMETROPIA', label: 'Emmétropie' },
    { code: 'MYOPIA', label: 'Myopie' },
    { code: 'HYPERMETROPIA', label: 'Hypermétropie' },
    { code: 'ASTIGMATISM', label: 'Astigmatisme' },
    { code: 'PRESBYOPIA', label: 'Presbytie' },
    { code: 'ANISOMETROPIA', label: 'Anisométropie' },
    { code: 'OTHER', label: 'Autre' },
  ],
  OPTICAL_USAGE: [
    { code: 'DISTANCE', label: 'Vision de loin' },
    { code: 'NEAR', label: 'Vision de près' },
    { code: 'PERMANENT', label: 'Lunettes permanentes' },
    { code: 'PROGRESSIVE', label: 'Progressifs' },
    { code: 'BIFOCAL', label: 'Bifocaux' },
    { code: 'COMPUTER_WORK', label: 'Travail / ordinateur' },
    { code: 'OTHER', label: 'Autre' },
  ],
  LENS_TYPE: [
    { code: 'SINGLE_VISION', label: 'Unifocal' },
    { code: 'BIFOCAL', label: 'Bifocal' },
    { code: 'PROGRESSIVE', label: 'Progressif' },
    { code: 'ANTI_FATIGUE', label: 'Anti-fatigue' },
    { code: 'OTHER', label: 'Autre' },
  ],
  LENS_TREATMENT: [
    { code: 'ANTI_REFLECTION', label: 'Anti-reflet' },
    { code: 'PHOTOCHROMIC', label: 'Photochromique' },
    { code: 'UV', label: 'UV' },
    { code: 'BLUE_LIGHT_FILTER', label: 'Filtre lumière bleue / Blue-Cut' },
    { code: 'HARD_ANTI_SCRATCH', label: 'Durci / anti-rayures' },
    { code: 'TINTED', label: 'Teinté' },
    { code: 'HMC', label: 'HMC' },
    { code: 'OTHER', label: 'Autre' },
  ],
}

/** Libellé de « Aucun connu » (identique dans les trois groupes d'antécédents). */
export const NONE_LABEL = 'Aucun connu'

/** Termes que l'on peut cocher : ceux du référentiel, sans « Aucun connu ». */
export function selectableTerms(group: TermGroup): readonly ClinicalTerm[] {
  return CLINICAL_TERMS[group].filter((term) => term.code !== NONE_CODE)
}

/** Rang d'un code dans son groupe (ordre du référentiel) ; un code inconnu vient après les autres. */
export function termRank(group: TermGroup, code: string): number {
  const index = selectableTerms(group).findIndex((term) => term.code === code)
  return index === -1 ? Number.MAX_SAFE_INTEGER : index
}

export function isKnownTerm(group: TermGroup, code: string): boolean {
  return selectableTerms(group).some((term) => term.code === code)
}

/**
 * Libellé d'un code. Un code que cette version ne connaît pas (terme ajouté au référentiel depuis)
 * est dit tel quel : il n'est jamais deviné ni retiré en silence.
 */
export function termLabel(group: TermGroup, code: string): string {
  return CLINICAL_TERMS[group].find((term) => term.code === code)?.label ?? code
}
