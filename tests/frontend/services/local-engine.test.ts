import { afterEach, describe, expect, it, vi } from 'vitest'

import { deviceLocalEngine } from '@/app/services/localEngine'
import type { MutationRequest } from '@/app/services/mutations'
import { localCommandsOrNull } from '@/app/services/vault'

vi.mock('@/app/services/vault', () => ({ localCommandsOrNull: vi.fn(() => null) }))
// Échange de synchronisation programmé après un commit local (O05) : hors du sujet de ce test.
vi.mock('@/app/services/deviceSync', () => ({ scheduleDeviceSync: vi.fn() }))

/**
 * Journal du poste branché sur le répartiteur (O04) : module `offline` fermé ou coffre fermé → `handles` rend faux et la
 * route directe s'applique exactement comme avant ; une route explicite en ligne seulement n'est jamais prise ; un
 * poste prêt délègue au moteur local, avec la même clé d'idempotence et la même version attendue. Fictif.
 */
const request: MutationRequest = {
  name: 'CreatePatient',
  payload: { patient_id: '01J9ZXK4M2Q8R7S6T5V4W3PA01' },
  expectedVersion: null,
  idempotencyKey: 'cle-engine-0001',
}

afterEach(() => {
  vi.mocked(localCommandsOrNull).mockReset()
  vi.mocked(localCommandsOrNull).mockReturnValue(null)
})

describe('deviceLocalEngine', () => {
  it('module hors-ligne fermé ou coffre fermé : rien ne change, la route directe s’applique', () => {
    expect(deviceLocalEngine.handles(request)).toBe(false)
  })

  it('une route explicite (en ligne seulement) n’est jamais journalisée', () => {
    const handles = vi.fn(() => true)
    vi.mocked(localCommandsOrNull).mockReturnValue({
      handles,
      commit: vi.fn(),
      refresh: vi.fn(),
      readiness: vi.fn(),
    } as never)

    expect(
      deviceLocalEngine.handles({
        ...request,
        route: { method: 'POST', path: '/prescriptions/external' },
      }),
    ).toBe(false)
    expect(handles).not.toHaveBeenCalled()
  })

  it('un poste prêt délègue au moteur local avec la même clé et rend l’instant du commit local', async () => {
    const commit = vi.fn(async () => ({
      commandId: 'C',
      deviceSequence: 1,
      recordedAt: '2026-10-22T09:30:00.000Z',
      replayed: false,
      number: 'EE-2026-0041',
      payload: {},
    }))
    const handles = vi.fn(() => true)
    vi.mocked(localCommandsOrNull).mockReturnValue({
      handles,
      commit,
      refresh: vi.fn(),
      readiness: vi.fn(),
    } as never)

    expect(deviceLocalEngine.handles(request)).toBe(true)
    await expect(deviceLocalEngine.commit(request)).resolves.toEqual({
      recordedAt: '2026-10-22T09:30:00.000Z',
    })
    expect(commit).toHaveBeenCalledWith({
      name: 'CreatePatient',
      payload: request.payload,
      expectedVersion: null,
      idempotencyKey: 'cle-engine-0001',
    })
  })
})
