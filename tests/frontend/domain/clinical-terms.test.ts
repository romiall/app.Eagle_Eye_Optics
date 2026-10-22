// @vitest-environment node
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  isUrgency,
  OTHER_PRECISION_LABELS,
  URGENCY_LABELS,
  URGENCY_LEVELS,
} from '@/domain/clinical/labels'
import {
  CLINICAL_TERMS,
  isKnownTerm,
  NONE_CODE,
  NONE_LABEL,
  selectableTerms,
  TERM_GROUPS,
  termLabel,
  termRank,
} from '@/domain/clinical/terms'

// Les libellés médicaux ne sont jamais inventés : ils sont ceux de `clinical_terms`
// (database/02_reference_data.sql). Ce test relit le SQL et exige l'égalité exacte des groupes, codes,
// libellés et ordres d'affichage ; toute dérive d'un côté ou de l'autre le fait échouer.
const ROOT = join(import.meta.dirname, '..', '..', '..')

interface SqlTerm {
  group: string
  code: string
  label: string
  order: number
  active: number
}

function sqlTerms(): SqlTerm[] {
  const sql = readFileSync(join(ROOT, 'database', '02_reference_data.sql'), 'utf8')
  const start = sql.indexOf('INSERT INTO `clinical_terms`')
  const end = sql.indexOf(';', start)
  const block = sql.slice(start, end)
  return [...block.matchAll(/\('([A-Z_]+)', '([^']+)', '((?:[^']|'')*)', (\d+), (\d+)\)/g)].map(
    (match) => ({
      group: match[1] ?? '',
      code: match[2] ?? '',
      label: (match[3] ?? '').replaceAll("''", "'"),
      order: Number(match[4]),
      active: Number(match[5]),
    }),
  )
}

describe('référentiel des termes cliniques = database/02_reference_data.sql', () => {
  const terms = sqlTerms()

  it('relit bien le SQL de référence', () => {
    expect(terms.length).toBeGreaterThan(70)
    expect(new Set(terms.map((term) => term.group))).toContain('COMPLAINT')
  })

  it.each(TERM_GROUPS)('groupe %s : codes, libellés et ordre identiques au SQL', (group) => {
    const expected = terms
      .filter((term) => term.group === group)
      .sort((left, right) => left.order - right.order)
      .map((term) => ({ code: term.code, label: term.label }))
    expect(expected.length, `groupe ${group} absent du SQL`).toBeGreaterThan(0)
    expect(CLINICAL_TERMS[group]).toEqual(expected)
  })

  it('les ordres du SQL sont 1, 2, 3… sans trou et tous les termes sont actifs', () => {
    for (const group of TERM_GROUPS) {
      const rows = terms.filter((term) => term.group === group).sort((a, b) => a.order - b.order)
      expect(rows.map((term) => term.order)).toEqual(rows.map((_term, index) => index + 1))
      expect(rows.every((term) => term.active === 1)).toBe(true)
    }
  })

  it('les 14 motifs, 3 groupes d’antécédents et 8 motifs d’orientation sont tous là', () => {
    expect(CLINICAL_TERMS.COMPLAINT).toHaveLength(14)
    expect(CLINICAL_TERMS.OCULAR_HISTORY).toHaveLength(8)
    expect(CLINICAL_TERMS.MEDICAL_HISTORY).toHaveLength(6)
    expect(CLINICAL_TERMS.FAMILY_HISTORY).toHaveLength(5)
    expect(CLINICAL_TERMS.REFERRAL_REASON).toHaveLength(8)
  })

  it('« Aucun connu » ouvre chaque groupe d’antécédents et ne se coche jamais comme un terme', () => {
    for (const group of ['OCULAR_HISTORY', 'MEDICAL_HISTORY', 'FAMILY_HISTORY'] as const) {
      expect(CLINICAL_TERMS[group][0]).toEqual({ code: NONE_CODE, label: NONE_LABEL })
      expect(selectableTerms(group).some((term) => term.code === NONE_CODE)).toBe(false)
      expect(selectableTerms(group)).toHaveLength(CLINICAL_TERMS[group].length - 1)
    }
    // Motifs et motifs d'orientation n'ont pas de « Aucun connu ».
    expect(CLINICAL_TERMS.COMPLAINT.some((term) => term.code === NONE_CODE)).toBe(false)
    expect(CLINICAL_TERMS.REFERRAL_REASON.some((term) => term.code === NONE_CODE)).toBe(false)
  })

  it('termLabel et termRank : un code inconnu est dit tel quel et vient après les autres', () => {
    expect(termLabel('COMPLAINT', 'BLURRED_VISION')).toBe('Vision floue')
    expect(termLabel('COMPLAINT', 'FUTURE_CODE')).toBe('FUTURE_CODE')
    expect(isKnownTerm('COMPLAINT', 'FUTURE_CODE')).toBe(false)
    expect(isKnownTerm('COMPLAINT', 'OTHER')).toBe(true)
    expect(termRank('COMPLAINT', 'VISION_DECLINE')).toBe(0)
    expect(termRank('COMPLAINT', 'FUTURE_CODE')).toBeGreaterThan(termRank('COMPLAINT', 'OTHER'))
  })
})

describe('libellés du DP', () => {
  it('niveaux d’urgence dans l’ordre du DP §10 : pas urgent, recommandée, rapide, urgence', () => {
    expect(URGENCY_LEVELS.map((level) => URGENCY_LABELS[level])).toEqual([
      'Pas urgent',
      'Consultation recommandée',
      'Consultation rapide',
      'Urgence',
    ])
    expect(isUrgency('RAPID')).toBe(true)
    expect(isUrgency('INCONNU')).toBe(false)
    expect(isUrgency(null)).toBe(false)
  })

  it('chaque liste a sa propre précision « Autre », pour qu’un lecteur d’écran la distingue', () => {
    const labels = Object.values(OTHER_PRECISION_LABELS)
    expect(new Set(labels).size).toBe(labels.length)
    expect(labels.every((label) => label.endsWith('précisez'))).toBe(true)
  })
})
