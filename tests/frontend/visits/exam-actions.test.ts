import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  apiError,
  CLINICIAN,
  CONSULTATION_ID,
  errorWithDetails,
  json,
  listCalls,
  openApp,
  READ_ONLY,
  resetEverything,
  startCalls,
  stubConsultationApi,
  textOf,
  visit,
  visitPage,
} from '../consultations/support'
import { VISIT_A, VISIT_B, VISIT_C } from './support'

// Démarrer et reprendre l'examen depuis la file du jour (C01) : visible seulement avec les droits, le
// module « consultations » ouvert et une visite démarrable ; jamais bloqué ni conditionné par le
// règlement de l'examen (le contrôle n'existe pas encore : B04, B06). Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-22T09:42:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

const ULID = /^[0-9A-Z]{26}$/
const VISIT_D = '01J9ZXK4M2Q8R7S6T5V4W30024'
const VISIT_E = '01J9ZXK4M2Q8R7S6T5V4W30025'
const VISIT_F = '01J9ZXK4M2Q8R7S6T5V4W30026'

const exam = (id: string, overrides: Parameters<typeof visit>[0] = {}) =>
  visit({ id, occurred_at: '2026-10-22T08:00:00.000Z', ...overrides })

/** Un exemple de chaque cas : démarrable, suivi, achat, examen en cours, terminé, attente de paiement. */
function day() {
  return visitPage([
    exam(VISIT_A),
    exam(VISIT_B, { reason: 'DIRECT_PURCHASE', exam_fee_control: 'NOT_APPLICABLE' }),
    exam(VISIT_C, { reason: 'FOLLOW_UP' }),
    exam(VISIT_D, {
      status: 'IN_CONSULTATION',
      consultation_id: CONSULTATION_ID,
      consultation_status: 'IN_PROGRESS',
    }),
    exam(VISIT_E, {
      status: 'COMPLETED',
      consultation_id: '01J9ZXK4M2Q8R7S6T5V4W30502',
      consultation_status: 'COMPLETED',
    }),
    exam(VISIT_F, { status: 'AWAITING_EXAM_FEE' }),
  ])
}

function cards(root: HTMLElement): HTMLElement {
  return root.querySelector('#file-cartes') as HTMLElement
}

function table(root: HTMLElement): HTMLElement {
  return root.querySelector('#file-tableau') as HTMLElement
}

/** Actions d'examen d'une carte : étiquette visible et nature (bouton ou lien). */
function actions(card: Element): { label: string; kind: 'button' | 'link'; href: string | null }[] {
  return [...card.querySelectorAll('button, a')]
    .filter((element) => /examen/i.test(element.textContent ?? ''))
    .map((element) => ({
      label: element.textContent?.replace(/\s+/g, ' ').trim() ?? '',
      kind: element.tagName === 'A' ? ('link' as const) : ('button' as const),
      href: element.getAttribute('href'),
    }))
}

function cardOf(root: HTMLElement, index: number): Element {
  return cards(root).querySelectorAll(':scope > li')[index] as Element
}

describe('qui voit quoi dans la file du jour', () => {
  it('démarrer pour un examen ou un suivi arrivé ; reprendre ou consulter quand une consultation existe ; rien d’autre', async () => {
    stubConsultationApi({ handlers: { list: () => json(200, day()) } })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(actions(cardOf(root, 0))).toEqual([
      { label: 'Démarrer l’examen', kind: 'button', href: null },
    ])
    // Un achat direct n'a jamais d'examen.
    expect(actions(cardOf(root, 1))).toEqual([])
    expect(actions(cardOf(root, 2))).toEqual([
      { label: 'Démarrer l’examen', kind: 'button', href: null },
    ])
    expect(actions(cardOf(root, 3))).toEqual([
      { label: 'Reprendre l’examen', kind: 'link', href: `/consultations/${CONSULTATION_ID}` },
    ])
    expect(actions(cardOf(root, 4))).toEqual([
      {
        label: 'Consulter l’examen',
        kind: 'link',
        href: '/consultations/01J9ZXK4M2Q8R7S6T5V4W30502',
      },
    ])
    // Une visite dans un autre statut n'est pas démarrable (statut décidé par le serveur).
    expect(actions(cardOf(root, 5))).toEqual([])

    // Le tableau de l'ordinateur porte les mêmes actions, dans une colonne « Examen ».
    const headers = [...table(root).querySelectorAll('th')].map((th) => th.textContent?.trim())
    expect(headers).toEqual([
      'Arrivée',
      'Patient',
      'Motif',
      'Statut',
      'Paiement de l’examen',
      'Examen',
    ])
    const rows = table(root).querySelectorAll('tbody tr')
    expect(actions(rows[0] as Element).map((action) => action.label)).toEqual(['Démarrer l’examen'])
    expect(actions(rows[3] as Element).map((action) => action.label)).toEqual([
      'Reprendre l’examen',
    ])
    expect(actions(rows[1] as Element)).toEqual([])
    unmount()
  })

  it('le nom accessible précise le patient et commence par le libellé visible', async () => {
    stubConsultationApi({ handlers: { list: () => json(200, day()) } })
    const { root, unmount } = await openApp('/aujourdhui')

    const start = cardOf(root, 0).querySelector('button') as HTMLButtonElement
    expect(start.getAttribute('aria-label')).toBe(
      'Démarrer l’examen de PATIENT FICTIF Élodie Marie',
    )
    expect(start.className).toContain('min-h-11')
    const resume = cardOf(root, 3).querySelector('a[href^="/consultations/"]') as HTMLAnchorElement
    expect(resume.getAttribute('aria-label')).toBe(
      'Reprendre l’examen de PATIENT FICTIF Élodie Marie',
    )
    expect(resume.className).toContain('min-h-11')
    unmount()
  })

  it('lecture seule (clinical.read sans clinical.write) : on reprend, on ne démarre pas', async () => {
    stubConsultationApi({ permissions: READ_ONLY, handlers: { list: () => json(200, day()) } })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(actions(cardOf(root, 0))).toEqual([])
    expect(actions(cardOf(root, 3)).map((action) => action.label)).toEqual(['Reprendre l’examen'])
    unmount()
  })

  it('clinical.write sans clinical.read : rien (le serveur répond par la consultation et refuse le démarrage sans lecture)', async () => {
    stubConsultationApi({
      permissions: ['clinical.write', 'visit.register', 'agenda.read', 'patient.read'],
      handlers: { list: () => json(200, day()) },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(actions(cardOf(root, 0))).toEqual([])
    expect(actions(cardOf(root, 3))).toEqual([])
    expect(
      [...table(root).querySelectorAll('th')].map((th) => th.textContent?.trim()),
    ).not.toContain('Examen')
    unmount()
  })

  it('sans droit clinique (accueil, caisse) : aucune action d’examen ni colonne « Examen »', async () => {
    stubConsultationApi({
      permissions: ['visit.register', 'agenda.read', 'patient.read'],
      handlers: { list: () => json(200, day()) },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(root.querySelector('#file-cartes')).not.toBeNull()
    expect(actions(cardOf(root, 0))).toEqual([])
    expect(
      [...table(root).querySelectorAll('th')].map((th) => th.textContent?.trim()),
    ).not.toContain('Examen')
    expect(root.querySelector('main a[href*="/consultations/"]')).toBeNull()
    // Le texte d'aide ne parle pas d'un examen que ce compte ne peut pas démarrer.
    expect(textOf(root)).not.toContain('Démarrer l’examen')
    unmount()
  })

  it('module « consultations » fermé : aucune action d’examen, même avec tous les droits', async () => {
    stubConsultationApi({
      modules: ['today', 'patients'],
      handlers: { list: () => json(200, day()) },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(actions(cardOf(root, 0))).toEqual([])
    expect(actions(cardOf(root, 3))).toEqual([])
    expect(
      [...table(root).querySelectorAll('th')].map((th) => th.textContent?.trim()),
    ).not.toContain('Examen')
    expect(textOf(root)).not.toContain('Démarrer l’examen')
    unmount()
  })

  it('démarrer n’est pas conditionné par le règlement : le texte le dit, et la file ne dit jamais payé, soldé ni gratuit', async () => {
    stubConsultationApi({ handlers: { list: () => json(200, day()) } })
    const { root, unmount } = await openApp('/aujourdhui')

    const text = textOf(root)
    expect(text).toContain('Le contrôle du paiement de l’examen n’est pas encore actif')
    expect(text).toContain('Démarrer l’examen n’est pas non plus bloqué par le règlement')
    expect(text).toContain('Paiement de l’examen non contrôlé')
    expect(text).not.toMatch(/pay[ée]\b|soldé|solde |gratuit|prêt pour/i)
    unmount()
  })
})

describe('démarrer l’examen', () => {
  async function openQueue(options: Parameters<typeof stubConsultationApi>[0] = {}) {
    const api = stubConsultationApi({
      handlers: { list: () => json(200, visitPage([exam(VISIT_A)])), ...options.handlers },
      ...(options.permissions === undefined ? {} : { permissions: options.permissions }),
    })
    const opened = await openApp('/aujourdhui')
    return { api, ...opened }
  }

  function startButton(root: HTMLElement): HTMLButtonElement {
    return cardOf(root, 0).querySelector('button') as HTMLButtonElement
  }

  async function click(button: HTMLElement): Promise<void> {
    button.click()
    await flushPromises()
  }

  it('envoie StartConsultation (visite, patient, instant courant UTC, aucune preuve de paiement) puis ouvre la consultation', async () => {
    const { api, root, router, unmount } = await openQueue()

    await click(startButton(root))

    expect(startCalls(api)).toHaveLength(1)
    const [call] = startCalls(api)
    expect(call?.path).toBe(`/api/v1/visits/${VISIT_A}/consultations`)
    expect(call?.headers['Idempotency-Key']).toMatch(ULID)
    expect(call?.body).toEqual({
      consultation_id: expect.stringMatching(ULID),
      visit_id: VISIT_A,
      patient_id: '01J9ZXK4M2Q8R7S6T5V4W30011',
      effective_at: '2026-10-22T09:42:00.000Z',
      date_change_reason: null,
      exam_invoice_id: null,
      exam_waiver_approval_id: null,
      expected_version: null,
    })
    const created = (call?.body as { consultation_id: string }).consultation_id
    expect(router.currentRoute.value.fullPath).toBe(`/consultations/${created}`)
    expect(root.querySelector('h1')?.textContent).toBe('Consultation')
    expect(textOf(root)).toContain('PATIENT FICTIF Élodie Marie')
    expect(textOf(root)).toContain('Brouillon')
    expect(root.querySelector('form')).not.toBeNull()
    unmount()
  })

  it('un double clic ne crée qu’une consultation', async () => {
    const { api, root, unmount } = await openQueue()
    const button = startButton(root)

    button.click()
    button.click()
    await flushPromises()

    expect(startCalls(api)).toHaveLength(1)
    unmount()
  })

  it('pendant l’envoi, le bouton est désactivé et annonce le démarrage en cours', async () => {
    let release: (response: Response) => void = () => undefined
    const { root, unmount } = await openQueue({
      handlers: {
        start: () =>
          new Promise<Response>((resolve) => {
            release = resolve
          }),
      },
    })

    startButton(root).click()
    await flushPromises()

    expect(startButton(root).disabled).toBe(true)
    expect(startButton(root).textContent).toContain('Démarrage en cours…')
    expect(startButton(root).getAttribute('aria-busy')).toBe('true')
    release(apiError(404, 'RESOURCE_NOT_FOUND', 'Introuvable.'))
    await flushPromises()
    unmount()
  })

  it('visite démarrée entre-temps (409 STATE_TRANSITION_REFUSED) : dit avec le statut actuel, la file est relue', async () => {
    let reads = 0
    const { api, root, router, unmount } = await openQueue({
      handlers: {
        list: () => {
          reads += 1
          return json(
            200,
            visitPage([
              reads === 1
                ? exam(VISIT_A)
                : exam(VISIT_A, {
                    status: 'IN_CONSULTATION',
                    consultation_id: CONSULTATION_ID,
                    consultation_status: 'DRAFT',
                  }),
            ]),
          )
        },
        start: () =>
          errorWithDetails(
            409,
            'STATE_TRANSITION_REFUSED',
            'La visite ne peut plus être démarrée.',
            {
              current_status: 'IN_CONSULTATION',
            },
          ),
      },
    })

    await click(startButton(root))

    expect(router.currentRoute.value.name).toBe('aujourdhui')
    expect(listCalls(api)).toHaveLength(2)
    const text = textOf(root)
    expect(text).toContain('La visite a changé depuis l’affichage de la file')
    expect(text).toContain(
      'L’examen de PATIENT FICTIF Élodie Marie n’a pas pu être démarré : la visite est maintenant « En consultation ». La file a été relue.',
    )
    // La file relue propose maintenant de reprendre l'examen déjà démarré.
    expect(actions(cardOf(root, 0)).map((action) => action.label)).toEqual(['Reprendre l’examen'])
    unmount()
  })

  it('refus d’état sans statut annoncé : le message du serveur est dit, la file est relue', async () => {
    const { api, root, unmount } = await openQueue({
      handlers: {
        start: () =>
          apiError(409, 'STATE_TRANSITION_REFUSED', 'Cette visite a déjà une consultation.'),
      },
    })

    await click(startButton(root))

    expect(listCalls(api)).toHaveLength(2)
    expect(textOf(root)).toContain('Cette visite a déjà une consultation.')
    unmount()
  })

  it('droits retirés (403) : le message du serveur sous le bouton, la file est conservée', async () => {
    const { api, root, router, unmount } = await openQueue({
      handlers: { start: () => apiError(403, 'ACTION_FORBIDDEN', 'Action non autorisée.') },
    })

    await click(startButton(root))

    expect(textOf(cardOf(root, 0))).toContain('Action non autorisée.')
    expect(listCalls(api)).toHaveLength(1)
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    expect(startButton(root).disabled).toBe(false)
    unmount()
  })

  it('réseau coupé : démarrage non confirmé, « Réessayer » reprend la même clé, la même consultation et le même instant', async () => {
    let attempt = 0
    const { api, root, router, unmount } = await openQueue({
      handlers: {
        start: (visitId, body) => {
          attempt += 1
          if (attempt === 1) return 'network'
          return json(201, {
            data: {
              ...api.server,
              id: String(body['consultation_id']),
              visit_id: visitId,
              steps: api.server.steps,
            },
          })
        },
      },
    })

    await click(startButton(root))

    expect(textOf(cardOf(root, 0))).toContain('L’enregistrement n’est pas confirmé')
    expect(startButton(root).textContent?.trim()).toBe('Réessayer de démarrer l’examen')
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    vi.setSystemTime(new Date('2026-10-22T09:50:00.000Z'))

    await click(startButton(root))

    const [first, second] = startCalls(api)
    expect(startCalls(api)).toHaveLength(2)
    expect(second?.headers['Idempotency-Key']).toBe(first?.headers['Idempotency-Key'])
    expect(second?.body).toEqual(first?.body)
    expect((second?.body as { effective_at: string }).effective_at).toBe('2026-10-22T09:42:00.000Z')
    expect(router.currentRoute.value.name).toBe('consultation')
    unmount()
  })

  it('session expirée : dite, aucune ouverture de consultation', async () => {
    const { api, root, router, unmount } = await openQueue({
      handlers: { start: () => apiError(401, 'AUTH_REQUIRED', 'Connexion requise.') },
    })
    api.me = 'anonymous'

    await click(startButton(root))

    expect(textOf(cardOf(root, 0))).toContain('Session expirée')
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('reprendre l’examen mène à la consultation par un lien (aucune commande envoyée)', async () => {
    const api = stubConsultationApi({ handlers: { list: () => json(200, day()) } })
    const { root, router, unmount } = await openApp('/aujourdhui')

    const link = cardOf(root, 3).querySelector('a[href^="/consultations/"]') as HTMLAnchorElement
    link.click()
    await flushPromises()

    expect(startCalls(api)).toHaveLength(0)
    expect(router.currentRoute.value.fullPath).toBe(`/consultations/${CONSULTATION_ID}`)
    expect(root.querySelector('h1')?.textContent).toBe('Consultation')
    unmount()
  })

  it('l’accueil (visit.register seul) ne voit aucun bouton de démarrage : droit d’écriture clinique requis', async () => {
    const { root, unmount } = await openQueue({
      permissions: ['visit.register', 'agenda.read', 'patient.read'],
    })

    expect(cardOf(root, 0).querySelector('button')).toBeNull()
    expect(CLINICIAN).toContain('clinical.write')
    unmount()
  })
})
