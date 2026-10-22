// @vitest-environment node
import { describe, expect, it } from 'vitest'

import {
  completionReasonError,
  completionSummary,
  isStepEmpty,
  missingDataText,
  type StepsData,
} from '@/domain/clinical/completion'
import {
  blankOrder,
  durationNumber,
  medicationsData,
  medicationsDraft,
  medicationsErrors,
  missingPosology,
  moveOrder,
} from '@/domain/clinical/medications'

// Médicaments (C04 : saisie libre contrôlée, aucune posologie déduite, ordre conservé) et récapitulatif de
// finalisation (C03 : rubriques non renseignées dites telles, justification si motif ou conclusion manquent,
// mesures jamais exigées). Jumeaux des règles du serveur. Données FICTIVES.
let counter = 0
const nextId = (): string => `01J9ZXK4M2Q8R7S6T5V4W3${String(counter++).padStart(4, '0')}`

function order(overrides: Partial<ReturnType<typeof blankOrder>> = {}) {
  return { ...blankOrder(nextId), name: 'Collyre fictif', form: 'DROPS' as const, ...overrides }
}

describe('médicaments', () => {
  it('rend chaque ligne telle que saisie, dans l’ordre, sans rien compléter', () => {
    const first = order({ name: '  Collyre A ', frequency: ' 3 fois par jour ', durationValue: '7', durationUnit: 'DAY' })
    const second = order({ name: 'Complément B', form: 'OTHER', formOther: 'Sachet' })

    const data = medicationsData({ orders: [first, second] })

    expect(data.orders.map((item) => item.name)).toEqual(['Collyre A', 'Complément B'])
    expect(data.orders[0]).toMatchObject({ frequency: '3 fois par jour', duration_value: 7, duration_unit: 'DAY', dosage: null, instructions: null, strength: null })
    expect(data.orders[1]).toMatchObject({ form: 'OTHER', form_other: 'Sachet' })
    expect(medicationsErrors({ orders: [first, second] })).toEqual([])
  })

  it('une précision de forme n’est envoyée que pour « Autre »', () => {
    const data = medicationsData({ orders: [order({ form: 'DROPS', formOther: 'Ignorée' })] })

    expect(data.orders[0]?.form_other).toBeNull()
  })

  it('signale chaque défaut à sa ligne : nom, forme, précision, durée incomplète ou invalide', () => {
    const errors = medicationsErrors({
      orders: [
        order({ name: '   ' }),
        order({ form: null }),
        order({ form: 'OTHER', formOther: '' }),
        order({ durationValue: '0', durationUnit: 'DAY' }),
        order({ durationValue: '3' }),
        order({ durationUnit: 'WEEK' }),
        order({ name: 'x'.repeat(181) }),
      ],
    })

    expect(errors.map((error) => error.path)).toEqual([
      'orders.0.name',
      'orders.1.form',
      'orders.2.form_other',
      'orders.3.duration_value',
      'orders.4.duration_unit',
      'orders.5.duration_value',
      'orders.6.name',
    ])
    expect(errors[0]?.message).toBe('Médicament 1 : le nom du médicament est obligatoire.')
  })

  it('au plus vingt médicaments', () => {
    const orders = Array.from({ length: 21 }, () => order())

    expect(medicationsErrors({ orders }).map((error) => error.path)).toEqual(['orders'])
  })

  it('la durée structurée est un entier strictement positif', () => {
    expect(durationNumber('7')).toBe(7)
    expect(durationNumber(' 12 ')).toBe(12)
    for (const refused of ['0', '-1', '1,5', '1.5', 'sept', '']) {
      expect(durationNumber(refused), refused).toBeNull()
    }
  })

  it('une ligne sans posologie, fréquence, durée ni instructions est signalée sans être bloquée', () => {
    const draft = { orders: [order(), order({ dosage: '1 goutte' })] }

    expect(missingPosology(draft)).toEqual([0])
    expect(medicationsErrors(draft)).toEqual([])
  })

  it('l’ordre se change ligne par ligne, et une ligne au bord ne sort pas de la liste', () => {
    const [a, b, c] = [order({ name: 'A' }), order({ name: 'B' }), order({ name: 'C' })]
    const draft = { orders: [a, b, c] }

    expect(moveOrder(draft, 0, 1).orders.map((item) => item.name)).toEqual(['B', 'A', 'C'])
    expect(moveOrder(draft, 0, -1)).toBe(draft)
    expect(moveOrder(draft, 2, 1)).toBe(draft)
  })

  it('relire puis réécrire la rubrique la rend à l’identique', () => {
    const saved = {
      orders: [
        {
          id: nextId(), catalog_item_id: null, name: 'Collyre fictif', form: 'DROPS' as const, form_other: null, strength: '0,5 %',
          dosage: '1 goutte', frequency: '3 fois par jour', duration_text: null, duration_value: 7, duration_unit: 'DAY' as const, instructions: null,
        },
      ],
    }

    expect(medicationsData(medicationsDraft(saved))).toEqual(saved)
  })
})

function steps(overrides: Partial<StepsData> = {}): StepsData {
  return {
    ANAMNESIS: { symptoms: null, wears_glasses: null, glasses_duration: null, previous_correction_available: null, complaints: [] },
    HISTORIES: { histories: [] },
    VISUAL_ACUITY: { acuities: [] },
    REFRACTION: { measurements: [], refraction_observations: null, ametropias: [] },
    CENTERING: { centering_id: null, pd_total: null, pd_od: null, pd_og: null, inconsistency_reason: null },
    MEDICATIONS: { orders: [] },
    CONCLUSION: { conclusion: null, referral: null, referral_urgency: null, referral_reasons: [] },
    ...overrides,
  } as StepsData
}

describe('récapitulatif de finalisation', () => {
  it('un dossier vide : toutes les rubriques sont non renseignées et la justification est exigée', () => {
    const summary = completionSummary(steps())

    expect(summary.emptySteps).toEqual(['ANAMNESIS', 'HISTORIES', 'VISUAL_ACUITY', 'REFRACTION', 'CENTERING', 'MEDICATIONS', 'CONCLUSION'])
    expect(summary.reasonRequired).toBe(true)
    expect(missingDataText(summary)).toBe('le motif ou les plaintes et la conclusion')
    expect(completionReasonError(summary, '   ')).toContain('Il manque le motif ou les plaintes et la conclusion')
    expect(completionReasonError(summary, 'Patient parti (fictif)')).toBeNull()
  })

  it('motif et conclusion renseignés : aucune justification ; les mesures non réalisées ne sont jamais exigées', () => {
    const summary = completionSummary(
      steps({
        ANAMNESIS: { symptoms: 'Gêne (fictif)', wears_glasses: null, glasses_duration: null, previous_correction_available: null, complaints: [] },
        CONCLUSION: { conclusion: 'Conclusion fictive', referral: false, referral_urgency: null, referral_reasons: [] },
      }),
    )

    expect(summary.reasonRequired).toBe(false)
    expect(summary.emptySteps).toEqual(['HISTORIES', 'VISUAL_ACUITY', 'REFRACTION', 'CENTERING', 'MEDICATIONS'])
    expect(completionReasonError(summary, '')).toBeNull()
  })

  it('une plainte suffit comme motif ; seule la conclusion manquante est alors dite', () => {
    const summary = completionSummary(
      steps({
        ANAMNESIS: { symptoms: null, wears_glasses: null, glasses_duration: null, previous_correction_available: null, complaints: [{ id: nextId(), term_code: 'PAIN', other_text: null }] },
      }),
    )

    expect(summary.missingMotif).toBe(false)
    expect(missingDataText(summary)).toBe('la conclusion')
  })

  it('« non » est une réponse : une orientation « Non » rend la conclusion renseignée, pas vide', () => {
    const data = steps({ CONCLUSION: { conclusion: null, referral: false, referral_urgency: null, referral_reasons: [] } })

    expect(isStepEmpty('CONCLUSION', data)).toBe(false)
    expect(completionSummary(data).missingConclusion).toBe(true)
  })

  it('un centrage n’est renseigné que s’il a une ligne', () => {
    expect(isStepEmpty('CENTERING', steps())).toBe(true)
    expect(isStepEmpty('CENTERING', steps({ CENTERING: { centering_id: nextId(), pd_total: '62.00', pd_od: null, pd_og: null, inconsistency_reason: null } }))).toBe(false)
  })
})
