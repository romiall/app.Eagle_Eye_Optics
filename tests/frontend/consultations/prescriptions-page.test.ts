import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  buttonByName,
  callsTo,
  choose,
  click,
  consultation,
  CONSULTATION_ID,
  errorWithDetails,
  ID_A,
  json,
  openConsultation,
  PATHS,
  prescription,
  PRESCRIPTION_ID,
  READ_ONLY,
  resetEverything,
  stubConsultationApi,
  textOf,
  type,
} from './support'

// Écran « Prescription optique » d'une consultation (C04, AT20, AT21) : brouillon, copie EXPLICITE de la
// réfraction, validation (version immuable, document préparé), nouvelle version, conflit de brouillon. Rien n'est
// dit validé ni imprimé avant la réponse du serveur. Données FICTIVES.
const URL = `/consultations/${CONSULTATION_ID}/prescriptions`
const ULID = /^[0-9A-Z]{26}$/

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-22T09:42:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

async function typeInto(root: HTMLElement, id: string, value: string): Promise<void> {
  const element = root.querySelector(`[id="${id}"]`)
  if (!(element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement)) {
    throw new Error(`Contrôle « ${id} » introuvable.`)
  }
  element.value = value
  element.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

function dialogTitled(root: HTMLElement, title: string): HTMLDialogElement {
  const found = [...root.querySelectorAll('dialog')].find((dialog) =>
    dialog.querySelector('h2')?.textContent?.includes(title),
  )
  if (found === undefined) throw new Error(`Boîte « ${title} » introuvable.`)
  return found
}

const inProgress = () =>
  consultation({
    status: 'IN_PROGRESS',
    version: 3,
    steps: {
      REFRACTION: {
        measurements: [
          {
            id: '01J9ZXK4M2Q8R7S6T5V4W30C01',
            stage: 'SUBJECTIVE',
            eye: 'OD',
            sphere: '-1.25',
            cylinder: '0.75',
            axis: 180,
            addition: '2.00',
          },
        ],
        refraction_observations: null,
        ametropias: [],
      },
    },
  })

describe('première prescription', () => {
  it('aucune prescription : le dit et propose d’en créer une ; le brouillon est enregistré sur le serveur', async () => {
    const api = stubConsultationApi({ consultation: inProgress() })
    const { root, unmount } = await openConsultation(URL)

    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Prescription optique')
    expect(textOf(root)).toContain(
      'Aucune prescription optique n’est enregistrée pour cette consultation.',
    )
    await click(buttonByName(root, 'Nouvelle prescription'))
    expect(textOf(root)).toContain('Nouvelle prescription (brouillon)')
    expect(textOf(root)).toContain('BROUILLON : ne pas remettre au patient')

    await typeInto(root, 'rx-OD-sphere', '-1,25')
    await click(root.querySelector('[id="rx-OG-no_correction"]'))
    await click(buttonByName(root, 'Enregistrer le brouillon'))

    const [call] = callsTo(api, 'POST', PATHS.consultationPrescriptions)
    expect(call?.path).toBe(`/api/v1/consultations/${CONSULTATION_ID}/prescriptions`)
    expect(call?.headers['Idempotency-Key']).toMatch(ULID)
    expect(call?.body).toMatchObject({
      expected_version: null,
      origin: 'INTERNAL',
      consultation_id: CONSULTATION_ID,
      patient_id: ID_A,
      version_no: 1,
      supersedes_id: null,
    })
    const eyes = call?.body?.['eyes'] as Record<string, unknown>[]
    expect(eyes.map((eye) => [eye['eye'], eye['sphere'], eye['no_correction']])).toEqual([
      ['OD', '-1.25', false],
      ['OG', null, true],
    ])
    expect(textOf(root)).toContain('Version 1 (brouillon)')
    expect(buttonByName(root, 'Valider la prescription')).not.toBeNull()
    unmount()
  })

  it('copier la réfraction est un geste explicite : choisir la mesure, confirmer ; rien n’est copié d’office', async () => {
    stubConsultationApi({ consultation: inProgress() })
    const { root, unmount } = await openConsultation(URL)
    await click(buttonByName(root, 'Nouvelle prescription'))
    expect((root.querySelector('[id="rx-OD-sphere"]') as HTMLInputElement).value).toBe('')

    await click(buttonByName(root, 'Copier une mesure de réfraction…'))
    const box = dialogTitled(root, 'Copier une mesure de réfraction')
    await click(buttonByName(box, 'Copier dans la prescription'))
    expect(textOf(box)).toContain('Choisissez la mesure à copier : aucune n’est copiée d’office.')
    expect((root.querySelector('[id="rx-OD-sphere"]') as HTMLInputElement).value).toBe('')

    await choose(box, 'Mesure à copier', 'SUBJECTIVE')
    await click(buttonByName(box, 'Copier dans la prescription'))

    expect((root.querySelector('[id="rx-OD-sphere"]') as HTMLInputElement).value).toBe('-1,25')
    expect((root.querySelector('[id="rx-OD-cylinder"]') as HTMLInputElement).value).toBe('0,75')
    expect((root.querySelector('[id="rx-OD-addition"]') as HTMLInputElement).value).toBe('2,00')
    expect((root.querySelector('[id="rx-OG-sphere"]') as HTMLInputElement).value).toBe('')
    unmount()
  })
})

describe('validation', () => {
  it('un brouillon enregistré se valide : version immuable, document préparé, plus de « BROUILLON »', async () => {
    const api = stubConsultationApi({ consultation: inProgress(), prescriptions: [prescription()] })
    const { root, unmount } = await openConsultation(URL)
    expect(textOf(root)).toContain('Version 1 (brouillon)')

    await click(buttonByName(root, 'Valider la prescription'))

    const [call] = callsTo(api, 'POST', PATHS.prescriptionValidate)
    expect(call?.path).toBe(`/api/v1/prescriptions/${PRESCRIPTION_ID}/validate`)
    expect(call?.body).toMatchObject({ expected_version: null, prescription_id: PRESCRIPTION_ID })
    expect(String(call?.body?.['document_id'])).toMatch(ULID)
    expect(textOf(root)).toContain('Validée')
    expect(textOf(root)).not.toContain('BROUILLON')
    expect(buttonByName(root, 'Créer une nouvelle version')).not.toBeNull()
    expect(textOf(root)).not.toMatch(/imprimée|payé/i)
    unmount()
  })

  it('un œil sans valeur ni mention « aucune correction » bloque la validation, rien n’est envoyé', async () => {
    const draft = prescription()
    const api = stubConsultationApi({
      consultation: inProgress(),
      prescriptions: [{ ...draft, eyes: [draft.eyes[0], { ...draft.eyes[1], sphere: null }] }],
    })
    const { root, unmount } = await openConsultation(URL)

    await click(buttonByName(root, 'Valider la prescription'))

    expect(callsTo(api, 'POST', PATHS.prescriptionValidate)).toHaveLength(0)
    expect(textOf(root)).toContain(
      'Œil OG : saisissez les valeurs, ou cochez « Aucune correction » pour cet œil.',
    )
    unmount()
  })

  it('une saisie non enregistrée empêche de valider : seule la version enregistrée est validée', async () => {
    const api = stubConsultationApi({ consultation: inProgress(), prescriptions: [prescription()] })
    const { root, unmount } = await openConsultation(URL)

    await type(root, 'Observations', 'Note non enregistrée')

    expect((buttonByName(root, 'Valider la prescription') as HTMLButtonElement).disabled).toBe(true)
    expect(callsTo(api, 'POST', PATHS.prescriptionValidate)).toHaveLength(0)
    unmount()
  })
})

describe('brouillon et nouvelle version', () => {
  it('modifier un brouillon envoie seulement les champs changés avec le jeton lu', async () => {
    const api = stubConsultationApi({ consultation: inProgress(), prescriptions: [prescription()] })
    const { root, unmount } = await openConsultation(URL)

    await type(root, 'Observations', 'Port permanent (fictif)')
    await click(buttonByName(root, 'Enregistrer le brouillon'))

    const [call] = callsTo(api, 'PATCH', PATHS.prescription)
    expect(call?.path).toBe(`/api/v1/prescriptions/${PRESCRIPTION_ID}`)
    expect(call?.body).toMatchObject({
      expected_updated_at: '2026-10-22T09:40:00.000Z',
      changes: { notes: 'Port permanent (fictif)' },
    })
    expect(Object.keys(call?.body?.['changes'] as object)).toEqual(['notes'])
    unmount()
  })

  it('un brouillon modifié ailleurs (409) garde la saisie et montre ce qui a changé', async () => {
    const current = prescription({ lens_index: '1.74', updated_at: '2026-10-22T09:41:00.000Z' })
    stubConsultationApi({
      consultation: inProgress(),
      prescriptions: [prescription()],
      handlers: {
        updatePrescription: () =>
          errorWithDetails(409, 'VERSION_CONFLICT', 'Prescription modifiée entre-temps.', {
            current_updated_at: current.updated_at,
          }),
        getPrescription: () => json(200, { data: current }),
      },
    })
    const { root, unmount } = await openConsultation(URL)

    await type(root, 'Observations', 'Ma saisie')
    await click(buttonByName(root, 'Enregistrer le brouillon'))

    expect(textOf(root)).toContain('Ce brouillon a été modifié entre-temps')
    expect(textOf(root)).toContain('Indice : actuellement « 1.74 », votre saisie « 1.50 »')
    expect((root.querySelector('[id="rx-observations"]') as HTMLTextAreaElement).value).toBe(
      'Ma saisie',
    )
    unmount()
  })

  it('une version validée se corrige par une NOUVELLE version de la même série, avec des lignes neuves', async () => {
    const validated = prescription({
      status: 'VALIDATED',
      validated_at: '2026-10-22T09:41:00.000Z',
      document_id: '01J9ZXK4M2Q8R7S6T5V4W30D01',
    })
    const api = stubConsultationApi({ consultation: inProgress(), prescriptions: [validated] })
    const { root, unmount } = await openConsultation(URL)

    await click(buttonByName(root, 'Créer une nouvelle version'))
    expect(textOf(root)).toContain('Nouvelle version 2 (brouillon)')
    await click(buttonByName(root, 'Enregistrer le brouillon'))

    const [call] = callsTo(api, 'POST', PATHS.prescriptionRevision)
    expect(call?.path).toBe(`/api/v1/prescriptions/${PRESCRIPTION_ID}/revisions`)
    expect(call?.body).toMatchObject({
      supersedes_id: PRESCRIPTION_ID,
      series_id: validated.series_id,
      version_no: 2,
    })
    const eyes = call?.body?.['eyes'] as { id: string }[]
    expect(eyes.map((eye) => eye.id)).not.toContain(validated.eyes[0].id)
    expect(api.prescriptions.find((item) => item.id === PRESCRIPTION_ID)?.status).toBe('VALIDATED')
    unmount()
  })
})

describe('droits', () => {
  it('lecture seule : la prescription se lit, aucune création ni validation', async () => {
    stubConsultationApi({
      permissions: READ_ONLY,
      consultation: inProgress(),
      prescriptions: [
        prescription({ status: 'VALIDATED', validated_at: '2026-10-22T09:41:00.000Z' }),
      ],
    })
    const { root, unmount } = await openConsultation(URL)

    expect(textOf(root)).toContain('Version 1')
    expect(buttonByName(root, 'Créer une nouvelle version')).toBeNull()
    expect(buttonByName(root, 'Valider la prescription')).toBeNull()
    unmount()
  })

  it('sans clinical.read : accès refusé, rien n’est lu', async () => {
    const api = stubConsultationApi({
      permissions: ['patient.read', 'visit.register', 'agenda.read'],
    })
    const { router, unmount } = await openConsultation(URL)

    expect(router.currentRoute.value.name).toBe('acces-refuse')
    expect(callsTo(api, 'GET', PATHS.consultationPrescriptions)).toHaveLength(0)
    unmount()
  })
})
