import { describe, expect, it } from 'vitest'

import type { PatientMergeIdentity } from '@/domain/contracts/generated'
import { checkMergeReason, compareIdentities, differenceCount } from '@/domain/patients/merge'

function identity(overrides: Partial<PatientMergeIdentity> = {}): PatientMergeIdentity {
  return {
    id: '01JAAAAAAAAAAAAAAAAAAAAAAA',
    record_number: 'TEST-PAT-000001',
    last_name: 'Patient Fictif',
    first_names: 'Test',
    sex: 'F',
    birth_date: '1990-04-12',
    phone_primary: '+237600000001',
    archived: false,
    merged_into_patient_id: null,
    version: 1,
    legacy_record_number: null,
    created_at: '2026-10-22T09:30:00.000Z',
    ...overrides,
  }
}

const date = (civil: string): string => civil.split('-').reverse().join('/')

describe('fusion de deux fiches (L1D08)', () => {
  it('exige un motif de 5 à 1000 caractères, blancs de bord retirés', () => {
    expect(checkMergeReason('  ok  ')).toEqual({
      ok: false,
      message: 'Indiquez le motif de la fusion (au moins 5 caractères).',
    })
    expect(checkMergeReason('x'.repeat(1001)).ok).toBe(false)
    expect(checkMergeReason('  Doublon accueil  ')).toEqual({ ok: true, reason: 'Doublon accueil' })
  })

  it('aligne les identités et ne signale que les vraies différences', () => {
    const rows = compareIdentities(
      identity(),
      identity({
        id: '01JBBBBBBBBBBBBBBBBBBBBBBB',
        record_number: 'TEST-PAT-000002',
        last_name: 'PATIENT FICTIF',
        phone_primary: null,
      }),
      date,
    )
    const byLabel = Object.fromEntries(rows.map((row) => [row.label, row]))
    expect(byLabel['Numéro de dossier']?.differs).toBe(false)
    expect(byLabel['Nom']?.differs).toBe(false)
    expect(byLabel['Téléphone principal']).toMatchObject({
      source: '+237600000001',
      survivor: 'Non renseigné',
      differs: true,
    })
    expect(byLabel['Date de naissance']?.source).toBe('12/04/1990')
    expect(differenceCount(rows)).toBe(1)
  })
})
