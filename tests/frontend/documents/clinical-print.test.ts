// @vitest-environment node
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  clinicalPatientFromRevision,
  consultationEntryFromRevision,
  consultationRecordFromRevision,
  medicationOrderFromRevision,
  prescriptionBodyFromResource,
  referralLetterFromRevision,
} from '@/documents/data/clinicalAdapters'
import { clinicalLabelsWithScales, defaultClinicalLabels } from '@/documents/data/clinicalLabels'
import {
  LENS_TREATMENT_LABELS,
  LENS_TYPE_LABELS,
  OPTICAL_USAGE_LABELS,
} from '@/documents/data/labels'
import { clinicFromSummary } from '@/documents/data/clinic'
import { demoClinic } from '@/documents/demo/fixtures'
import { MISSING } from '@/documents/engine/format'
import type { DocumentModel, TableBlock } from '@/documents/engine/model'
import { documentFileName } from '@/documents/output/fileName'
import { buildDocument, CLINICAL_TEMPLATE_VERSIONS } from '@/documents/templates/registry'
import { CORRECTION_TITLES } from '@/domain/clinical/acuity'
import { MEDICATION_FORM_LABELS, STAGE_LABELS } from '@/domain/clinical/labels'
import { CLINICAL_TERMS } from '@/domain/clinical/terms'
import type {
  ClinicalRevisionDetail,
  ClinicalRevisionSnapshot,
  ConsultationPatient,
  Prescription,
} from '@/domain/contracts/generated'

import { FORBIDDEN_WORDS, layout, outOfBounds, printedText } from './support'

// Impression des documents cliniques (D02, C04) : chaque document d'un examen se construit de la RÉVISION
// FIGÉE (fixture du contrat `snapshots/clinical-revision.json`), jamais de la saisie en cours ; une seule
// source de libellés pour l'écran et le document ; aucune donnée clinique dans le nom du fichier.
// Données FICTIVES.
const ROOT = join(import.meta.dirname, '..', '..', '..')

const SNAPSHOT = (
  JSON.parse(
    readFileSync(join(ROOT, 'contracts', 'fixtures', 'snapshots', 'clinical-revision.valid.json'), 'utf8'),
  ) as { data: ClinicalRevisionSnapshot }
).data

const REVISION_ID = '01J9ZXK4M2Q8R7S6T5V4W3001R'
const OTHER_PATIENT = '01J9ZXK4M2Q8R7S6T5V4W3009Z'

function revision(
  snapshot: Partial<ClinicalRevisionSnapshot> = {},
  steps: Partial<ClinicalRevisionSnapshot['steps']> = {},
): ClinicalRevisionDetail {
  const base = structuredClone(SNAPSHOT)
  const merged = { ...base, ...snapshot, steps: { ...base.steps, ...steps } }
  return {
    id: REVISION_ID,
    revision_no: merged.revision_no,
    recorded_at: merged.recorded_at,
    author_id: merged.author.user_id,
    reason: merged.revision_no === 1 ? 'Finalisation de la consultation' : 'Correction fictive',
    previous_revision_id: merged.previous_revision_id,
    sha256: 'a'.repeat(64),
    snapshot: merged,
  }
}

const CURRENT_PATIENT: ConsultationPatient = {
  id: SNAPSHOT.patient.patient_id,
  record_number: SNAPSHOT.patient.record_number,
  last_name: 'NOM ACTUEL FICTIF',
  first_names: null,
  sex: 'F',
  birth_date: '1990-05-17',
}

const labels = defaultClinicalLabels()
const PATIENT = clinicalPatientFromRevision(SNAPSHOT.patient, CURRENT_PATIENT)
const base = () => ({ revision: revision(), clinic: demoClinic(), patient: PATIENT, labels })

function firstTable(model: DocumentModel, caption: string): TableBlock {
  const found = model.body.find(
    (block): block is TableBlock => block.kind === 'table' && block.caption === caption,
  )
  if (found === undefined) throw new Error(`Tableau « ${caption} » absent.`)
  return found
}

function noForbiddenWords(text: string): void {
  for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word)
  expect(text).not.toContain('FCFA')
}

describe('patient d’un document tiré d’une révision', () => {
  it('nom, prénoms et dossier sont ceux FIGÉS dans la révision, jamais l’identité actuelle', () => {
    expect(PATIENT).toEqual({
      recordNumber: 'TEST-P-000001',
      lastName: 'PATIENT FICTIF',
      firstNames: 'Élodie Marie',
      sex: 'Féminin',
      birthDate: '1990-05-17',
    })
  })

  it('sexe et naissance (non figés) ne viennent que du MÊME patient, sinon ils ne sont pas imprimés', () => {
    expect(clinicalPatientFromRevision(SNAPSHOT.patient, { ...CURRENT_PATIENT, id: OTHER_PATIENT })).toMatchObject({
      sex: null,
      birthDate: null,
    })
    expect(clinicalPatientFromRevision(SNAPSHOT.patient, null)).toMatchObject({ sex: null, birthDate: null })
    expect(clinicalPatientFromRevision(SNAPSHOT.patient, { ...CURRENT_PATIENT, sex: 'UNKNOWN' }).sex).toBeNull()
  })
})

describe('fiche de consultation (révision figée)', () => {
  it('reprend la révision telle qu’enregistrée : auteur, version, rubriques, médicaments', () => {
    const entry = consultationEntryFromRevision(revision(), labels)

    expect(entry).toMatchObject({
      consultationId: SNAPSHOT.consultation_id,
      revisionNo: 1,
      status: 'COMPLETED',
      effectiveAt: SNAPSHOT.effective_at,
      clinicianName: 'Professionnel (démonstration)',
      symptoms: 'Gêne en vision de près (fictif)',
      wearsGlasses: true,
      glassesDuration: '3 ans',
      previousCorrectionAvailable: null,
      conclusion: 'Conclusion fictive',
      referral: false,
      missingDataReason: null,
      amendmentReason: null,
    })
    expect(entry.refraction).toEqual([
      { stage: 'Réfraction subjective', eye: 'OD', sphere: '-1.25', cylinder: null, axis: null, addition: null },
    ])
    expect(entry.medications).toEqual([
      {
        name: 'Collyre fictif (démonstration)',
        form: 'Collyre',
        strength: null,
        posology: null,
        frequency: null,
        duration: null,
        instructions: null,
      },
    ])
  })

  it('une révision corrigée garde son numéro, son auteur et le motif de la correction', () => {
    const amended = revision({
      revision_no: 2,
      previous_revision_id: '01J9ZXK4M2Q8R7S6T5V4W3001Q',
      amendment_reason: 'Correction fictive de la conclusion',
      author: { user_id: '01J9ZXK4M2Q8R7S6T5V4W30007', display_name: 'Gérante (démonstration)' },
    })
    const entry = consultationEntryFromRevision(amended, labels)

    expect(entry.revisionNo).toBe(2)
    expect(entry.clinicianName).toBe('Gérante (démonstration)')
    expect(entry.amendmentReason).toBe('Correction fictive de la conclusion')
  })

  it('imprime l’auteur et la version, les médicaments, les seules prescriptions VALIDÉES, sans aucun montant', () => {
    const validated = prescription({ status: 'VALIDATED', validated_at: '2026-10-14T09:40:00.000Z' })
    const draft = prescription({ id: '01J9ZXK4M2Q8R7S6T5V4W3002D', version_no: 2, status: 'DRAFT' })
    const data = consultationRecordFromRevision({
      ...base(),
      templateVersion: CLINICAL_TEMPLATE_VERSIONS.CLINICAL_RECORD,
      prescriptions: [draft, validated],
      exportedAt: '2026-10-22T09:00:00.000Z',
      exportedByName: 'Professionnel (démonstration)',
    })
    const model = buildDocument('CLINICAL_RECORD', data)
    const plan = layout(model)
    const text = printedText(plan)

    expect(data.selection).toBe('SINGLE')
    expect(data.prescriptions.map((summary) => [summary.versionNo, summary.status])).toEqual([[1, 'VALIDATED']])
    expect(model.templateVersion).toBe('clinical_record.v1')
    expect(text).toContain('FICHE DE CONSULTATION')
    expect(text).toContain('Auteur de la révision | Professionnel (démonstration)')
    expect(text).toContain('Consultation du 14/10/2026 (version 1, terminée)')
    expect(text).toContain('Prescription optique, version 1 (validée, 14/10/2026)')
    expect(firstTable(model, 'Médicaments prescrits').rows[0]?.cells).toEqual([
      'Collyre fictif (démonstration)',
      'Collyre',
      MISSING,
      MISSING,
      MISSING,
      MISSING,
      MISSING,
    ])
    expect(text).not.toContain('brouillon')
    expect(outOfBounds(plan)).toEqual([])
    noForbiddenWords(text)
  })
})

describe('ordonnance de médicaments (révision figée)', () => {
  it('n’existe que si la rubrique Médicaments est renseignée', () => {
    expect(
      medicationOrderFromRevision({
        ...base(),
        revision: revision({}, { MEDICATIONS: { orders: [] } }),
        templateVersion: CLINICAL_TEMPLATE_VERSIONS.MEDICATION_ORDER,
      }),
    ).toBeNull()
  })

  it('reprend la saisie telle quelle, dans l’ordre ; rien n’est complété', () => {
    const steps = {
      MEDICATIONS: {
        orders: [
          {
            id: '01J9ZXK4M2Q8R7S6T5V4W3002A',
            catalog_item_id: null,
            name: 'Collyre fictif',
            form: 'DROPS' as const,
            form_other: null,
            strength: '0,5 %',
            dosage: '1 goutte',
            frequency: '3 fois par jour',
            duration_text: 'jusqu’au contrôle',
            duration_value: 7,
            duration_unit: 'DAY' as const,
            instructions: null,
          },
          {
            id: '01J9ZXK4M2Q8R7S6T5V4W3002B',
            catalog_item_id: null,
            name: 'Complément fictif',
            form: 'OTHER' as const,
            form_other: 'Sachet',
            strength: null,
            dosage: null,
            frequency: null,
            duration_text: null,
            duration_value: null,
            duration_unit: null,
            instructions: null,
          },
        ],
      },
    } as Partial<ClinicalRevisionSnapshot['steps']>
    const order = medicationOrderFromRevision({
      ...base(),
      revision: revision({}, steps),
      templateVersion: CLINICAL_TEMPLATE_VERSIONS.MEDICATION_ORDER,
    })
    if (order === null) throw new Error('Ordonnance attendue.')
    const model = buildDocument('MEDICATION_ORDER', order)
    const text = printedText(layout(model))

    expect(order).toMatchObject({
      orderId: REVISION_ID,
      status: 'VALIDATED',
      orderedAt: SNAPSHOT.recorded_at,
      professional: { displayName: 'Professionnel (démonstration)', professionalNumber: null },
    })
    expect(firstTable(model, 'Produits prescrits').rows.map((row) => row.cells)).toEqual([
      ['Collyre fictif', 'Collyre', '0,5 %', '1 goutte', '3 fois par jour', 'jusqu’au contrôle · 7 jour(s)', MISSING],
      ['Complément fictif', 'Sachet', MISSING, MISSING, MISSING, MISSING, MISSING],
    ])
    expect(model.templateVersion).toBe('medication_order.v1')
    expect(model.watermark).not.toBe('BROUILLON')
    expect(text).toContain('L’application ne complète aucune posologie.')
    noForbiddenWords(text)
  })
})

describe('lettre d’orientation (révision figée)', () => {
  it('n’existe que pour une orientation « oui » (jamais pour « non » ni « non renseigné »)', () => {
    for (const referral of [false, null]) {
      expect(
        referralLetterFromRevision({
          ...base(),
          revision: revision({}, { CONCLUSION: { ...SNAPSHOT.steps.CONCLUSION, referral } }),
          templateVersion: CLINICAL_TEMPLATE_VERSIONS.REFERRAL_LETTER,
        }),
      ).toBeNull()
    }
  })

  it('reprend le niveau, les motifs et la conclusion saisis ; aucune mesure jointe d’office, aucun destinataire inventé', () => {
    const letter = referralLetterFromRevision({
      ...base(),
      revision: revision(
        {},
        {
          CONCLUSION: {
            conclusion: 'Avis spécialisé souhaité (fictif)',
            referral: true,
            referral_urgency: 'RAPID',
            referral_reasons: [
              { id: '01J9ZXK4M2Q8R7S6T5V4W3002C', term_code: 'OTHER', other_text: 'Précision fictive' },
            ],
          },
        },
      ),
      templateVersion: CLINICAL_TEMPLATE_VERSIONS.REFERRAL_LETTER,
    })
    if (letter === null) throw new Error('Lettre attendue.')
    const text = printedText(layout(buildDocument('REFERRAL_LETTER', letter)))

    expect(letter).toMatchObject({
      urgency: 'Consultation rapide',
      reasons: ['Autre : Précision fictive'],
      summary: 'Avis spécialisé souhaité (fictif)',
      addressee: null,
      measurements: [],
    })
    expect(text).toContain('LETTRE D’ORIENTATION')
    expect(text).toContain('Niveau d’urgence | Consultation rapide')
    expect(text).not.toContain('Mesures jointes')
    noForbiddenWords(text)
  })
})

describe('une seule source de libellés (écran et document)', () => {
  it('stades, corrections, formes et termes viennent du domaine clinique', () => {
    for (const [code, label] of Object.entries(STAGE_LABELS)) expect(labels.stage(code)).toBe(label)
    for (const [code, label] of Object.entries(CORRECTION_TITLES)) expect(labels.correction(code)).toBe(label)
    expect(labels.medicationForm('TABLET', null)).toBe(MEDICATION_FORM_LABELS.TABLET)
    expect(labels.medicationForm('OTHER', ' Sachet ')).toBe('Sachet')
    expect(labels.ametropia('MYOPIA', null)).toBe('Myopie')
    expect(labels.durationUnit('WEEK')).toBe('semaine(s)')
    expect(labels.stage('INCONNU')).toBe('INCONNU')
  })

  it('les libellés de prescription du moteur sont la copie du référentiel du domaine', () => {
    const fromTerms = (group: 'OPTICAL_USAGE' | 'LENS_TYPE' | 'LENS_TREATMENT') =>
      Object.fromEntries(CLINICAL_TERMS[group].map((term) => [term.code, term.label]))

    expect(OPTICAL_USAGE_LABELS).toEqual(fromTerms('OPTICAL_USAGE'))
    expect(LENS_TYPE_LABELS).toEqual(fromTerms('LENS_TYPE'))
    expect(LENS_TREATMENT_LABELS).toEqual(fromTerms('LENS_TREATMENT'))
  })

  it('une échelle d’acuité du cabinet est nommée ; une échelle inconnue reste non précisée', () => {
    const named = clinicalLabelsWithScales([{ id: 'S1', label: 'Échelle fictive' }])

    expect(named.scale('S1')).toBe('Échelle fictive')
    expect(named.scale('S2')).toBeNull()
  })
})

describe('prescription : justification d’écart et lecture à l’écran', () => {
  it('la justification d’un écart de DP saisie est imprimée telle quelle', () => {
    const body = prescriptionBodyFromResource(
      prescription({ pd_total: '62.00', pd_od: '31.00', pd_og: '32.00', pd_inconsistency_reason: 'Mesures reprises (fictif)' }),
      labels,
    )

    expect(body.pdInconsistencyReason).toBe('Mesures reprises (fictif)')
    expect(body.lensIndex).toBe('1.60')
  })

  it('un indice « autre » garde sa précision', () => {
    const body = prescriptionBodyFromResource(prescription({ lens_index: null, lens_index_other: '1.53 (fictif)' }), labels)

    expect(body.lensIndex).toBe('Autre : 1.53 (fictif)')
  })
})

describe('cabinet et nom de fichier', () => {
  it('le cabinet vient du compte connecté ; document de TEST tant que les documents de production sont fermés', () => {
    const clinic = clinicFromSummary({
      name: 'TEST CABINET FICTIF',
      timezone: 'Africa/Douala',
      currency: 'XAF',
      production_documents_enabled: false,
    })

    expect(clinic).toMatchObject({ name: 'TEST CABINET FICTIF', testDocument: true, address: null, taxIdentifier: null, logo: null })
    expect(
      clinicFromSummary({ name: 'X', timezone: 'Africa/Douala', currency: 'XAF', production_documents_enabled: true }).testDocument,
    ).toBe(false)
  })

  it('aucun nom, aucun dossier, aucune donnée clinique dans le nom de fichier des documents cliniques', () => {
    const order = medicationOrderFromRevision({ ...base(), templateVersion: CLINICAL_TEMPLATE_VERSIONS.MEDICATION_ORDER })
    const letter = referralLetterFromRevision({
      ...base(),
      revision: revision({}, { CONCLUSION: { ...SNAPSHOT.steps.CONCLUSION, referral: true, referral_urgency: 'EMERGENCY' } }),
      templateVersion: CLINICAL_TEMPLATE_VERSIONS.REFERRAL_LETTER,
    })
    const record = consultationRecordFromRevision({
      ...base(),
      templateVersion: CLINICAL_TEMPLATE_VERSIONS.CLINICAL_RECORD,
      prescriptions: [],
      exportedAt: '2026-10-22T09:00:00.000Z',
      exportedByName: 'Professionnel (démonstration)',
    })
    if (order === null || letter === null) throw new Error('Documents attendus.')
    const names = [
      documentFileName(buildDocument('MEDICATION_ORDER', order)),
      documentFileName(buildDocument('REFERRAL_LETTER', letter)),
      documentFileName(buildDocument('CLINICAL_RECORD', record)),
    ]

    expect(names).toEqual(['ordonnance-20261014.pdf', 'lettre-orientation-20261014.pdf', 'dossier-clinique-20261022.pdf'])
    for (const name of names) {
      expect(name).not.toMatch(/PATIENT|FICTIF|lodie|TEST-P|Collyre|urgence|glaucome/i)
    }
  })
})

function prescription(overrides: Partial<Prescription> = {}): Prescription {
  return {
    id: '01J9ZXK4M2Q8R7S6T5V4W3002E',
    series_id: '01J9ZXK4M2Q8R7S6T5V4W3002F',
    version_no: 1,
    supersedes_id: null,
    patient_id: SNAPSHOT.patient.patient_id,
    origin: 'INTERNAL',
    consultation_id: SNAPSHOT.consultation_id,
    external_prescriber: null,
    external_prescribed_on: null,
    external_file_id: null,
    status: 'VALIDATED',
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
      { id: '01J9ZXK4M2Q8R7S6T5V4W3002G', eye: 'OD', no_correction: false, sphere: '-1.25', cylinder: '-0.50', axis: 90, addition: null, fitting_height: null },
      { id: '01J9ZXK4M2Q8R7S6T5V4W3002H', eye: 'OG', no_correction: true, sphere: null, cylinder: null, axis: null, addition: null, fitting_height: null },
    ],
    treatments: [],
    validated_at: '2026-10-14T09:40:00.000Z',
    validated_by: SNAPSHOT.author.user_id,
    validated_by_name: null,
    document_id: '01J9ZXK4M2Q8R7S6T5V4W3002J',
    updated_at: '2026-10-14T09:40:00.000Z',
    ...overrides,
  }
}
