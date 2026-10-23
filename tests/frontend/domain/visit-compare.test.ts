import { describe, expect, it } from 'vitest'

import { NOT_FILLED } from '@/domain/clinical/compare'
import {
  compareFacts,
  completedExams,
  defaultPair,
  latestValidated,
  prescriptionFacts,
  type PrescriptionFactsSource,
} from '@/domain/clinical/visitCompare'
import type {
  PatientActivityConsultation,
  PatientActivityItem,
  Prescription,
} from '@/domain/contracts/generated'

function exam(
  id: string,
  occurredAt: string,
  status: 'DRAFT' | 'IN_PROGRESS' | 'COMPLETED' = 'COMPLETED',
): PatientActivityConsultation {
  return {
    type: 'CONSULTATION',
    id,
    occurred_at: occurredAt,
    screen_path: null,
    status,
    clinician_name: 'Professionnel Fictif',
  }
}

function source(overrides: Partial<PrescriptionFactsSource> = {}): PrescriptionFactsSource {
  return {
    eyes: [
      {
        eye: 'OD',
        label: 'OD',
        noCorrection: false,
        sphere: '+1,25',
        cylinder: '-0,50',
        axis: '90',
        addition: '—',
        fittingHeight: '',
      },
      {
        eye: 'OG',
        label: 'OG',
        noCorrection: true,
        sphere: '',
        cylinder: '',
        axis: '',
        addition: '',
        fittingHeight: '',
      },
    ],
    pd: { total: '62', od: '—', og: '—' },
    usage: 'Vision de loin',
    lensType: null,
    lensIndex: null,
    treatments: [],
    notes: null,
    ...overrides,
  }
}

const A = '01JAAAAAAAAAAAAAAAAAAAAAAA'
const B = '01JBBBBBBBBBBBBBBBBBBBBBBB'
const C = '01JCCCCCCCCCCCCCCCCCCCCCCC'

describe('comparaison de deux examens (L1D08)', () => {
  it('ne garde que les examens terminés, du plus récent au plus ancien', () => {
    const items: PatientActivityItem[] = [
      exam(A, '2026-01-10T08:00:00.000Z'),
      exam(B, '2026-09-01T08:00:00.000Z', 'IN_PROGRESS'),
      exam(C, '2026-05-02T08:00:00.000Z'),
    ]
    expect(completedExams(items).map((entry) => entry.id)).toEqual([C, A])
  })

  it('propose l’avant-dernier en référence et respecte une paire demandée valide', () => {
    const exams = [exam(C, '2026-05-02T08:00:00.000Z'), exam(A, '2026-01-10T08:00:00.000Z')]
    expect(defaultPair(exams)).toEqual({ left: A, right: C })
    expect(defaultPair(exams, { left: C, right: A })).toEqual({ left: C, right: A })
    // Un examen inconnu ou deux fois le même : la paire par défaut reprend la main.
    expect(defaultPair(exams, { left: B, right: C })).toEqual({ left: A, right: C })
    expect(defaultPair(exams, { left: C, right: C })).toEqual({ left: A, right: C })
  })

  it('compare les prescriptions texte contre texte, une valeur absente n’est jamais zéro', () => {
    const before = prescriptionFacts(source())
    const after = prescriptionFacts(
      source({
        eyes: [{ ...source().eyes[0]!, sphere: '+1,50' }, source().eyes[1]!],
        lensType: 'Unifocal',
      }),
    )
    const comparison = compareFacts(before, after)
    const byKey = Object.fromEntries(comparison.rows.map((row) => [row.key, row]))
    expect(byKey['OD.sphere']).toMatchObject({ left: '+1,25', right: '+1,50', kind: 'changed' })
    expect(byKey['OD.addition']).toMatchObject({
      left: NOT_FILLED,
      right: NOT_FILLED,
      kind: 'same',
    })
    expect(byKey['OG.correction']).toMatchObject({ right: 'Aucune correction', kind: 'same' })
    expect(byKey['lens_type']).toMatchObject({ left: NOT_FILLED, right: 'Unifocal', kind: 'added' })
    expect(comparison.differences).toBe(2)
  })

  it('un examen sans prescription validée compte comme non renseigné', () => {
    const comparison = compareFacts(null, prescriptionFacts(source()))
    expect(comparison.rows.find((row) => row.key === 'OD.sphere')).toMatchObject({
      left: NOT_FILLED,
      right: '+1,25',
      kind: 'added',
    })
  })

  it('retient la dernière version validée, jamais un brouillon', () => {
    const versions = [
      { version_no: 3, status: 'DRAFT' },
      { version_no: 2, status: 'VALIDATED' },
      { version_no: 1, status: 'VALIDATED' },
    ] as unknown as Prescription[]
    expect(latestValidated(versions)?.version_no).toBe(2)
    expect(
      latestValidated([{ version_no: 1, status: 'DRAFT' }] as unknown as Prescription[]),
    ).toBeNull()
  })
})
