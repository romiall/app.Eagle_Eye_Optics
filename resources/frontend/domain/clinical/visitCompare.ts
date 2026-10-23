/**
 * Comparaison de deux examens d'un même patient (L1D08 ; fichier 02, « Historique » : « comparaison de deux visites :
 * mesures OD/OG, échelle, valeurs non mesurées et prescriptions ; jamais agréger des acuités d'échelles
 * incompatibles » ; AT21).
 *
 * - Les rubriques de l'examen se comparent par `compareSteps` (C03), sur la révision ACTUELLE de chaque examen
 *   terminé : une acuité garde son échelle, deux échelles différentes sont déclarées non comparables, aucune
 *   conversion ni différence chiffrée.
 * - La prescription comparée est la dernière version VALIDÉE de chaque examen (un brouillon n'est pas une
 *   prescription) ; ses valeurs sont celles de l'écran et du document imprimé (adaptateur de l'écran), comparées
 *   texte contre texte : aucun calcul.
 * - Une valeur absente est « Non renseigné », jamais zéro ; le changement est dit en texte et par symbole.
 *
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
import type {
  PatientActivityConsultation,
  PatientActivityItem,
  Prescription,
} from '@/domain/contracts/generated'

import { type ChangeKind, NOT_FILLED } from './compare'

/** Un fait affichable : identifiant stable, libellé, valeur en clair. */
export interface PlainFact {
  key: string
  label: string
  value: string
}

export interface FactRow {
  key: string
  label: string
  left: string
  right: string
  kind: ChangeKind
}

export interface FactComparison {
  rows: FactRow[]
  differences: number
}

/** Valeurs de l'écran d'une prescription (même forme que le modèle d'écran de l'application). */
export interface PrescriptionFactsSource {
  eyes: readonly {
    eye: 'OD' | 'OG'
    label: string
    noCorrection: boolean
    sphere: string
    cylinder: string
    axis: string
    addition: string
    fittingHeight: string
  }[]
  pd: { total: string; od: string; og: string }
  usage: string | null
  lensType: string | null
  lensIndex: string | null
  treatments: readonly string[]
  notes: string | null
}

const MISSING_MARKS = new Set(['', '—', '-'])

function shown(value: string | null | undefined): string {
  if (value === null || value === undefined) return NOT_FILLED
  const trimmed = value.trim()
  return MISSING_MARKS.has(trimmed) ? NOT_FILLED : trimmed
}

/** Faits d'une prescription, dans l'ordre de la fiche imprimée. */
export function prescriptionFacts(source: PrescriptionFactsSource): PlainFact[] {
  const facts: PlainFact[] = []
  for (const eye of source.eyes) {
    if (eye.noCorrection) {
      facts.push({
        key: `${eye.eye}.correction`,
        label: `${eye.label} : correction`,
        value: 'Aucune correction',
      })
      continue
    }
    facts.push(
      { key: `${eye.eye}.sphere`, label: `${eye.label} : sphère (D)`, value: shown(eye.sphere) },
      {
        key: `${eye.eye}.cylinder`,
        label: `${eye.label} : cylindre (D)`,
        value: shown(eye.cylinder),
      },
      { key: `${eye.eye}.axis`, label: `${eye.label} : axe (°)`, value: shown(eye.axis) },
      {
        key: `${eye.eye}.addition`,
        label: `${eye.label} : addition (D)`,
        value: shown(eye.addition),
      },
      {
        key: `${eye.eye}.height`,
        label: `${eye.label} : hauteur`,
        value: shown(eye.fittingHeight),
      },
    )
  }
  facts.push(
    { key: 'pd.total', label: 'Écart pupillaire total', value: shown(source.pd.total) },
    { key: 'pd.od', label: 'Demi-écart OD', value: shown(source.pd.od) },
    { key: 'pd.og', label: 'Demi-écart OG', value: shown(source.pd.og) },
    { key: 'usage', label: 'Usage', value: shown(source.usage) },
    { key: 'lens_type', label: 'Type de verres', value: shown(source.lensType) },
    { key: 'lens_index', label: 'Indice', value: shown(source.lensIndex) },
    {
      key: 'treatments',
      label: 'Traitements',
      value: source.treatments.length === 0 ? NOT_FILLED : source.treatments.join(', '),
    },
    { key: 'notes', label: 'Remarques', value: shown(source.notes) },
  )
  return facts
}

/**
 * Compare deux listes de faits (`left` : examen de référence, le plus ancien ; `right` : examen comparé). Une liste
 * absente (pas de prescription validée) compte comme entièrement « Non renseigné ».
 */
export function compareFacts(
  left: readonly PlainFact[] | null,
  right: readonly PlainFact[] | null,
): FactComparison {
  const order: PlainFact[] = []
  const seen = new Set<string>()
  for (const fact of [...(right ?? []), ...(left ?? [])]) {
    if (seen.has(fact.key)) continue
    seen.add(fact.key)
    order.push(fact)
  }
  const leftByKey = new Map((left ?? []).map((fact) => [fact.key, fact.value]))
  const rightByKey = new Map((right ?? []).map((fact) => [fact.key, fact.value]))
  const rows = order.map((fact): FactRow => {
    const before = leftByKey.get(fact.key) ?? NOT_FILLED
    const after = rightByKey.get(fact.key) ?? NOT_FILLED
    let kind: ChangeKind = 'same'
    if (before !== after) {
      if (before === NOT_FILLED) kind = 'added'
      else if (after === NOT_FILLED) kind = 'removed'
      else kind = 'changed'
    }
    return { key: fact.key, label: fact.label, left: before, right: after, kind }
  })
  return { rows, differences: rows.filter((row) => row.kind !== 'same').length }
}

/**
 * Dernière version VALIDÉE d'une prescription parmi les versions d'un examen (numéro de version le plus élevé) ;
 * null si aucune n'est validée.
 */
export function latestValidated(prescriptions: readonly Prescription[]): Prescription | null {
  let best: Prescription | null = null
  for (const prescription of prescriptions) {
    if (prescription.status !== 'VALIDATED') continue
    if (best === null || prescription.version_no > best.version_no) best = prescription
  }
  return best
}

/** Examens TERMINÉS d'une liste d'activité, du plus récent au plus ancien (instants ISO comparés en texte). */
export function completedExams(
  items: readonly PatientActivityItem[],
): PatientActivityConsultation[] {
  return items
    .filter(
      (item): item is PatientActivityConsultation =>
        item.type === 'CONSULTATION' && item.status === 'COMPLETED',
    )
    .slice()
    .sort((a, b) => (a.occurred_at < b.occurred_at ? 1 : a.occurred_at > b.occurred_at ? -1 : 0))
}

/**
 * Paire proposée par défaut : l'avant-dernier examen en référence, le dernier en comparé. Une demande explicite
 * (adresse) l'emporte si elle désigne des examens terminés de la liste et deux examens différents.
 */
export function defaultPair(
  exams: readonly PatientActivityConsultation[],
  requested: { left: string | null; right: string | null } = { left: null, right: null },
): { left: string | null; right: string | null } {
  const ids = new Set(exams.map((exam) => exam.id))
  const right =
    requested.right !== null && ids.has(requested.right) ? requested.right : (exams[0]?.id ?? null)
  const fallbackLeft = exams.find((exam) => exam.id !== right)?.id ?? null
  const left =
    requested.left !== null && ids.has(requested.left) && requested.left !== right
      ? requested.left
      : fallbackLeft
  return { left, right }
}
