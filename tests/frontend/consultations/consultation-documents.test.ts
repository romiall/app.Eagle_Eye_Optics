import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { documentStore, resetDocumentStoreForTests } from '@/app/services/documents'
import type { ClinicalRevisionDetail, Consultation } from '@/domain/contracts/generated'

import { FORBIDDEN_WORDS } from '../documents/support'
import {
  ACCOUNT_ID,
  buttonByName,
  callsTo,
  click,
  CLINICIAN,
  consultation,
  CONSULTATION_ID,
  CONSULTATION_URL,
  openConsultation,
  PATHS,
  prescription,
  READ_ONLY,
  resetEverything,
  stubConsultationApi,
  textOf,
} from './support'

// Impression des documents cliniques depuis les écrans (D02, C04 ; fichier 11) : rien d'imprimable pour un
// examen en cours ni pendant une correction ; pour un examen terminé, ordonnance (rubrique Médicaments), lettre
// (orientation « oui ») et fiche (`clinical.export`) préparées depuis la révision FIGÉE relue à la demande ;
// prescription optique seulement VALIDÉE. Aucune donnée clinique dans l'adresse ni le nom du fichier, aucun mot
// interdit. Le rendu jsPDF est remplacé par des octets dérivés du texte mis en page. Données FICTIVES.
vi.mock('@/documents/engine/pdf', async () => {
  const support = await import('../documents/support')
  return {
    renderDocument: (model: Parameters<typeof support.layout>[0]) => {
      const plan = support.layout(model)
      return {
        bytes: new TextEncoder().encode(`%PDF-fictif\n${support.printedText(plan)}`),
        pageCount: plan.pages.length,
        warnings: [...plan.warnings],
        plan,
      }
    },
  }
})

const REVISION_ID = '01J9ZXK4M2Q8R7S6T5V4W30E01'
const RECORDED_AT = '2026-10-22T09:30:00.000Z'
const PRESCRIPTIONS_URL = `/consultations/${CONSULTATION_ID}/prescriptions`

const FROZEN_STEPS = {
  ANAMNESIS: {
    symptoms: 'Gêne fictive en lecture',
    wears_glasses: null,
    glasses_duration: null,
    previous_correction_available: null,
    complaints: [
      { id: '01J9ZXK4M2Q8R7S6T5V4W30E02', term_code: 'BLURRED_VISION', other_text: null },
    ],
  },
  MEDICATIONS: {
    orders: [
      {
        id: '01J9ZXK4M2Q8R7S6T5V4W30E03',
        catalog_item_id: null,
        name: 'Collyre fictif figé',
        form: 'DROPS',
        form_other: null,
        strength: null,
        dosage: '1 goutte',
        frequency: null,
        duration_text: null,
        duration_value: null,
        duration_unit: null,
        instructions: null,
      },
    ],
  },
  CONCLUSION: {
    conclusion: 'Conclusion fictive',
    referral: true,
    referral_urgency: 'RECOMMENDED',
    referral_reasons: [
      { id: '01J9ZXK4M2Q8R7S6T5V4W30E04', term_code: 'SUSPECTED_GLAUCOMA', other_text: null },
    ],
  },
}

function completed(steps: Record<string, unknown> = FROZEN_STEPS): Consultation {
  return consultation({
    status: 'COMPLETED',
    current_revision: 1,
    version: 5,
    completed_at: RECORDED_AT,
    steps,
    revisions: [
      {
        id: REVISION_ID,
        revision_no: 1,
        recorded_at: RECORDED_AT,
        author_id: ACCOUNT_ID,
        reason: 'Finalisation de la consultation',
        previous_revision_id: null,
        sha256: 'a'.repeat(64),
      },
    ],
  })
}

/** Révision 1 figée de la consultation, telle que le serveur la rend. */
function frozen(exam: Consultation): ClinicalRevisionDetail {
  return {
    id: REVISION_ID,
    revision_no: 1,
    recorded_at: RECORDED_AT,
    author_id: ACCOUNT_ID,
    reason: 'Finalisation de la consultation',
    previous_revision_id: null,
    sha256: 'a'.repeat(64),
    snapshot: {
      consultation_id: exam.id,
      revision_no: 1,
      recorded_at: RECORDED_AT,
      author: { user_id: ACCOUNT_ID, display_name: 'Dr Fictif' },
      patient: {
        patient_id: exam.patient.id,
        record_number: exam.patient.record_number,
        last_name: exam.patient.last_name,
        first_names: exam.patient.first_names,
      },
      visit_id: exam.visit_id,
      effective_at: exam.effective_at,
      steps: structuredClone(exam.steps),
      finalization_missing_data_reason: null,
      previous_revision_id: null,
      amendment_reason: null,
    },
  }
}

function preview(root: HTMLElement, heading: string): Element | null {
  return root.querySelector(`section[aria-label="Aperçu : ${heading}"]`)
}

function noForbiddenWords(root: HTMLElement): void {
  const text = textOf(root)
  for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word)
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-22T09:42:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetDocumentStoreForTests()
  resetEverything()
})

describe('documents de l’examen', () => {
  it('examen en cours : aucun document n’est proposé, rien n’est lu', async () => {
    const api = stubConsultationApi({
      permissions: [...CLINICIAN, 'clinical.export'],
      consultation: consultation({ status: 'IN_PROGRESS', version: 3, steps: FROZEN_STEPS }),
    })
    const { root, unmount } = await openConsultation()

    expect(textOf(root)).not.toContain('Documents de l’examen')
    expect(buttonByName(root, 'Aperçu de l’ordonnance de médicaments')).toBeNull()
    expect(buttonByName(root, 'Aperçu de la fiche de consultation')).toBeNull()
    expect(callsTo(api, 'GET', PATHS.revision)).toHaveLength(0)
    unmount()
  })

  it('examen terminé : ordonnance et lettre pour clinical.read, sans la fiche ; aucune lecture avant la demande', async () => {
    const exam = completed()
    const api = stubConsultationApi({ consultation: exam, revisionDetails: [frozen(exam)] })
    const { root, unmount } = await openConsultation()

    expect(textOf(root)).toContain('Documents de l’examen')
    expect(textOf(root)).toContain('révision 1 figée')
    expect(buttonByName(root, 'Aperçu de l’ordonnance de médicaments')).not.toBeNull()
    expect(buttonByName(root, 'Aperçu de la lettre d’orientation')).not.toBeNull()
    expect(buttonByName(root, 'Aperçu de la fiche de consultation')).toBeNull()
    expect(callsTo(api, 'GET', PATHS.revision)).toHaveLength(0)
    unmount()
  })

  it('avec clinical.export, la fiche de consultation est aussi proposée', async () => {
    const exam = completed()
    stubConsultationApi({
      permissions: [...CLINICIAN, 'clinical.export'],
      consultation: exam,
      revisionDetails: [frozen(exam)],
    })
    const { root, unmount } = await openConsultation()

    expect(buttonByName(root, 'Aperçu de la fiche de consultation')).not.toBeNull()
    unmount()
  })

  it('sans médicaments ni orientation, ni ordonnance ni lettre', async () => {
    const exam = completed({
      ...FROZEN_STEPS,
      MEDICATIONS: { orders: [] },
      CONCLUSION: {
        conclusion: 'Conclusion fictive',
        referral: false,
        referral_urgency: null,
        referral_reasons: [],
      },
    })
    stubConsultationApi({
      permissions: [...CLINICIAN, 'clinical.export'],
      consultation: exam,
      revisionDetails: [frozen(exam)],
    })
    const { root, unmount } = await openConsultation()

    expect(buttonByName(root, 'Aperçu de la fiche de consultation')).not.toBeNull()
    expect(buttonByName(root, 'Aperçu de l’ordonnance de médicaments')).toBeNull()
    expect(buttonByName(root, 'Aperçu de la lettre d’orientation')).toBeNull()
    unmount()
  })

  it('aperçu de l’ordonnance : révision figée relue, PDF nommé sans donnée, adresse inchangée, aucun mot interdit', async () => {
    const exam = completed()
    const api = stubConsultationApi({ consultation: exam, revisionDetails: [frozen(exam)] })
    const { root, router, unmount } = await openConsultation()

    await click(buttonByName(root, 'Aperçu de l’ordonnance de médicaments'))
    await vi.waitFor(() => expect(preview(root, 'Ordonnance de médicaments')).not.toBeNull())

    const [read] = callsTo(api, 'GET', PATHS.revision)
    expect(read?.path).toBe(`/api/v1/consultations/${CONSULTATION_ID}/revisions/1`)
    const text = textOf(root)
    expect(text).toContain('Collyre fictif figé')
    expect(text).toContain('ORDONNANCE')
    expect(text).toContain('DOCUMENT DE TEST')
    expect(text).toContain('fichier ordonnance-20261022.pdf')
    expect(buttonByName(root, 'Imprimer')).not.toBeNull()
    expect(buttonByName(root, 'Télécharger le PDF')).not.toBeNull()
    expect(router.currentRoute.value.fullPath).toBe(CONSULTATION_URL)
    noForbiddenWords(root)

    const [kept] = await documentStore().list({ documentType: 'MEDICATION_ORDER' })
    expect(kept?.fileName).toBe('ordonnance-20261022.pdf')
    expect(kept?.fileName).not.toMatch(/PATIENT|FICTIF|Collyre/i)
    unmount()
  })

  it('aperçu de la lettre d’orientation : niveau et motifs de la révision figée', async () => {
    const exam = completed()
    stubConsultationApi({ consultation: exam, revisionDetails: [frozen(exam)] })
    const { root, unmount } = await openConsultation()

    await click(buttonByName(root, 'Aperçu de la lettre d’orientation'))
    await vi.waitFor(() => expect(preview(root, 'Lettre d’orientation')).not.toBeNull())

    expect(textOf(root)).toContain('Niveau d’urgence | Consultation recommandée')
    expect(textOf(root)).toContain('Suspicion de glaucome')
    expect(textOf(root)).toContain('fichier lettre-orientation-20261022.pdf')
    noForbiddenWords(root)
    unmount()
  })

  it('serveur injoignable : rien n’est préparé, le message le dit', async () => {
    const exam = completed()
    stubConsultationApi({ consultation: exam, handlers: { getRevision: () => 'network' } })
    const { root, unmount } = await openConsultation()

    await click(buttonByName(root, 'Aperçu de l’ordonnance de médicaments'))
    await vi.waitFor(() =>
      expect(textOf(root)).toContain('rien n’est préparé à partir d’une copie ancienne'),
    )

    expect(preview(root, 'Ordonnance de médicaments')).toBeNull()
    expect(await documentStore().list()).toEqual([])
    unmount()
  })

  it('pendant une correction, aucun document n’est proposé', async () => {
    const exam = completed()
    stubConsultationApi({ consultation: exam, revisionDetails: [frozen(exam)] })
    const { root, unmount } = await openConsultation()
    expect(buttonByName(root, 'Aperçu de l’ordonnance de médicaments')).not.toBeNull()

    await click(buttonByName(root, 'Corriger l’examen'))

    expect(textOf(root)).toContain('Correction en cours')
    expect(textOf(root)).not.toContain('Documents de l’examen')
    expect(buttonByName(root, 'Aperçu de l’ordonnance de médicaments')).toBeNull()
    unmount()
  })
})

describe('impression de la prescription optique', () => {
  const validated = () =>
    prescription({
      status: 'VALIDATED',
      validated_at: '2026-10-22T09:41:00.000Z',
      validated_by: ACCOUNT_ID,
      validated_by_name: null,
      document_id: '01J9ZXK4M2Q8R7S6T5V4W30D01',
    })

  it('une prescription brouillon ne propose aucune impression', async () => {
    stubConsultationApi({ consultation: completed(), prescriptions: [prescription()] })
    const { root, unmount } = await openConsultation(PRESCRIPTIONS_URL)

    expect(textOf(root)).toContain('Version 1 (brouillon)')
    expect(buttonByName(root, /^Aperçu de la prescription/)).toBeNull()
    expect(textOf(root)).not.toContain('Imprimer la prescription')
    unmount()
  })

  it('une prescription validée s’imprime à part du dossier : nom du professionnel, version, PDF sans donnée dans le nom', async () => {
    const api = stubConsultationApi({ consultation: completed(), prescriptions: [validated()] })
    const { root, router, unmount } = await openConsultation(PRESCRIPTIONS_URL)

    expect(textOf(root)).toContain('Imprimer la prescription')
    await click(buttonByName(root, 'Aperçu de la prescription (version 1)'))
    await vi.waitFor(() => expect(preview(root, 'Prescription optique, version 1')).not.toBeNull())

    const text = textOf(root)
    expect(text).toContain('PRESCRIPTION OPTIQUE')
    expect(text).toContain('Prescripteur | Dr Fictif')
    expect(text).toContain('fichier prescription-optique-20261022.pdf')
    expect(router.currentRoute.value.fullPath).toBe(PRESCRIPTIONS_URL)
    // Validée par le compte connecté : aucun dossier supplémentaire n'est lu pour trouver son nom.
    expect(callsTo(api, 'GET', PATHS.revision)).toHaveLength(0)
    noForbiddenWords(root)

    const [kept] = await documentStore().list({ documentType: 'OPTICAL_PRESCRIPTION' })
    expect(kept).toMatchObject({
      documentId: '01J9ZXK4M2Q8R7S6T5V4W30D01',
      revisionNo: 1,
      templateVersion: 'optical_prescription.v1',
    })
    unmount()
  })

  it('lecture seule (clinical.read) : l’impression reste possible, aucune modification', async () => {
    stubConsultationApi({
      permissions: READ_ONLY,
      consultation: completed(),
      prescriptions: [validated()],
    })
    const { root, unmount } = await openConsultation(PRESCRIPTIONS_URL)

    expect(buttonByName(root, 'Aperçu de la prescription (version 1)')).not.toBeNull()
    expect(buttonByName(root, 'Créer une nouvelle version')).toBeNull()
    unmount()
  })

  it('l’écran de lecture affiche les valeurs du document : signe explicite, tiret, unités', async () => {
    stubConsultationApi({ consultation: completed(), prescriptions: [validated()] })
    const { root, unmount } = await openConsultation(PRESCRIPTIONS_URL)

    const text = textOf(root)
    expect(text).toContain('-1,25')
    expect(text).toContain('-0,50')
    expect(text).toContain('90°')
    expect(text).toContain('62,00 mm')
    expect(text).toContain('Lunettes permanentes')
    unmount()
  })
})
