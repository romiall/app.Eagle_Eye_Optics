import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  callsTo,
  CLINICIAN_PERMISSIONS,
  CONSULTATION_ID,
  consultationPage,
  consultationSummary,
  json,
  openApp,
  RECEPTION_PERMISSIONS,
  resetEverything,
  settle,
  stubSettingsApi,
  textOf,
} from '../settings/support'

/**
 * Liste des examens (écran « Consultations ») : jour du cabinet par défaut, filtres état et « mes examens »
 * (préréglables par l'adresse), aucune valeur clinique, « indisponible » plutôt que « aucun examen » quand le
 * serveur ne répond pas, réservée à clinical.read et au module « consultations ». Données fictives.
 */
afterEach(() => {
  resetEverything()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('liste des examens', () => {
  it('lit les examens du jour du cabinet et ouvre la consultation', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-22T09:30:00.000Z'))
    const api = stubSettingsApi({
      permissions: CLINICIAN_PERMISSIONS,
      handlers: {
        'GET /api/v1/consultations': () => json(200, consultationPage([consultationSummary()])),
      },
    })
    const { root } = await openApp('/consultations')
    await settle()

    const first = callsTo(api, 'GET', /\/api\/v1\/consultations$/)[0]
    expect(first?.query.get('date')).toBe('2026-10-22')
    expect(first?.query.get('status')).toBeNull()
    expect(first?.query.get('mine')).toBeNull()
    const text = textOf(root)
    expect(text).toContain('PATIENT FICTIF')
    expect(text).toContain('EE-2026-0042')
    expect(text).toContain('Professionnel Fictif')
    expect(root.querySelector(`a[href="/consultations/${CONSULTATION_ID}"]`)).not.toBeNull()
  })

  it('les préréglages de l’adresse donnent tous les jours, en cours, mes examens', async () => {
    const api = stubSettingsApi({
      permissions: CLINICIAN_PERMISSIONS,
      handlers: { 'GET /api/v1/consultations': () => json(200, consultationPage([])) },
    })
    await openApp('/consultations?jour=tous&etat=IN_PROGRESS&mes=1')
    await settle()

    const first = callsTo(api, 'GET', /\/api\/v1\/consultations$/)[0]
    expect(first?.query.get('date')).toBeNull()
    expect(first?.query.get('status')).toBe('IN_PROGRESS')
    expect(first?.query.get('mine')).toBe('true')
  })

  it('un examen terminé propose ses révisions', async () => {
    stubSettingsApi({
      permissions: CLINICIAN_PERMISSIONS,
      handlers: {
        'GET /api/v1/consultations': () =>
          json(
            200,
            consultationPage([
              consultationSummary({
                status: 'COMPLETED',
                completed_at: '2026-10-22T10:00:00.000Z',
                current_revision: 1,
              }),
            ]),
          ),
      },
    })
    const { root } = await openApp('/consultations')
    await settle()

    expect(root.querySelector(`a[href="/consultations/${CONSULTATION_ID}/historique"]`)).not.toBeNull()
    expect(textOf(root)).toContain('révision 1')
  })

  it('serveur muet : « indisponible », jamais « aucun examen »', async () => {
    stubSettingsApi({
      permissions: CLINICIAN_PERMISSIONS,
      handlers: { 'GET /api/v1/consultations': () => 'network' },
    })
    const { root } = await openApp('/consultations')
    await settle()

    expect(textOf(root)).toContain('Serveur indisponible')
    expect(textOf(root)).not.toContain('Aucun examen')
  })

  it('l’accueil n’ouvre pas la liste ; module fermé : page introuvable', async () => {
    const reception = stubSettingsApi({ permissions: RECEPTION_PERMISSIONS })
    const first = await openApp('/consultations')
    await settle()
    expect(textOf(first.root)).toContain('Accès refusé')
    expect(callsTo(reception, 'GET', /\/api\/v1\/consultations$/)).toHaveLength(0)
    first.unmount()
    resetEverything()

    stubSettingsApi({ permissions: CLINICIAN_PERMISSIONS, modules: ['today', 'patients'] })
    const second = await openApp('/consultations')
    await settle()
    expect(textOf(second.root)).toContain('Page introuvable')
  })
})
