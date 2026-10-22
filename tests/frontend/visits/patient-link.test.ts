import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  callsTo,
  click,
  field,
  ID_A,
  ID_B,
  json,
  linkTo,
  listCalls,
  openApp,
  patient,
  resetEverything,
  stubVisitsApi,
  textOf,
  visitPage,
} from './support'

// Lien « Enregistrer une arrivée » de la fiche patient (P03) : visible seulement avec visit.register,
// le module « today » ouvert et une fiche ni archivée ni fusionnée ; il ouvre le formulaire avec le
// patient déjà choisi, relu sur le serveur. Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

const ARRIVAL_LINK = `/aujourdhui/arrivee?patient=${ID_A}`

function sheet(
  options: {
    permissions?: string[]
    modules?: string[]
    record?: ReturnType<typeof patient>
  } = {},
) {
  return stubVisitsApi({
    permissions: options.permissions ?? ['visit.register', 'patient.read', 'patient.update'],
    ...(options.modules === undefined ? {} : { modules: options.modules }),
    handlers: {
      get: () => json(200, { data: options.record ?? patient() }),
      list: () => json(200, visitPage([])),
    },
  })
}

describe('fiche patient : « Enregistrer une arrivée »', () => {
  it('apparaît avec visit.register quand le module « today » est ouvert, à côté de « Modifier »', async () => {
    sheet()
    const { root, unmount } = await openApp(`/patients/${ID_A}`)

    const link = linkTo(root, ARRIVAL_LINK)
    expect(link?.textContent?.trim()).toBe('Enregistrer une arrivée')
    expect(link?.className).toContain('min-h-11')
    expect(linkTo(root, `/patients/${ID_A}/modifier`)?.textContent?.trim()).toBe('Modifier')
    unmount()
  })

  it('apparaît même sans droit de modifier la fiche', async () => {
    sheet({ permissions: ['visit.register', 'patient.read'] })
    const { root, unmount } = await openApp(`/patients/${ID_A}`)

    expect(linkTo(root, ARRIVAL_LINK)).not.toBeNull()
    expect(linkTo(root, `/patients/${ID_A}/modifier`)).toBeNull()
    unmount()
  })

  it('n’apparaît pas sans visit.register (lecture ou caisse)', async () => {
    sheet({ permissions: ['patient.read', 'patient.update', 'agenda.read'] })
    const { root, unmount } = await openApp(`/patients/${ID_A}`)

    expect(root.querySelector('h1')?.textContent).toBe('PATIENT FICTIF Élodie Marie')
    expect(linkTo(root, ARRIVAL_LINK)).toBeNull()
    expect(textOf(root)).not.toContain('Enregistrer une arrivée')
    expect(linkTo(root, `/patients/${ID_A}/modifier`)).not.toBeNull()
    unmount()
  })

  it('n’apparaît pas quand le module « today » est fermé : liens masqués', async () => {
    sheet({ modules: ['patients'] })
    const { root, unmount } = await openApp(`/patients/${ID_A}`)

    expect(root.querySelector('h1')?.textContent).toBe('PATIENT FICTIF Élodie Marie')
    expect(linkTo(root, ARRIVAL_LINK)).toBeNull()
    expect(textOf(root)).not.toContain('Enregistrer une arrivée')
    unmount()
  })

  it('n’apparaît pas sur une fiche archivée', async () => {
    sheet({ record: patient({ archived: true }) })
    const { root, unmount } = await openApp(`/patients/${ID_A}`)

    expect(textOf(root)).toContain('Fiche archivée')
    expect(linkTo(root, ARRIVAL_LINK)).toBeNull()
    unmount()
  })

  it('n’apparaît pas sur une fiche fusionnée', async () => {
    sheet({ record: patient({ merged_into_patient_id: ID_B }) })
    const { root, unmount } = await openApp(`/patients/${ID_A}`)

    expect(textOf(root)).toContain('Cette fiche a été fusionnée dans une autre')
    expect(linkTo(root, ARRIVAL_LINK)).toBeNull()
    unmount()
  })

  it('n’apparaît pas tant que la fiche n’a pas été lue', async () => {
    stubVisitsApi({
      permissions: ['visit.register', 'patient.read'],
      handlers: { get: () => 'network' },
    })
    const { root, unmount } = await openApp(`/patients/${ID_A}`)

    expect(textOf(root)).toContain('Fiche indisponible')
    expect(linkTo(root, ARRIVAL_LINK)).toBeNull()
    unmount()
  })

  it('ouvre le formulaire d’arrivée avec ce patient déjà choisi, relu sur le serveur', async () => {
    const api = sheet()
    const { root, router, unmount } = await openApp(`/patients/${ID_A}`)

    await click(linkTo(root, ARRIVAL_LINK))

    expect(router.currentRoute.value.name).toBe('arrivee')
    expect(router.currentRoute.value.query).toEqual({ patient: ID_A })
    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Enregistrer une arrivée')
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('PATIENT FICTIF Élodie Marie')
    expect(textOf(root)).toContain('Dossier EE-2026-0001')
    expect(callsTo(api, 'GET', /^\/api\/v1\/patients\/[^/]+$/)).toHaveLength(2)
    expect(listCalls(api)).toHaveLength(1)
    unmount()
  })
})
