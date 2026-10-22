import { webcrypto } from 'node:crypto'

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { canonicalHash, stepsDigest } from '@/domain/clinical/snapshot'
import type { Consultation } from '@/domain/contracts/generated'

import {
  buttonByName,
  callsTo,
  click,
  consultation,
  CONSULTATION_ID,
  errorWithDetails,
  field,
  openConsultation,
  PATHS,
  READ_ONLY,
  resetEverything,
  stubConsultationApi,
  submitForm,
  textOf,
  type,
} from './support'

// Finalisation et correction d'un examen (C03, AT19, AT21) : confirmation avec récapitulatif des rubriques non
// renseignées, justification d'absence quand le motif ou la conclusion manquent, empreinte calculée sur ce qui
// a été lu, révision 1 figée ; correction motivée qui envoie les rubriques modifiées en UNE révision. Rien n'est
// dit terminé avant la réponse du serveur. Données FICTIVES.
const ULID = /^[0-9A-Z]{26}$/
const FIRST_REVISION = '01J9ZXK4M2Q8R7S6T5V4W30901'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-22T09:42:00.000Z'))
  // Empreinte SHA-256 par Web Crypto : l'implémentation de Node quand l'environnement de test n'en fournit pas.
  if (globalThis.crypto?.subtle === undefined) vi.stubGlobal('crypto', webcrypto)
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

function readyToFinish(overrides: Partial<Consultation> = {}): Consultation {
  return consultation({
    status: 'IN_PROGRESS',
    version: 3,
    steps: {
      ANAMNESIS: {
        symptoms: 'Gêne en vision de près (fictif)',
        wears_glasses: null,
        glasses_duration: null,
        previous_correction_available: null,
        complaints: [],
      },
      CONCLUSION: {
        conclusion: 'Conclusion fictive',
        referral: false,
        referral_urgency: null,
        referral_reasons: [],
      },
    },
    ...overrides,
  })
}

function completedExam(): Consultation {
  return readyToFinish({
    status: 'COMPLETED',
    version: 4,
    current_revision: 1,
    completed_at: '2026-10-22T09:40:00.000Z',
    revisions: [
      {
        id: FIRST_REVISION,
        revision_no: 1,
        recorded_at: '2026-10-22T09:40:00.000Z',
        author_id: '01J9ZXK4M2Q8R7S6T5V4W30002',
        reason: 'Finalisation de la consultation',
        previous_revision_id: null,
        sha256: 'a'.repeat(64),
      },
    ],
  })
}

function dialog(root: HTMLElement): HTMLDialogElement {
  const element = root.querySelector('dialog')
  if (element === null) throw new Error('Boîte de confirmation introuvable.')
  return element
}

async function goToStep(root: HTMLElement, position: number): Promise<void> {
  const stepper = root.querySelector('nav[aria-label="Étapes de la consultation"]') as HTMLElement
  await click([...stepper.querySelectorAll('button')][position - 1] ?? null)
}

describe('terminer l’examen', () => {
  it('demande confirmation, récapitule les rubriques non renseignées et envoie l’empreinte de ce qui a été lu', async () => {
    const api = stubConsultationApi({ consultation: readyToFinish() })
    const expected = await stepsDigest(api.server.steps)
    const { root, unmount } = await openConsultation()

    await click(buttonByName(root, 'Terminer l’examen'))

    const box = dialog(root)
    expect(textOf(box)).toContain('Rubriques non renseignées')
    for (const title of [
      'Antécédents',
      'Acuité visuelle',
      'Réfraction',
      'Centrage',
      'Médicaments',
    ]) {
      expect(textOf(box)).toContain(`${title} : non renseigné`)
    }
    expect(textOf(box)).not.toContain('Il manque')
    expect(callsTo(api, 'POST', PATHS.complete)).toHaveLength(0)

    await click(buttonByName(box, 'Terminer l’examen'))

    const [call] = callsTo(api, 'POST', PATHS.complete)
    expect(call?.path).toBe(`/api/v1/consultations/${CONSULTATION_ID}/complete`)
    expect(call?.headers['Idempotency-Key']).toMatch(ULID)
    expect(call?.body).toMatchObject({
      expected_version: 3,
      consultation_id: CONSULTATION_ID,
      revision_no: 1,
      snapshot_sha256: expected,
      finalization_missing_data_reason: null,
    })
    expect(String(call?.body?.['revision_id'])).toMatch(ULID)
    expect(textOf(root)).toContain('Examen terminé')
    expect(textOf(root)).toContain('révision 1 sur 1 enregistrée(s)')
    expect(buttonByName(root, 'Enregistrer')).toBeNull()
    unmount()
  })

  it('sans motif ni conclusion, une justification d’absence est exigée avant tout envoi', async () => {
    const api = stubConsultationApi({
      consultation: consultation({ status: 'IN_PROGRESS', version: 2 }),
    })
    const { root, unmount } = await openConsultation()
    await click(buttonByName(root, 'Terminer l’examen'))
    const box = dialog(root)
    expect(textOf(box)).toContain('Il manque le motif ou les plaintes et la conclusion')

    await click(buttonByName(box, 'Terminer l’examen'))

    expect(callsTo(api, 'POST', PATHS.complete)).toHaveLength(0)
    expect(textOf(box)).toContain('renseignez-les ou justifiez leur absence avant de terminer')

    await type(box, 'Justification d’absence', 'Patient parti avant la fin (fictif)')
    await click(buttonByName(box, 'Terminer l’examen'))

    expect(
      callsTo(api, 'POST', PATHS.complete)[0]?.body?.['finalization_missing_data_reason'],
    ).toBe('Patient parti avant la fin (fictif)')
    expect(textOf(root)).toContain(
      'Justification d’absence enregistrée : Patient parti avant la fin (fictif)',
    )
    unmount()
  })

  it('une étape modifiée non enregistrée bloque la finalisation : seul ce qui est enregistré est figé', async () => {
    const api = stubConsultationApi({ consultation: readyToFinish() })
    const { root, unmount } = await openConsultation()

    await type(root, 'Plaintes et symptômes', 'Nouvelle saisie non enregistrée')

    expect(textOf(root)).toContain('Enregistrez d’abord les étapes modifiées')
    expect((buttonByName(root, 'Terminer l’examen') as HTMLButtonElement).disabled).toBe(true)
    expect(callsTo(api, 'POST', PATHS.complete)).toHaveLength(0)
    unmount()
  })

  it('le serveur refuse une empreinte périmée (422) : le message est rendu, rien n’est dit terminé', async () => {
    stubConsultationApi({
      consultation: readyToFinish(),
      handlers: {
        complete: () =>
          errorWithDetails(
            422,
            'VALIDATION_FAILED',
            'Données invalides.',
            {},
            {
              snapshot_sha256: [
                'Le contenu enregistré n’est plus celui que vous avez relu : rechargez la consultation avant de la terminer.',
              ],
            },
          ),
      },
    })
    const { root, unmount } = await openConsultation()
    await click(buttonByName(root, 'Terminer l’examen'))

    await click(buttonByName(dialog(root), 'Terminer l’examen'))

    expect(textOf(root)).toContain('rechargez la consultation avant de la terminer')
    expect(textOf(root)).not.toContain('Examen terminé')
    unmount()
  })

  it('un conflit de version (409) propose de recharger, sans rien dire terminé', async () => {
    stubConsultationApi({
      consultation: readyToFinish(),
      handlers: {
        complete: () =>
          errorWithDetails(409, 'VERSION_CONFLICT', 'La consultation a été modifiée.', {
            current_version: 4,
          }),
      },
    })
    const { root, unmount } = await openConsultation()
    await click(buttonByName(root, 'Terminer l’examen'))

    await click(buttonByName(dialog(root), 'Terminer l’examen'))

    expect(textOf(root)).toContain('La consultation a changé entre-temps')
    expect(buttonByName(root, 'Recharger la consultation')).not.toBeNull()
    expect(textOf(root)).not.toContain('Examen terminé')
    unmount()
  })

  it('sans clinical.complete, « Terminer l’examen » n’est pas proposé', async () => {
    stubConsultationApi({
      permissions: ['clinical.read', 'clinical.write', 'patient.read', 'agenda.read'],
      consultation: readyToFinish(),
    })
    const { root, unmount } = await openConsultation()

    expect(buttonByName(root, 'Terminer l’examen')).toBeNull()
    unmount()
  })
})

describe('corriger un examen terminé', () => {
  it('envoie les rubriques modifiées en une révision motivée, chaînée à la précédente', async () => {
    const api = stubConsultationApi({ consultation: completedExam() })
    const { root, unmount } = await openConsultation()

    await click(buttonByName(root, 'Corriger l’examen'))
    expect(textOf(root)).toContain('Correction en cours')
    await goToStep(root, 7)
    await type(root, 'Conclusion', 'Conclusion corrigée (fictif)')
    await type(
      root,
      'Pourquoi corrigez-vous cet examen ?',
      'Faute de frappe dans la conclusion (fictif)',
    )

    await submitForm(root)

    const [call] = callsTo(api, 'POST', PATHS.amend)
    expect(call?.path).toBe(`/api/v1/consultations/${CONSULTATION_ID}/amendments`)
    const steps = call?.body?.['steps'] as { step: string; data: { conclusion: string } }[]
    expect(steps.map((item) => item.step)).toEqual(['CONCLUSION'])
    expect(steps[0]?.data.conclusion).toBe('Conclusion corrigée (fictif)')
    expect(call?.body).toMatchObject({
      expected_version: 4,
      consultation_id: CONSULTATION_ID,
      revision_no: 2,
      previous_revision_id: FIRST_REVISION,
      reason: 'Faute de frappe dans la conclusion (fictif)',
      snapshot_sha256: await canonicalHash(steps),
    })
    expect(textOf(root)).toContain('Examen terminé')
    expect(textOf(root)).toContain('révision 2 sur 2 enregistrée(s)')
    unmount()
  })

  it('le motif est obligatoire : sans lui, rien n’est envoyé', async () => {
    const api = stubConsultationApi({ consultation: completedExam() })
    const { root, unmount } = await openConsultation()
    await click(buttonByName(root, 'Corriger l’examen'))
    await goToStep(root, 7)
    await type(root, 'Conclusion', 'Conclusion corrigée (fictif)')

    await submitForm(root)

    expect(callsTo(api, 'POST', PATHS.amend)).toHaveLength(0)
    expect(textOf(root)).toContain('Le motif de la correction est obligatoire')
    unmount()
  })

  it('une correction qui ne change rien n’est pas envoyée', async () => {
    const api = stubConsultationApi({ consultation: completedExam() })
    const { root, unmount } = await openConsultation()
    await click(buttonByName(root, 'Corriger l’examen'))
    await type(root, 'Pourquoi corrigez-vous cet examen ?', 'Motif fictif')

    await submitForm(root)

    expect(callsTo(api, 'POST', PATHS.amend)).toHaveLength(0)
    expect(textOf(root)).toContain(
      'Aucune modification à enregistrer : modifiez au moins une étape.',
    )
    unmount()
  })

  it('abandonner une correction sans saisie revient à la lecture seule, sans rien envoyer', async () => {
    const api = stubConsultationApi({ consultation: completedExam() })
    const { root, unmount } = await openConsultation()
    await click(buttonByName(root, 'Corriger l’examen'))

    await click(buttonByName(root, 'Abandonner la correction'))

    expect(textOf(root)).not.toContain('Correction en cours')
    expect(buttonByName(root, 'Corriger l’examen')).not.toBeNull()
    expect(callsTo(api, 'POST', PATHS.amend)).toHaveLength(0)
    unmount()
  })

  it('un compte en lecture seule ne peut pas corriger', async () => {
    stubConsultationApi({ permissions: READ_ONLY, consultation: completedExam() })
    const { root, unmount } = await openConsultation()

    expect(buttonByName(root, 'Corriger l’examen')).toBeNull()
    expect(field(root, 'Plaintes et symptômes').disabled).toBe(true)
    unmount()
  })
})
