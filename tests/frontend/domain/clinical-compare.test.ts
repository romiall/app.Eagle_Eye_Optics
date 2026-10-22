// @vitest-environment node
import { describe, expect, it } from 'vitest'

import {
  CHANGE_LABELS,
  CHANGE_SYMBOLS,
  compareSteps,
  describeSteps,
  NOT_FILLED,
  NOT_MEASURED,
  type StepsData,
} from '@/domain/clinical/compare'

// Comparaison de deux révisions (C03 ; fichier 02, « Historique ») : fait par fait, « non renseigné » et
// « non mesuré » jamais confondus avec « aucun » ni zéro, changement dit en texte ET en symbole, acuités
// d'échelles différentes déclarées non comparables. Données FICTIVES.
const SCALE_A = '01J9ZXK4M2Q8R7S6T5V4W30701'
const SCALE_B = '01J9ZXK4M2Q8R7S6T5V4W30702'
const label = (scaleId: string): string =>
  scaleId === SCALE_A ? 'Dixièmes' : scaleId === SCALE_B ? 'Snellen' : scaleId

function steps(overrides: Partial<StepsData> = {}): StepsData {
  return {
    ANAMNESIS: {
      symptoms: null,
      wears_glasses: null,
      glasses_duration: null,
      previous_correction_available: null,
      complaints: [],
    },
    HISTORIES: { histories: [] },
    VISUAL_ACUITY: { acuities: [] },
    REFRACTION: { measurements: [], refraction_observations: null, ametropias: [] },
    CENTERING: {
      centering_id: null,
      pd_total: null,
      pd_od: null,
      pd_og: null,
      inconsistency_reason: null,
    },
    MEDICATIONS: { orders: [] },
    CONCLUSION: { conclusion: null, referral: null, referral_urgency: null, referral_reasons: [] },
    ...overrides,
  } as StepsData
}

function acuity(rawValue: string, scaleId: string) {
  return {
    acuities: [
      {
        id: '01J9ZXK4M2Q8R7S6T5V4W30A01',
        context: 'PRELIMINARY',
        distance: 'FAR',
        correction: 'WITHOUT',
        eye: 'OD',
        scale_id: scaleId,
        raw_value: rawValue,
        normalized_value: null,
      },
    ],
  } as StepsData['VISUAL_ACUITY']
}

describe('description d’une révision', () => {
  it('une révision vide dit « non renseigné » et « non mesuré », jamais « aucun » ni zéro', () => {
    const facts = describeSteps(steps(), label)

    const values = new Set(facts.map((fact) => fact.value))
    expect(values.has(NOT_FILLED)).toBe(true)
    expect(values.has(NOT_MEASURED)).toBe(true)
    expect(facts.some((fact) => /aucun|0,00/i.test(fact.value))).toBe(false)
    expect(
      facts.find((fact) => fact.step === 'ANAMNESIS' && fact.key === 'wears_glasses')?.value,
    ).toBe('Non renseigné')
  })

  it('une acuité est toujours rendue avec son échelle ; une mesure garde sa virgule et son signe', () => {
    const facts = describeSteps(
      steps({
        VISUAL_ACUITY: acuity('8/10', SCALE_A),
        REFRACTION: {
          measurements: [
            {
              id: '01J9ZXK4M2Q8R7S6T5V4W30A02',
              stage: 'SUBJECTIVE',
              eye: 'OD',
              sphere: '-1.25',
              cylinder: '0.00',
              axis: 0,
              addition: null,
            },
          ],
          refraction_observations: null,
          ametropias: [],
        },
      }),
      label,
    )

    expect(facts.find((fact) => fact.key === 'PRELIMINARY|FAR|WITHOUT|OD')?.value).toBe(
      '8/10 (échelle : Dixièmes)',
    )
    expect(facts.find((fact) => fact.key === 'SUBJECTIVE|OD')?.value).toBe(
      'Sphère -1,25 · Cylindre 0,00 · Axe 0° · Addition Non mesuré',
    )
  })
})

describe('comparaison de deux révisions', () => {
  it('deux révisions identiques : aucune différence', () => {
    const comparison = compareSteps(steps(), steps(), label)

    expect(comparison.differences).toBe(0)
    expect(comparison.rows.every((row) => row.kind === 'same')).toBe(true)
  })

  it('un changement est dit en texte et par un symbole : ajouté, retiré, modifié', () => {
    const left = steps({
      CONCLUSION: {
        conclusion: 'Ancienne conclusion',
        referral: false,
        referral_urgency: null,
        referral_reasons: [],
      },
    })
    const right = steps({
      ANAMNESIS: {
        symptoms: 'Gêne (fictif)',
        wears_glasses: null,
        glasses_duration: null,
        previous_correction_available: null,
        complaints: [],
      },
      CONCLUSION: {
        conclusion: 'Nouvelle conclusion',
        referral: null,
        referral_urgency: null,
        referral_reasons: [],
      },
    })

    const rows = compareSteps(left, right, label).rows
    const row = (step: string, key: string) =>
      rows.find((entry) => entry.step === step && entry.key === key)

    expect(row('ANAMNESIS', 'symptoms')?.kind).toBe('added')
    expect(row('CONCLUSION', 'conclusion')).toMatchObject({
      kind: 'changed',
      left: 'Ancienne conclusion',
      right: 'Nouvelle conclusion',
    })
    expect(row('CONCLUSION', 'referral')).toMatchObject({
      kind: 'removed',
      left: 'Non',
      right: 'Non renseigné',
    })
    for (const kind of ['same', 'changed', 'added', 'removed'] as const) {
      expect(CHANGE_LABELS[kind]).not.toBe('')
      expect(CHANGE_SYMBOLS[kind]).not.toBe('')
    }
  })

  it('des acuités d’échelles différentes sont déclarées non comparables, sans conversion', () => {
    const rows = compareSteps(
      steps({ VISUAL_ACUITY: acuity('8/10', SCALE_A) }),
      steps({ VISUAL_ACUITY: acuity('6/6', SCALE_B) }),
      label,
    ).rows

    const row = rows.find((entry) => entry.key === 'PRELIMINARY|FAR|WITHOUT|OD')
    expect(row).toMatchObject({
      kind: 'changed',
      left: '8/10 (échelle : Dixièmes)',
      right: '6/6 (échelle : Snellen)',
    })
    expect(row?.note).toContain('pas comparables')
  })

  it('un médicament retiré reste dans sa rubrique, dans l’ordre du dossier', () => {
    const order = {
      id: '01J9ZXK4M2Q8R7S6T5V4W30A03',
      catalog_item_id: null,
      name: 'Collyre fictif',
      form: 'DROPS',
      form_other: null,
      strength: null,
      dosage: null,
      frequency: null,
      duration_text: null,
      duration_value: null,
      duration_unit: null,
      instructions: null,
    }
    const left = steps({ MEDICATIONS: { orders: [order] } as StepsData['MEDICATIONS'] })

    const rows = compareSteps(left, steps(), label).rows
    const steps_ = rows.map((row) => row.step)

    const removed = rows.find((row) => row.key === `order:${order.id}`)
    expect(removed).toMatchObject({ kind: 'removed', left: 'Forme : Collyre', right: NOT_FILLED })
    expect(steps_.indexOf('MEDICATIONS')).toBeLessThan(steps_.indexOf('CONCLUSION'))
    expect(steps_.lastIndexOf('MEDICATIONS')).toBeLessThan(steps_.indexOf('CONCLUSION'))
  })
})
