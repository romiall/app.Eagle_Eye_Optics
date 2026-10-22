// @vitest-environment node
import { describe, expect, it } from 'vitest'

import {
  blankPrescription,
  buildCreatePayload,
  buildDraftChanges,
  buildValidatePayload,
  copyCentering,
  copyRefraction,
  isPrescriptionDirty,
  nextVersionNo,
  prescriptionContent,
  prescriptionDifferences,
  prescriptionDraft,
  prescriptionErrors,
  prescriptionErrorTarget,
  RX_IDS,
  withNewIds,
} from '@/domain/clinical/prescriptions'
import type { Prescription } from '@/domain/contracts/generated'

// Prescription optique (C04) : brouillon, copie EXPLICITE de la réfraction, règles de validation par œil,
// charges des commandes, différences d'un conflit. L'impression (documents de D02, seule source des valeurs
// imprimées et affichées) est testée dans tests/frontend/documents/clinical-print.test.ts. Données FICTIVES.
let counter = 0
const nextId = (): string => `01J9ZXK4M2Q8R7S6T5V4W3${String(counter++).padStart(4, '0')}`
const TODAY = '2026-10-22'

function saved(overrides: Partial<Prescription> = {}): Prescription {
  return {
    id: '01J9ZXK4M2Q8R7S6T5V4W30801',
    series_id: '01J9ZXK4M2Q8R7S6T5V4W30802',
    version_no: 1,
    supersedes_id: null,
    patient_id: '01J9ZXK4M2Q8R7S6T5V4W30001',
    origin: 'INTERNAL',
    consultation_id: '01J9ZXK4M2Q8R7S6T5V4W30501',
    external_prescriber: null,
    external_prescribed_on: null,
    external_file_id: null,
    status: 'DRAFT',
    usage_code: 'PERMANENT',
    usage_other: null,
    lens_type_code: 'SINGLE_VISION',
    lens_type_other: null,
    lens_index: '1.60',
    lens_index_other: null,
    pd_total: '62.00',
    pd_od: null,
    pd_og: null,
    pd_inconsistency_reason: null,
    notes: null,
    eyes: [
      {
        id: '01J9ZXK4M2Q8R7S6T5V4W30803',
        eye: 'OD',
        no_correction: false,
        sphere: '-1.25',
        cylinder: '-0.50',
        axis: 90,
        addition: null,
        fitting_height: null,
      },
      {
        id: '01J9ZXK4M2Q8R7S6T5V4W30804',
        eye: 'OG',
        no_correction: true,
        sphere: null,
        cylinder: null,
        axis: null,
        addition: null,
        fitting_height: null,
      },
    ],
    treatments: [
      { id: '01J9ZXK4M2Q8R7S6T5V4W30805', term_code: 'ANTI_REFLECTION', other_text: null },
    ],
    validated_at: null,
    validated_by: null,
    validated_by_name: null,
    document_id: null,
    updated_at: '2026-10-22T09:40:00.000Z',
    ...overrides,
  }
}

describe('brouillon et contenu', () => {
  it('relire puis réécrire une prescription la rend à l’identique (aucune modification)', () => {
    const prescription = saved()
    const draft = prescriptionDraft(prescription, nextId)

    expect(buildDraftChanges(draft, prescription)).toEqual({})
    expect(isPrescriptionDirty(draft, prescription)).toBe(false)
    expect(prescriptionContent(draft).eyes.map((eye) => eye.id)).toEqual([
      '01J9ZXK4M2Q8R7S6T5V4W30803',
      '01J9ZXK4M2Q8R7S6T5V4W30804',
    ])
  })

  it('seuls les champs modifiés partent dans la modification du brouillon', () => {
    const prescription = saved()
    const draft = {
      ...prescriptionDraft(prescription, nextId),
      notes: ' Port permanent (fictif) ',
      lensIndex: '1.67',
    }

    expect(buildDraftChanges(draft, prescription)).toEqual({
      notes: 'Port permanent (fictif)',
      lens_index: '1.67',
    })
  })

  it('un indice « autre » part avec sa précision et sans indice de la liste', () => {
    const content = prescriptionContent({
      ...blankPrescription('INTERNAL', nextId),
      lensIndex: 'OTHER',
      lensIndexOther: '1.53 (fictif)',
    })

    expect([content.lens_index, content.lens_index_other]).toEqual([null, '1.53 (fictif)'])
  })

  it('les champs d’une ordonnance externe ne partent que pour une ordonnance externe', () => {
    const internal = prescriptionContent({
      ...blankPrescription('INTERNAL', nextId),
      externalPrescriber: 'Dr Fictif',
      externalPrescribedOn: '2026-10-01',
    })
    const external = prescriptionContent({
      ...blankPrescription('EXTERNAL', nextId),
      externalPrescriber: ' Dr Fictif ',
      externalPrescribedOn: '2026-10-01',
    })

    expect([internal.external_prescriber, internal.external_prescribed_on]).toEqual([null, null])
    expect([external.external_prescriber, external.external_prescribed_on]).toEqual([
      'Dr Fictif',
      '2026-10-01',
    ])
  })
})

describe('copie explicite de la réfraction', () => {
  const measurements = [
    {
      id: nextId(),
      stage: 'SUBJECTIVE' as const,
      eye: 'OD' as const,
      sphere: '-1.25',
      cylinder: '0.75',
      axis: 180,
      addition: '2.00',
    },
    {
      id: nextId(),
      stage: 'OBJECTIVE' as const,
      eye: 'OG' as const,
      sphere: '-2.00',
      cylinder: null,
      axis: null,
      addition: null,
    },
  ]

  it('copie le stade choisi, œil par œil, sans transposer ni toucher l’œil non mesuré', () => {
    const draft = {
      ...blankPrescription('INTERNAL', nextId),
      eyes: { ...blankPrescription('INTERNAL', nextId).eyes },
    }
    draft.eyes.OG = { ...draft.eyes.OG, sphere: '-0,50', fittingHeight: '22' }

    const result = copyRefraction(draft, measurements, 'SUBJECTIVE')

    expect(result.copied).toEqual(['OD'])
    expect(result.draft.eyes.OD).toMatchObject({
      sphere: '-1,25',
      cylinder: '0,75',
      axis: '180',
      addition: '2,00',
      noCorrection: false,
    })
    expect(result.draft.eyes.OG).toEqual(draft.eyes.OG)
  })

  it('hors réfraction subjective, aucune addition n’est copiée', () => {
    const result = copyRefraction(blankPrescription('INTERNAL', nextId), measurements, 'OBJECTIVE')

    expect(result.copied).toEqual(['OG'])
    expect(result.draft.eyes.OG.addition).toBe('')
  })

  it('les écarts pupillaires copiés sont ceux du centrage, sans DP fabriquée', () => {
    const draft = copyCentering(blankPrescription('INTERNAL', nextId), {
      pd_total: '62.00',
      pd_od: null,
      pd_og: null,
    })

    expect([draft.pdTotal, draft.pdOd, draft.pdOg]).toEqual(['62,00', '', ''])
  })
})

describe('règles', () => {
  it('un brouillon incomplet est admis ; la validation exige une valeur ou « aucune correction » par œil', () => {
    const draft = blankPrescription('INTERNAL', nextId)

    expect(prescriptionErrors(draft, 'draft', TODAY)).toEqual([])
    expect(prescriptionErrors(draft, 'validate', TODAY).map((error) => error.path)).toEqual([
      'eyes.0.no_correction',
      'eyes.1.no_correction',
    ])
  })

  it('à la validation, un cylindre non nul exige un axe ; un cylindre nul n’en exige pas', () => {
    const draft = blankPrescription('INTERNAL', nextId)
    draft.eyes.OD = { ...draft.eyes.OD, sphere: '-1', cylinder: '-0,75' }
    draft.eyes.OG = { ...draft.eyes.OG, cylinder: '0' }

    const errors = prescriptionErrors(draft, 'validate', TODAY)

    expect(errors.map((error) => error.path)).toEqual(['eyes.0.axis'])
    expect(errors[0]?.controlId).toBe(RX_IDS.eye('OD', 'axis'))
  })

  it('« aucune correction » avec une valeur est contradictoire, dès le brouillon', () => {
    const draft = blankPrescription('INTERNAL', nextId)
    draft.eyes.OD = { ...draft.eyes.OD, noCorrection: true, sphere: '-1' }

    expect(prescriptionErrors(draft, 'draft', TODAY).map((error) => error.path)).toEqual([
      'eyes.0.no_correction',
    ])
  })

  it('une DP incohérente exige une justification, une DP nulle ou négative est refusée', () => {
    const draft = {
      ...blankPrescription('INTERNAL', nextId),
      pdTotal: '62',
      pdOd: '31',
      pdOg: '32',
    }

    expect(prescriptionErrors(draft, 'draft', TODAY).map((error) => error.path)).toEqual([
      'pd_inconsistency_reason',
    ])
    expect(
      prescriptionErrors({ ...draft, pdReason: 'Mesures reprises (fictif)' }, 'draft', TODAY),
    ).toEqual([])
    expect(
      prescriptionErrors(
        { ...blankPrescription('INTERNAL', nextId), pdTotal: '0' },
        'draft',
        TODAY,
      ).map((error) => error.path),
    ).toEqual(['pd_total'])
  })

  it('une ordonnance externe exige son prescripteur et une date passée', () => {
    const draft = blankPrescription('EXTERNAL', nextId)

    expect(prescriptionErrors(draft, 'draft', TODAY).map((error) => error.path)).toEqual([
      'external_prescriber',
      'external_prescribed_on',
    ])
    expect(
      prescriptionErrors(
        { ...draft, externalPrescriber: 'Dr Fictif', externalPrescribedOn: '2026-10-23' },
        'draft',
        TODAY,
      ).map((error) => error.path),
    ).toEqual(['external_prescribed_on'])
    expect(
      prescriptionErrors(
        { ...draft, externalPrescriber: 'Dr Fictif', externalPrescribedOn: TODAY },
        'draft',
        TODAY,
      ),
    ).toEqual([])
  })

  it('« Autre » exige sa précision pour l’usage, le type de verre et l’indice', () => {
    const draft = {
      ...blankPrescription('INTERNAL', nextId),
      usageCode: 'OTHER',
      lensTypeCode: 'OTHER',
      lensIndex: 'OTHER',
    }

    expect(prescriptionErrors(draft, 'draft', TODAY).map((error) => error.path)).toEqual([
      'usage_other',
      'lens_type_other',
      'lens_index_other',
    ])
  })
})

describe('charges, versions et erreurs du serveur', () => {
  it('la création porte toutes les clés du contrat, null explicite pour l’absent', () => {
    const payload = buildCreatePayload(
      {
        prescriptionId: 'P',
        seriesId: 'S',
        versionNo: 1,
        supersedesId: null,
        patientId: 'PAT',
        consultationId: 'C',
      },
      blankPrescription('INTERNAL', nextId),
    )

    expect(Object.keys(payload).sort()).toEqual([
      'consultation_id',
      'external_file_id',
      'external_prescribed_on',
      'external_prescriber',
      'eyes',
      'lens_index',
      'lens_index_other',
      'lens_type_code',
      'lens_type_other',
      'notes',
      'origin',
      'patient_id',
      'pd_inconsistency_reason',
      'pd_od',
      'pd_og',
      'pd_total',
      'prescription_id',
      'series_id',
      'supersedes_id',
      'treatments',
      'usage_code',
      'usage_other',
      'version_no',
    ])
    expect(buildValidatePayload('P', 'D')).toEqual({ prescription_id: 'P', document_id: 'D' })
  })

  it('une nouvelle version reprend le contenu avec des lignes NEUVES et le numéro suivant', () => {
    const draft = prescriptionDraft(saved({ status: 'VALIDATED' }), nextId)
    const copy = withNewIds(draft, nextId)

    expect(copy.eyes.OD.id).not.toBe(draft.eyes.OD.id)
    expect(copy.treatments[0]?.id).not.toBe(draft.treatments[0]?.id)
    expect(copy.eyes.OD.sphere).toBe(draft.eyes.OD.sphere)
    expect(nextVersionNo({ version_no: 3 })).toBe(4)
  })

  it('une erreur du serveur désigne le bon champ, préfixe « changes. » compris', () => {
    expect(prescriptionErrorTarget('eyes.1.axis')).toBe(RX_IDS.eye('OG', 'axis'))
    expect(prescriptionErrorTarget('changes.usage_other')).toBe(RX_IDS.usageOther)
    expect(prescriptionErrorTarget('treatments.0.other_text')).toBe(RX_IDS.treatmentOther)
    expect(prescriptionErrorTarget('inconnu')).toBe(RX_IDS.form)
  })

  it('un conflit montre les champs que ma saisie et la version actuelle n’ont pas en commun', () => {
    const mine = { ...prescriptionDraft(saved(), nextId), notes: 'Ma note' }
    const current = { ...prescriptionDraft(saved(), nextId), lensIndex: '1.74' }

    expect(
      prescriptionDifferences(mine, current).map((difference) => [
        difference.key,
        difference.mine,
        difference.current,
      ]),
    ).toEqual([
      ['lens_index', '1.60', '1.74'],
      ['notes', 'Ma note', 'vide'],
    ])
  })
})
