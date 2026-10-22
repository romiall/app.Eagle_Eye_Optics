// @vitest-environment node
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  ACUITY_SLOTS,
  acuityErrors,
  acuitySlotKey,
  blankVisualAcuity,
  groupScale,
  mixedScaleGroups,
  setAcuityValue,
  setGroupScale,
  visualAcuityData,
  visualAcuityDraft,
} from '@/domain/clinical/acuity'
import {
  blankCentering,
  centeringData,
  centeringDraft,
  centeringErrors,
  pdTextsMismatch,
  suggestedSum,
} from '@/domain/clinical/centering'
import { axisData, axisError, measureData, measureError, measureText } from '@/domain/clinical/fields'
import {
  blankRefraction,
  measurementKey,
  refractionData,
  refractionDraft,
  refractionErrors,
  stepNotices,
} from '@/domain/clinical/refraction'

// Règles pures des rubriques mesurées (C02) : vecteurs communs PHP/TypeScript
// (contracts/vectors/clinical/measures.json, rejoués par tests/Unit/Clinical/MeasurementRulesTest.php),
// acuités (valeur lue conservée, aucune conversion), réfraction (stades distincts, aucune inférence) et
// centrage (rien de fabriqué, justification d'un écart). Données FICTIVES.
const ROOT = join(import.meta.dirname, '..', '..', '..')
const vectors = JSON.parse(
  readFileSync(join(ROOT, 'contracts', 'vectors', 'clinical', 'measures.json'), 'utf8'),
) as {
  axis: { id: string; value: number; valid: boolean }[]
  pd_mismatch: { id: string; total: string | null; od: string | null; og: string | null; mismatch: boolean }[]
  canonical: { id: string; text: string; canonical: string }[]
  refused: { id: string; text: string }[]
}

let counter = 0
const nextId = (): string => `01J9ZXK4M2Q8R7S6T5V4W3${String(counter++).padStart(4, '0')}`
const SCALE = '01J9ZXK4M2Q8R7S6T5V4W30701'
const OTHER_SCALE = '01J9ZXK4M2Q8R7S6T5V4W30702'

describe('vecteurs communs des mesures', () => {
  it.each(vectors.axis.map((item) => [item.id, item] as const))('axe %s', (_id, item) => {
    expect(axisError(String(item.value)) === null).toBe(item.valid)
    expect(axisData(String(item.value))).toBe(item.valid ? item.value : null)
  })

  it.each(vectors.canonical.map((item) => [item.id, item] as const))(
    'forme canonique %s',
    (_id, item) => {
      expect(measureError(item.text)).toBeNull()
      expect(measureData(item.text)).toBe(item.canonical)
    },
  )

  it.each(vectors.refused.map((item) => [item.id, item] as const))('refus %s', (_id, item) => {
    expect(measureError(item.text)).not.toBeNull()
  })

  it.each(vectors.pd_mismatch.map((item) => [item.id, item] as const))(
    'écart de DP %s',
    (_id, item) => {
      expect(pdTextsMismatch(item.total ?? '', item.od ?? '', item.og ?? '')).toBe(item.mismatch)
    },
  )

  it('une mesure invalide est conservée telle quelle (jamais effacée ni corrigée en silence)', () => {
    expect(measureData(' 1,255 ')).toBe('1,255')
    expect(measureData('')).toBeNull()
    expect(measureText('-1.25')).toBe('-1,25')
    expect(measureText(null)).toBe('')
  })
})

describe('acuité visuelle', () => {
  it('la grille porte douze valeurs préliminaires et trois après correction', () => {
    expect(ACUITY_SLOTS).toHaveLength(15)
    expect(ACUITY_SLOTS.filter((slot) => slot.context === 'POST_REFRACTION').every((slot) => slot.distance === 'UNSPECIFIED' && slot.correction === 'WITH')).toBe(true)
  })

  it('une case vide n’a pas de ligne ; la valeur lue est conservée telle que saisie, zéro compris, sans conversion', () => {
    let draft = blankVisualAcuity(nextId)
    draft = setGroupScale(draft, 'PRELIMINARY|FAR', SCALE)
    draft = setAcuityValue(draft, acuitySlotKey('PRELIMINARY', 'FAR', 'WITHOUT', 'OD'), ' 8/10 ')
    draft = setAcuityValue(draft, acuitySlotKey('PRELIMINARY', 'FAR', 'WITHOUT', 'OG'), '0')

    const data = visualAcuityData(draft)

    expect(data.acuities.map((row) => [row.eye, row.raw_value, row.scale_id, row.normalized_value])).toEqual([
      ['OD', '8/10', SCALE, null],
      ['OG', '0', SCALE, null],
    ])
    expect(acuityErrors(draft)).toEqual([])
  })

  it('une valeur sans échelle est refusée sur l’échelle de son groupe', () => {
    const draft = setAcuityValue(blankVisualAcuity(nextId), acuitySlotKey('PRELIMINARY', 'NEAR', 'WITH', 'OU'), 'P2')

    const errors = acuityErrors(draft)

    expect(errors.map((error) => error.path)).toEqual(['acuities.0.scale_id'])
    expect(errors[0]?.message).toContain('choisissez l’échelle utilisée')
  })

  it('une valeur trop longue ou avec un caractère de contrôle est refusée', () => {
    let draft = setGroupScale(blankVisualAcuity(nextId), 'PRELIMINARY|FAR', SCALE)
    draft = setAcuityValue(draft, acuitySlotKey('PRELIMINARY', 'FAR', 'WITH', 'OD'), 'x'.repeat(101))
    draft = setAcuityValue(draft, acuitySlotKey('PRELIMINARY', 'FAR', 'WITH', 'OG'), 'a\u0007b')

    expect(acuityErrors(draft).map((error) => error.path)).toEqual(['acuities.0.raw_value', 'acuities.1.raw_value'])
  })

  it('des échelles différentes dans un même groupe sont signalées (valeurs non comparables)', () => {
    let draft = setGroupScale(blankVisualAcuity(nextId), 'PRELIMINARY|FAR', SCALE)
    draft = setAcuityValue(draft, acuitySlotKey('PRELIMINARY', 'FAR', 'WITHOUT', 'OD'), '8/10')
    const key = acuitySlotKey('PRELIMINARY', 'FAR', 'WITH', 'OD')
    draft = { ...draft, [key]: { ...draft[key]!, scaleId: OTHER_SCALE, value: '6/6' } }

    expect(mixedScaleGroups(draft).map((group) => group.key)).toEqual(['PRELIMINARY|FAR'])
    expect(groupScale(draft, 'PRELIMINARY|FAR')).toBeNull()
    expect(groupScale(draft, 'PRELIMINARY|NEAR')).toBeNull()
  })

  it('une valeur normalisée déjà enregistrée est renvoyée telle quelle, jamais calculée', () => {
    const id = nextId()
    const draft = visualAcuityDraft(
      { acuities: [{ id, context: 'PRELIMINARY', distance: 'FAR', correction: 'WITHOUT', eye: 'OD', scale_id: SCALE, raw_value: '8/10', normalized_value: '0.8000' }] },
      nextId,
    )

    expect(visualAcuityData(draft).acuities).toEqual([
      { id, context: 'PRELIMINARY', distance: 'FAR', correction: 'WITHOUT', eye: 'OD', scale_id: SCALE, raw_value: '8/10', normalized_value: '0.8000' },
    ])
  })
})

describe('réfraction', () => {
  it('les trois stades restent distincts, un œil non mesuré n’a pas de ligne, l’addition n’est que subjective', () => {
    const draft = blankRefraction(nextId)
    draft.cells[measurementKey('AUTOREFRACTOR', 'OD')] = { ...draft.cells[measurementKey('AUTOREFRACTOR', 'OD')]!, sphere: '-1,25', cylinder: '+0,50', axis: '90' }
    draft.cells[measurementKey('OBJECTIVE', 'OG')] = { ...draft.cells[measurementKey('OBJECTIVE', 'OG')]!, addition: '2' }
    draft.cells[measurementKey('SUBJECTIVE', 'OG')] = { ...draft.cells[measurementKey('SUBJECTIVE', 'OG')]!, sphere: '0', addition: '2,25' }

    const data = refractionData(draft)

    expect(data.measurements.map((row) => [row.stage, row.eye, row.sphere, row.cylinder, row.axis, row.addition])).toEqual([
      ['AUTOREFRACTOR', 'OD', '-1.25', '0.50', 90, null],
      ['SUBJECTIVE', 'OG', '0.00', null, null, '2.25'],
    ])
    expect(refractionErrors(draft)).toEqual([])
  })

  it('une mesure ou un axe invalide est refusé avec son stade et son œil, jamais arrondi', () => {
    const draft = blankRefraction(nextId)
    draft.cells[measurementKey('OBJECTIVE', 'OD')] = { ...draft.cells[measurementKey('OBJECTIVE', 'OD')]!, sphere: '-1,255', axis: '181' }

    const errors = refractionErrors(draft)

    expect(errors.map((error) => error.path)).toEqual(['measurements.0.sphere', 'measurements.0.axis'])
    expect(errors[0]?.message).toContain('Réfraction objective, OD')
    expect(errors[0]?.message).toContain('jamais arrondie')
  })

  it('les indications de pas et d’axe ne bloquent jamais l’enregistrement', () => {
    const cell = { id: nextId(), sphere: '-1,10', cylinder: '-0,50', axis: '', addition: '' }

    const notices = stepNotices(cell, 'OBJECTIVE')

    expect(notices).toHaveLength(2)
    expect(notices[0]).toContain('pas usuel de 0,25')
    expect(notices[1]).toContain('Cylindre non nul sans axe')
    expect(stepNotices({ ...cell, sphere: '-1,25', axis: '90' }, 'OBJECTIVE')).toEqual([])
    expect(stepNotices({ id: nextId(), sphere: '', cylinder: '', axis: '45', addition: '' }, 'OBJECTIVE')).toEqual(['Axe renseigné sans cylindre non nul.'])
    const draft = blankRefraction(nextId)
    draft.cells[measurementKey('OBJECTIVE', 'OD')] = cell
    expect(refractionErrors(draft)).toEqual([])
  })

  it('relire puis réécrire une rubrique enregistrée la rend à l’identique', () => {
    const saved = {
      measurements: [{ id: nextId(), stage: 'SUBJECTIVE' as const, eye: 'OD' as const, sphere: '-1.00', cylinder: '0.00', axis: 0, addition: '1.50' }],
      refraction_observations: 'Observation fictive',
      ametropias: [{ id: nextId(), term_code: 'MYOPIA', other_text: null }],
    }

    expect(refractionData(refractionDraft(saved, nextId))).toEqual(saved)
  })
})

describe('centrage', () => {
  it('rien n’est fabriqué : la DP monoculaire n’est pas déduite du total ; la somme est seulement proposée', () => {
    const draft = { ...blankCentering(nextId), pdTotal: '62', pdOd: '31', pdOg: '' }

    expect(centeringData(draft)).toEqual({ centering_id: draft.id, pd_total: '62.00', pd_od: '31.00', pd_og: null, inconsistency_reason: null })
    expect(suggestedSum(draft)).toBeNull()
    expect(suggestedSum({ pdOd: '31,5', pdOg: '30,5' })).toBe('62.00')
  })

  it('un centrage vide n’a pas de ligne (identifiant null)', () => {
    expect(centeringData(blankCentering(nextId)).centering_id).toBeNull()
    const saved = { centering_id: nextId(), pd_total: '62.00', pd_od: null, pd_og: null, inconsistency_reason: null }
    expect(centeringData(centeringDraft(saved, nextId))).toEqual(saved)
  })

  it('un écart OD + OG différent du total exige une justification ; justifié, il est admis', () => {
    const draft = { ...blankCentering(nextId), pdTotal: '62', pdOd: '31', pdOg: '30' }

    expect(centeringErrors(draft).map((error) => error.path)).toEqual(['inconsistency_reason'])
    expect(centeringErrors({ ...draft, reason: 'Mesures reprises (fictif)' })).toEqual([])
  })

  it('un écart nul ou négatif, ou une justification sans mesure, sont refusés', () => {
    expect(centeringErrors({ ...blankCentering(nextId), pdTotal: '0' }).map((error) => error.path)).toEqual(['pd_total'])
    expect(centeringErrors({ ...blankCentering(nextId), pdOd: '-31' }).map((error) => error.path)).toEqual(['pd_od'])
    expect(centeringErrors({ ...blankCentering(nextId), reason: 'Sans mesure' }).map((error) => error.path)).toEqual(['inconsistency_reason'])
  })
})
