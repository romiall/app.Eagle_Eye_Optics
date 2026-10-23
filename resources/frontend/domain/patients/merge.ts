/**
 * Fusion manuelle de deux fiches d'un même patient (L1D08) : règles d'écran, sans effet. La comparaison
 * aligne les deux identités champ par champ et signale les différences (la gérante décide, jamais
 * l'application : aucune fusion automatique). Le serveur revérifie le motif et les garde-fous.
 */
import type { PatientMergeIdentity } from '@/domain/contracts/generated'

import { sexLabel } from './identity'

export const MERGE_REASON_MIN = 5
export const MERGE_REASON_MAX = 1000

export type MergeReasonCheck = { ok: true; reason: string } | { ok: false; message: string }

/** Motif de la fusion : obligatoire, 5 à 1000 caractères après suppression des blancs de bord. */
export function checkMergeReason(text: string): MergeReasonCheck {
  const reason = text.trim()
  if (reason.length < MERGE_REASON_MIN) {
    return {
      ok: false,
      message: `Indiquez le motif de la fusion (au moins ${MERGE_REASON_MIN} caractères).`,
    }
  }
  if (reason.length > MERGE_REASON_MAX) {
    return { ok: false, message: `Le motif dépasse ${MERGE_REASON_MAX} caractères.` }
  }
  return { ok: true, reason }
}

export interface IdentityComparisonRow {
  label: string
  source: string
  survivor: string
  /** Valeurs différentes (comparées sans tenir compte de la casse ni des blancs de bord). */
  differs: boolean
}

const NOT_GIVEN = 'Non renseigné'

function shown(value: string | null): string {
  return value === null || value.trim() === '' ? NOT_GIVEN : value.trim()
}

function same(left: string, right: string): boolean {
  return left.toLocaleLowerCase('fr') === right.toLocaleLowerCase('fr')
}

/**
 * Lignes de la comparaison côte à côte : fiche absorbée à gauche, fiche conservée à droite. `formatDate`
 * rend une date civile AAAA-MM-JJ lisible (fournie par l'écran).
 */
export function compareIdentities(
  source: PatientMergeIdentity,
  survivor: PatientMergeIdentity,
  formatDate: (civilDate: string) => string,
): IdentityComparisonRow[] {
  const fields: Array<[string, (identity: PatientMergeIdentity) => string]> = [
    ['Numéro de dossier', (identity) => identity.record_number],
    ['Ancien numéro (classeur)', (identity) => shown(identity.legacy_record_number)],
    ['Nom', (identity) => identity.last_name],
    ['Prénom(s)', (identity) => shown(identity.first_names)],
    ['Sexe', (identity) => sexLabel(identity.sex)],
    [
      'Date de naissance',
      (identity) => (identity.birth_date === null ? NOT_GIVEN : formatDate(identity.birth_date)),
    ],
    ['Téléphone principal', (identity) => shown(identity.phone_primary)],
  ]
  return fields.map(([label, read]) => {
    const left = read(source)
    const right = read(survivor)
    // Le numéro de dossier diffère toujours : ce n'est pas une différence d'identité à signaler.
    const differs = label === 'Numéro de dossier' ? false : !same(left, right)
    return { label, source: left, survivor: right, differs }
  })
}

/** Nombre de champs d'identité qui diffèrent entre les deux fiches. */
export function differenceCount(rows: readonly IdentityComparisonRow[]): number {
  return rows.filter((row) => row.differs).length
}
