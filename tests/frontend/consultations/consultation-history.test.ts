import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ClinicalRevisionDetail, Consultation } from '@/domain/contracts/generated'

import {
  apiError,
  buttonByName,
  callsTo,
  choose,
  click,
  consultation,
  CONSULTATION_ID,
  emptySteps,
  openConsultation,
  PATHS,
  resetEverything,
  SCALE_NEAR,
  SCALE_TENTHS,
  stubConsultationApi,
  textOf,
} from './support'

// Révisions d'une consultation et comparaison côte à côte (C03, AT19, AT42) : chaque révision est relue telle
// qu'enregistrée ; le changement est dit en texte et par un symbole ; « non renseigné » / « non mesuré » ne sont
// jamais « aucun » ; des acuités d'échelles différentes sont déclarées non comparables ; hors ligne, une révision
// est « indisponible », jamais vide. Données FICTIVES.
const URL = `/consultations/${CONSULTATION_ID}/historique`
const R1 = '01J9ZXK4M2Q8R7S6T5V4W30E01'
const R2 = '01J9ZXK4M2Q8R7S6T5V4W30E02'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-22T09:42:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

function meta(id: string, revisionNo: number, previous: string | null, reason: string) {
  return {
    id,
    revision_no: revisionNo,
    recorded_at: `2026-10-22T09:4${revisionNo}:00.000Z`,
    author_id: '01J9ZXK4M2Q8R7S6T5V4W30002',
    reason,
    previous_revision_id: previous,
    sha256: 'b'.repeat(64),
  }
}

function detail(
  revisionNo: number,
  steps: Consultation['steps'],
  reason: string,
): ClinicalRevisionDetail {
  const id = revisionNo === 1 ? R1 : R2
  return {
    ...meta(id, revisionNo, revisionNo === 1 ? null : R1, reason),
    snapshot: {
      consultation_id: CONSULTATION_ID,
      revision_no: revisionNo,
      recorded_at: `2026-10-22T09:4${revisionNo}:00.000Z`,
      author: { user_id: '01J9ZXK4M2Q8R7S6T5V4W30002', display_name: 'Dr Fictif' },
      patient: {
        patient_id: '01J9ZXK4M2Q8R7S6T5V4W30001',
        record_number: 'EE-2026-0001',
        last_name: 'PATIENT FICTIF',
        first_names: 'Élodie Marie',
      },
      visit_id: '01J9ZXK4M2Q8R7S6T5V4W30021',
      effective_at: '2026-10-22T09:30:00.000Z',
      steps,
      finalization_missing_data_reason: null,
      previous_revision_id: revisionNo === 1 ? null : R1,
      amendment_reason: revisionNo === 1 ? null : reason,
    },
  }
}

function withConclusion(text: string, scale: string, value: string): Consultation['steps'] {
  return {
    ...emptySteps(),
    VISUAL_ACUITY: {
      acuities: [
        {
          id: '01J9ZXK4M2Q8R7S6T5V4W30E10',
          context: 'PRELIMINARY',
          distance: 'FAR',
          correction: 'WITHOUT',
          eye: 'OD',
          scale_id: scale,
          raw_value: value,
          normalized_value: null,
        },
      ],
    },
    CONCLUSION: { conclusion: text, referral: false, referral_urgency: null, referral_reasons: [] },
  } as Consultation['steps']
}

function twoRevisions() {
  return stubConsultationApi({
    consultation: consultation({
      status: 'COMPLETED',
      version: 5,
      current_revision: 2,
      completed_at: '2026-10-22T09:41:00.000Z',
      revisions: [
        meta(R1, 1, null, 'Finalisation de la consultation'),
        meta(R2, 2, R1, 'Faute de frappe (fictif)'),
      ],
    }),
    revisionDetails: [
      detail(
        1,
        withConclusion('Conclusion initiale', SCALE_TENTHS, '8/10'),
        'Finalisation de la consultation',
      ),
      detail(
        2,
        withConclusion('Conclusion corrigée', SCALE_NEAR, 'P2'),
        'Faute de frappe (fictif)',
      ),
    ],
  })
}

describe('révisions d’une consultation', () => {
  it('liste les révisions avec leur motif et compare les deux dernières, changement dit en texte et en symbole', async () => {
    const api = twoRevisions()
    const { root, unmount } = await openConsultation(URL)

    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Révisions de la consultation')
    expect(textOf(root)).toContain('Révision 1')
    expect(textOf(root)).toContain('Motif : Faute de frappe (fictif)')
    expect(
      callsTo(api, 'GET', PATHS.revision)
        .map((call) => call.path.split('/').pop())
        .sort(),
    ).toEqual(['1', '2'])
    expect(textOf(root)).toMatch(/[0-9]+ différence\(s\) entre ces deux révisions\./)
    expect(textOf(root)).toContain('Conclusion initiale')
    expect(textOf(root)).toContain('Conclusion corrigée')
    expect(textOf(root)).toContain('≠ Modifié')
    unmount()
  })

  it('des acuités d’échelles différentes sont déclarées non comparables, chacune avec son échelle', async () => {
    twoRevisions()
    const { root, unmount } = await openConsultation(URL)

    expect(textOf(root)).toContain('8/10 (échelle : Dixièmes (X/10) — DÉMONSTRATION)')
    expect(textOf(root)).toContain('P2 (échelle : Vision de près (Parinaud) — DÉMONSTRATION)')
    expect(textOf(root)).toContain('Échelles différentes : ces valeurs ne sont pas comparables.')
    unmount()
  })

  it('« Afficher seulement les différences » décoché montre aussi les éléments identiques', async () => {
    twoRevisions()
    const { root, unmount } = await openConsultation(URL)
    expect(textOf(root)).not.toContain('= Identique')

    await click(root.querySelector('input[type="checkbox"]'))

    expect(textOf(root)).toContain('= Identique')
    expect(textOf(root)).toContain('Non renseigné')
    unmount()
  })

  it('une seule révision choisie se lit seule, sans colonne de comparaison', async () => {
    twoRevisions()
    const { root, unmount } = await openConsultation(URL)

    await choose(root, 'Révision de référence', '')

    expect(textOf(root)).not.toMatch(/différence\(s\)/)
    expect(textOf(root)).toContain('Conclusion corrigée')
    unmount()
  })

  it('une révision illisible est « indisponible », jamais vide ; « Réessayer » la relit', async () => {
    let up = false
    const api = twoRevisions()
    const original = api.getRevision
    api.getRevision = (id, revisionNo, request) =>
      up || revisionNo === 2 ? original(id, revisionNo, request) : 'network'
    const { root, unmount } = await openConsultation(URL)

    expect(textOf(root)).toContain('Révision indisponible')
    expect(textOf(root)).toContain('Aucune ancienne copie n’est présentée.')

    up = true
    await click(buttonByName(root, 'Réessayer'))
    expect(textOf(root)).not.toContain('Révision indisponible')
    unmount()
  })

  it('un examen non terminé n’a aucune révision : le dit', async () => {
    stubConsultationApi({ consultation: consultation({ status: 'IN_PROGRESS', version: 2 }) })
    const { root, unmount } = await openConsultation(URL)

    expect(textOf(root)).toContain('Aucune révision enregistrée')
    unmount()
  })

  it('une révision refusée par le serveur (403) n’affiche aucune donnée', async () => {
    const api = twoRevisions()
    api.getRevision = () => apiError(403, 'ACTION_FORBIDDEN', 'Action non autorisée.')
    const { root, unmount } = await openConsultation(URL)

    expect(textOf(root)).toContain('Révision indisponible')
    expect(textOf(root)).not.toContain('Conclusion initiale')
    unmount()
  })
})
