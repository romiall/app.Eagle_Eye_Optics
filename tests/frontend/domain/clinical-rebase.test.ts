import { describe, expect, it } from 'vitest'

import { rebaseConsultation, rebaseStep } from '@/domain/clinical/rebase'
import {
  type AnamnesisData,
  buildStepData,
  type ConclusionData,
  draftFromData,
  type HistoriesData,
  setOtherText,
  type TermData,
} from '@/domain/clinical/steps'

// Reprise d'une saisie après un conflit de version (AT19, AT42) : une valeur non touchée reprend le
// serveur, une valeur modifiée garde la saisie, une valeur modifiée des deux côtés est signalée. Les
// termes se combinent un par un. Données FICTIVES.
let counter = 0
const nextId = (): string => `01J9ZXK4M2Q8R7S6T5V4W4${String(++counter).padStart(4, '0')}`

function term(code: string, id = nextId(), otherText: string | null = null): TermData {
  return { id, term_code: code, other_text: otherText }
}

function anamnesis(overrides: Partial<AnamnesisData> = {}): AnamnesisData {
  return {
    symptoms: null,
    wears_glasses: null,
    glasses_duration: null,
    previous_correction_available: null,
    complaints: [],
    ...overrides,
  }
}

function conclusion(overrides: Partial<ConclusionData> = {}): ConclusionData {
  return {
    conclusion: null,
    referral: null,
    referral_urgency: null,
    referral_reasons: [],
    ...overrides,
  }
}

describe('ANAMNESIS : reprise valeur par valeur', () => {
  it('une valeur que je n’ai pas touchée reprend celle du serveur ; celle que j’ai changée est gardée', () => {
    const base = anamnesis({ symptoms: 'Gêne le soir', complaints: [term('PAIN')] })
    const latest = anamnesis({ symptoms: 'Gêne le soir et le matin', complaints: [term('PAIN')] })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.wearsGlasses = true

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    expect(result.draft.symptoms).toBe('Gêne le soir et le matin')
    expect(result.draft.wearsGlasses).toBe(true)
    expect(result.conflicts).toEqual([])
  })

  it('cocher des termes différents sur deux postes se combine sans conflit, avec les identifiants de chacun', () => {
    const pain = term('PAIN', 'ID-PAIN')
    const base = anamnesis({ complaints: [pain] })
    const redness = term('REDNESS', 'ID-REDNESS')
    const latest = anamnesis({ complaints: [pain, redness] })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.complaints = [{ id: 'ID-HEADACHE', code: 'HEADACHE', otherText: '' }, ...mine.complaints]

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    expect(result.conflicts).toEqual([])
    expect(result.draft.complaints.map((row) => [row.code, row.id])).toEqual([
      // Ordre du référentiel : céphalées, rougeur, douleur.
      ['HEADACHE', 'ID-HEADACHE'],
      ['REDNESS', 'ID-REDNESS'],
      ['PAIN', 'ID-PAIN'],
    ])
  })

  it('une valeur modifiée des deux côtés garde ma saisie et est signalée avec la valeur actuelle', () => {
    const base = anamnesis({ symptoms: 'Gêne' })
    const latest = anamnesis({ symptoms: 'Gêne à la lecture' })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.symptoms = 'Gêne au volant'

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    expect(result.draft.symptoms).toBe('Gêne au volant')
    expect(result.conflicts).toEqual([
      {
        key: 'symptoms',
        label: 'Plaintes et symptômes',
        current: 'Gêne à la lecture',
      },
    ])
  })

  it('modifiée des deux côtés vers la MÊME valeur : pas de conflit', () => {
    const base = anamnesis({ symptoms: 'Gêne' })
    const latest = anamnesis({ symptoms: 'Gêne à la lecture' })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.symptoms = '  Gêne à la lecture '

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    expect(result.draft.symptoms).toBe('Gêne à la lecture')
    expect(result.conflicts).toEqual([])
  })

  it('un terme décoché ici et laissé là-bas : ma suppression est gardée sans conflit ; décoché là-bas, repris', () => {
    const base = anamnesis({ complaints: [term('PAIN'), term('REDNESS')] })
    const latest = anamnesis({ complaints: [term('PAIN')] })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.complaints = mine.complaints.filter((row) => row.code !== 'PAIN')

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    // PAIN : j'ai décoché, l'autre poste l'a laissé (== base) → décoché. REDNESS : je n'ai rien fait,
    // l'autre poste l'a décoché → décoché.
    expect(result.draft.complaints).toEqual([])
    expect(result.conflicts).toEqual([])
  })

  it('« Autre » précisé différemment des deux côtés : conflit, ma précision est gardée', () => {
    const base = anamnesis({ complaints: [term('OTHER', 'ID-O', 'Gêne')] })
    const latest = anamnesis({ complaints: [term('OTHER', 'ID-O', 'Gêne au soleil')] })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.complaints = setOtherText(mine.complaints, 'OTHER', 'Gêne la nuit')

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    expect(result.draft.complaints).toEqual([
      { id: 'ID-O', code: 'OTHER', otherText: 'Gêne la nuit' },
    ])
    expect(result.conflicts).toEqual([
      { key: 'complaint:OTHER', label: 'Motif « Autre »', current: 'coché (Gêne au soleil)' },
    ])
  })

  it('l’autre poste a répondu « porte des lunettes » avec la durée : repris, et une durée cachée reste cachée', () => {
    const base = anamnesis()
    const latest = anamnesis({
      wears_glasses: true,
      glasses_duration: '2 ans',
      previous_correction_available: true,
    })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.symptoms = 'Maux de tête'

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    expect(result.draft).toMatchObject({
      symptoms: 'Maux de tête',
      wearsGlasses: true,
      glassesDuration: '2 ans',
      previousCorrectionAvailable: true,
    })
  })

  it('mon « Non » aux lunettes face à une durée saisie ailleurs : les contraintes du serveur sont rétablies', () => {
    const base = anamnesis({ wears_glasses: true, glasses_duration: '1 an' })
    const latest = anamnesis({ wears_glasses: true, glasses_duration: '2 ans' })
    const mine = draftFromData('ANAMNESIS', base, nextId)
    mine.wearsGlasses = false

    const result = rebaseStep('ANAMNESIS', base, latest, mine, nextId)

    expect(result.draft.wearsGlasses).toBe(false)
    expect(buildStepData('ANAMNESIS', result.draft).glasses_duration).toBeNull()
    expect(result.conflicts.map((conflict) => conflict.key)).toContain('glasses_duration')
  })
})

describe('HISTORIES : reprise par groupe', () => {
  const group = (
    history_group: 'OCULAR_HISTORY' | 'MEDICAL_HISTORY' | 'FAMILY_HISTORY',
    overrides: Partial<HistoriesData['histories'][number]> = {},
  ): HistoriesData['histories'][number] => ({
    id: nextId(),
    history_group,
    known_none: false,
    comments: null,
    terms: [],
    ...overrides,
  })

  it('un groupe rempli ailleurs est repris tel quel quand je ne l’ai pas touché', () => {
    const base: HistoriesData = { histories: [] }
    const latest: HistoriesData = {
      histories: [group('OCULAR_HISTORY', { terms: [term('GLAUCOMA', 'ID-G')] })],
    }
    const mine = draftFromData('HISTORIES', base, nextId)
    mine.FAMILY_HISTORY = { ...mine.FAMILY_HISTORY, comments: 'Père diabétique' }

    const result = rebaseStep('HISTORIES', base, latest, mine, nextId)

    expect(result.conflicts).toEqual([])
    expect(result.draft.OCULAR_HISTORY.terms).toEqual([
      { id: 'ID-G', code: 'GLAUCOMA', otherText: '' },
    ])
    expect(result.draft.FAMILY_HISTORY.comments).toBe('Père diabétique')
  })

  it('mon « Aucun connu » face à une valeur ajoutée ailleurs : la valeur est gardée, « Aucun connu » retiré et signalé', () => {
    const base: HistoriesData = { histories: [] }
    const latest: HistoriesData = {
      histories: [group('MEDICAL_HISTORY', { terms: [term('DIABETES')] })],
    }
    const mine = draftFromData('HISTORIES', base, nextId)
    mine.MEDICAL_HISTORY = { ...mine.MEDICAL_HISTORY, knownNone: true }

    const result = rebaseStep('HISTORIES', base, latest, mine, nextId)

    expect(result.draft.MEDICAL_HISTORY.knownNone).toBe(false)
    expect(result.draft.MEDICAL_HISTORY.terms.map((row) => row.code)).toEqual(['DIABETES'])
    expect(result.conflicts).toEqual([
      expect.objectContaining({
        key: 'MEDICAL_HISTORY|none',
        label: 'Antécédents médicaux : Aucun connu',
      }),
    ])
  })

  it('un groupe encore « non renseigné » des deux côtés le reste : jamais « Aucun connu » par défaut', () => {
    const base: HistoriesData = { histories: [] }
    const mine = draftFromData('HISTORIES', base, nextId)
    mine.OCULAR_HISTORY = { ...mine.OCULAR_HISTORY, comments: 'Lunettes depuis 10 ans' }

    const result = rebaseStep('HISTORIES', base, base, mine, nextId)

    expect(result.draft.MEDICAL_HISTORY.knownNone).toBe(false)
    expect(buildStepData('HISTORIES', result.draft).histories.map((h) => h.history_group)).toEqual([
      'OCULAR_HISTORY',
    ])
  })
})

describe('CONCLUSION : reprise', () => {
  it('mon « Non » à l’orientation face à un niveau modifié ailleurs : le niveau est signalé, jamais envoyé', () => {
    const base = conclusion({ referral: true, referral_urgency: 'RECOMMENDED' })
    const latest = conclusion({ referral: true, referral_urgency: 'EMERGENCY' })
    const mine = draftFromData('CONCLUSION', base, nextId)
    mine.referral = false

    const result = rebaseStep('CONCLUSION', base, latest, mine, nextId)

    expect(result.draft.referral).toBe(false)
    expect(buildStepData('CONCLUSION', result.draft).referral_urgency).toBeNull()
    expect(result.conflicts).toEqual([
      {
        key: 'referral_urgency',
        label: 'Niveau d’urgence de l’orientation',
        current: 'Urgence',
      },
    ])
  })

  it('une conclusion saisie ici et un motif d’orientation coché là se combinent', () => {
    const base = conclusion({ referral: true, referral_urgency: 'RAPID' })
    const latest = conclusion({
      referral: true,
      referral_urgency: 'RAPID',
      referral_reasons: [term('CATARACT', 'ID-C')],
    })
    const mine = draftFromData('CONCLUSION', base, nextId)
    mine.conclusion = 'Suspicion de cataracte'

    const result = rebaseStep('CONCLUSION', base, latest, mine, nextId)

    expect(result.conflicts).toEqual([])
    expect(result.draft.conclusion).toBe('Suspicion de cataracte')
    expect(result.draft.reasons).toEqual([{ id: 'ID-C', code: 'CATARACT', otherText: '' }])
    expect(result.draft.urgency).toBe('RAPID')
  })
})

describe('rebaseConsultation', () => {
  it('une étape non modifiée reprend le serveur ; une étape modifiée est rebasée ; les conflits sont rangés par étape', () => {
    const base = {
      ANAMNESIS: anamnesis({ symptoms: 'A' }),
      HISTORIES: { histories: [] } as HistoriesData,
      CONCLUSION: conclusion({ conclusion: 'C1' }),
    }
    const latest = {
      ANAMNESIS: anamnesis({ symptoms: 'A (autre poste)' }),
      HISTORIES: {
        histories: [
          {
            id: 'ID-H',
            history_group: 'OCULAR_HISTORY' as const,
            known_none: true,
            comments: null,
            terms: [],
          },
        ],
      },
      CONCLUSION: conclusion({ conclusion: 'C1 (autre poste)' }),
    }
    const current = {
      ANAMNESIS: draftFromData('ANAMNESIS', base.ANAMNESIS, nextId),
      HISTORIES: draftFromData('HISTORIES', base.HISTORIES, nextId),
      CONCLUSION: draftFromData('CONCLUSION', base.CONCLUSION, nextId),
    }
    current.CONCLUSION.conclusion = 'C1 (mon poste)'

    const result = rebaseConsultation(base, latest, current, nextId)

    expect(result.drafts.ANAMNESIS.symptoms).toBe('A (autre poste)')
    expect(result.drafts.HISTORIES.OCULAR_HISTORY).toMatchObject({ id: 'ID-H', knownNone: true })
    expect(result.drafts.CONCLUSION.conclusion).toBe('C1 (mon poste)')
    expect(result.conflicts.ANAMNESIS).toEqual([])
    expect(result.conflicts.HISTORIES).toEqual([])
    expect(result.conflicts.CONCLUSION).toEqual([
      { key: 'conclusion', label: 'Conclusion', current: 'C1 (autre poste)' },
    ])
  })
})
