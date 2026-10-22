import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { revalidateSession } from '@/app/session'
import { setArrivalFlash } from '@/pages/today/flash'

import {
  apiError,
  buttonByName,
  CASH_DESK,
  click,
  field,
  FRONT_DESK,
  json,
  linkTo,
  listCalls,
  openApp,
  resetEverything,
  stubVisitsApi,
  textOf,
  type,
  VISIT_A,
  VISIT_B,
  VISIT_C,
  visit,
  visitPage,
} from './support'

// File du jour (P03 ; AT05) : jour civil du cabinet, jours voisins, pagination, états chargement /
// vide / hors ligne / erreur / droits, marqueur du contrôle du paiement de l'examen (inexistant à ce
// jalon) et absence de toute parole sur le règlement. Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

/** Ce que l'écran n'a le droit de dire à aucun moment : le règlement de l'examen n'est pas contrôlé. */
const NEVER_SAID = /pay[ée]|solde|soldé|gratuit|prêt pour|démarrer l.examen|commencer l.examen/i

function cards(root: HTMLElement): HTMLElement {
  return root.querySelector('#file-cartes') as HTMLElement
}

function table(root: HTMLElement): HTMLElement {
  return root.querySelector('#file-tableau') as HTMLElement
}

describe('écran Aujourd’hui : jour civil du cabinet', () => {
  it('lit la file du jour civil de Douala, jamais celui de l’UTC, et dit le jour en toutes lettres', async () => {
    // 23 h 30 UTC le 14 : il est déjà 0 h 30 le 15 à Douala (UTC+1).
    vi.setSystemTime(new Date('2026-10-14T23:30:00.000Z'))
    const api = stubVisitsApi()
    const { root, unmount } = await openApp('/aujourdhui')

    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Aujourd’hui')
    expect(document.title).toBe('Aujourd’hui – Eagle’s Eye Optics Medical')
    expect(listCalls(api)).toHaveLength(1)
    expect(listCalls(api)[0]?.query.get('date')).toBe('2026-10-15')
    expect(listCalls(api)[0]?.query.get('page')).toBe('1')
    expect(textOf(root)).toContain('File du jour : jeudi 15 octobre 2026 (aujourd’hui)')
    unmount()
  })

  it('« Jour précédent », « Jour suivant » et « Aujourd’hui » changent le jour lu', async () => {
    const api = stubVisitsApi()
    const { root, unmount } = await openApp('/aujourdhui')
    const days = () => listCalls(api).map((call) => call.query.get('date'))
    expect(days()).toEqual(['2026-10-15'])

    await click(buttonByName(root, 'Jour précédent'))
    expect(days()).toEqual(['2026-10-15', '2026-10-14'])
    expect(textOf(root)).toContain('File du jour : mercredi 14 octobre 2026')
    expect(textOf(root)).not.toContain('(aujourd’hui)')
    expect((field(root, 'Jour affiché') as HTMLInputElement).value).toBe('14/10/2026')

    await click(buttonByName(root, 'Jour suivant'))
    await click(buttonByName(root, 'Jour suivant'))
    expect(days().slice(-2)).toEqual(['2026-10-15', '2026-10-16'])
    expect(textOf(root)).toContain('File du jour : vendredi 16 octobre 2026')

    await click(buttonByName(root, 'Aujourd’hui'))
    expect(days().at(-1)).toBe('2026-10-15')
    expect(textOf(root)).toContain('(aujourd’hui)')
    expect((field(root, 'Jour affiché') as HTMLInputElement).value).toBe('15/10/2026')
    unmount()
  })

  it('une date saisie (JJ/MM/AAAA) est lue à la sortie du champ ; une date refusée ne change pas le jour', async () => {
    const api = stubVisitsApi()
    const { root, unmount } = await openApp('/aujourdhui')
    const input = field(root, 'Jour affiché') as HTMLInputElement

    await type(root, 'Jour affiché', '20/10/2026')
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(listCalls(api).at(-1)?.query.get('date')).toBe('2026-10-20')
    expect(textOf(root)).toContain('File du jour : mardi 20 octobre 2026')

    // Date qui n'existe pas : message, aucune lecture, le jour affiché reste celui du 20.
    const before = listCalls(api).length
    await type(root, 'Jour affiché', '31/02/2026')
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(textOf(root)).toContain('Cette date n’existe pas dans le calendrier.')
    expect(listCalls(api)).toHaveLength(before)
    expect(textOf(root)).toContain('File du jour : mardi 20 octobre 2026')

    // Champ vidé : obligatoire, aucune lecture.
    await type(root, 'Jour affiché', '')
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(textOf(root)).toContain('Ce champ est obligatoire.')
    expect(listCalls(api)).toHaveLength(before)
    unmount()
  })

  it('« Actualiser la file » relit le même jour', async () => {
    const api = stubVisitsApi()
    const { root, unmount } = await openApp('/aujourdhui')

    await click(buttonByName(root, 'Actualiser la file'))

    expect(listCalls(api).map((call) => call.query.get('date'))).toEqual([
      '2026-10-15',
      '2026-10-15',
    ])
    unmount()
  })

  it('la réponse tardive d’un autre jour ne s’affiche jamais à la place du jour courant', async () => {
    let release: (response: Response) => void = () => undefined
    const api = stubVisitsApi({
      handlers: {
        list: (day) =>
          day === '2026-10-15'
            ? new Promise<Response>((resolve) => {
                release = resolve
              })
            : json(
                200,
                visitPage([
                  visit({ id: VISIT_B, patient: { ...visit().patient, last_name: 'HIER FICTIF' } }),
                ]),
              ),
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')
    expect(textOf(root)).toContain('Chargement de la file du jour…')

    await click(buttonByName(root, 'Jour précédent'))
    expect(textOf(root)).toContain('HIER FICTIF')

    release(
      json(
        200,
        visitPage([visit({ patient: { ...visit().patient, last_name: 'TARDIF FICTIF' } })]),
      ),
    )
    await flushPromises()

    expect(textOf(root)).toContain('HIER FICTIF')
    expect(textOf(root)).not.toContain('TARDIF FICTIF')
    expect(listCalls(api)).toHaveLength(2)
    unmount()
  })
})

describe('liste des arrivées', () => {
  const second = visit({
    id: VISIT_B,
    patient_id: '01J9ZXK4M2Q8R7S6T5V4W30012',
    reason: 'DIRECT_PURCHASE',
    occurred_at: '2026-10-15T09:40:00.000Z',
    exam_fee_control: 'NOT_APPLICABLE',
    administrative_note: 'Vient chercher des lunettes',
    patient: {
      id: '01J9ZXK4M2Q8R7S6T5V4W30012',
      record_number: 'EE-2026-0002',
      last_name: 'AUTRE FICTIF',
      first_names: null,
      sex: 'M',
      birth_date: null,
    },
  })

  it('montre pour chaque arrivée l’heure du cabinet, le patient, son dossier et son âge, le motif, le statut et le marqueur', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([visit(), second])) } })
    const { root, unmount } = await openApp('/aujourdhui')

    const first = cards(root).querySelectorAll('li')[0] as HTMLElement
    const text = textOf(first)
    expect(text).toContain('09h15')
    expect(text).toContain('PATIENT FICTIF Élodie Marie')
    expect(text).toContain('Dossier EE-2026-0001 · 36 ans')
    expect(text).toContain('Examen de vue')
    expect(text).toContain('Arrivé')
    expect(text).toContain('Paiement de l’examen non contrôlé')

    const other = textOf(cards(root).querySelectorAll('li')[1] as HTMLElement)
    expect(other).toContain('10h40')
    expect(other).toContain('AUTRE FICTIF')
    expect(other).toContain('Dossier EE-2026-0002 · âge non renseigné')
    expect(other).toContain('Achat direct')
    expect(other).toContain('Pas d’examen')
    expect(other).not.toContain('non contrôlé')
    expect(other).toContain('Note : Vient chercher des lunettes')

    // Le même contenu en tableau pour l'ordinateur, avec de vrais en-têtes de colonnes.
    const headers = [...table(root).querySelectorAll('th')].map((th) => th.textContent?.trim())
    expect(headers).toEqual(['Arrivée', 'Patient', 'Motif', 'Statut', 'Paiement de l’examen'])
    const rows = table(root).querySelectorAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(textOf(rows[0] as HTMLElement)).toContain('09h15')
    expect(textOf(rows[0] as HTMLElement)).toContain('Paiement de l’examen non contrôlé')
    expect(textOf(rows[1] as HTMLElement)).toContain('Pas d’examen')
    unmount()
  })

  it('le marqueur du contrôle de l’examen a un symbole : la couleur n’est jamais le seul signal', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([visit()])) } })
    const { root, unmount } = await openApp('/aujourdhui')

    const marker = [...cards(root).querySelectorAll('span')].find((span) =>
      span.textContent?.includes('Paiement de l’examen non contrôlé'),
    )
    expect(marker?.querySelector('[aria-hidden="true"]')?.textContent).toBe('⚠')
    unmount()
  })

  it('explique que le contrôle du paiement de l’examen n’est pas actif, et ne dit jamais payé, soldé ou gratuit', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([visit(), second])) } })
    const { root, unmount } = await openApp('/aujourdhui')

    const text = textOf(root)
    expect(text).toContain('Le contrôle du paiement de l’examen n’est pas encore actif')
    expect(text).toContain('pas bloquée faute de règlement')
    expect(text).toContain('Un achat direct ou un retrait de commande n’impose aucun examen')
    expect(text).not.toMatch(NEVER_SAID)
    unmount()
  })

  it('aucun bouton ni lien ne démarre un examen ou ouvre une consultation (aucun écran de consultation à ce jalon)', async () => {
    stubVisitsApi({
      handlers: {
        list: () =>
          json(200, visitPage([visit(), second, visit({ id: VISIT_C, reason: 'FOLLOW_UP' })])),
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    const actions = [...root.querySelectorAll('main button, main a')].map((element) =>
      element.textContent?.replace(/\s+/g, ' ').trim(),
    )
    expect(
      actions.filter((label) => /examen|consultation|démarrer|commencer/i.test(label ?? '')),
    ).toEqual([])
    expect(root.querySelector('main a[href*="consultation"]')).toBeNull()
    unmount()
  })

  it('chaque statut renvoyé par le serveur est dit avec son libellé exact', async () => {
    const statuses = [
      ['ARRIVED', 'Arrivé'],
      ['AWAITING_EXAM_FEE', 'En attente du paiement de l’examen'],
      ['READY_FOR_EXAM', 'Prêt pour l’examen'],
      ['IN_CONSULTATION', 'En consultation'],
      ['COMPLETED', 'Terminé'],
      ['CANCELLED', 'Annulé'],
    ] as const
    stubVisitsApi({
      handlers: {
        list: () =>
          json(
            200,
            visitPage(
              statuses.map(([status], index) =>
                visit({ id: `01J9ZXK4M2Q8R7S6T5V4W300${30 + index}`, status }),
              ),
            ),
          ),
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    const rows = [...cards(root).querySelectorAll('li')]
    expect(rows).toHaveLength(6)
    statuses.forEach(([, label], index) => {
      expect(textOf(rows[index] as HTMLElement), label).toContain(label)
    })
    unmount()
  })

  it('un statut ou un contrôle qui ne vient pas du serveur n’est jamais inventé : « Prêt pour l’examen » absent d’une arrivée simple', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([visit()])) } })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).not.toContain('Prêt pour l’examen')
    expect(textOf(root)).not.toContain('En attente du paiement')
    unmount()
  })

  it('cartes sur téléphone, tableau à partir de 1024 px : aucune des deux ne force un défilement horizontal', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([visit()])) } })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(cards(root).className).toContain('lg:hidden')
    expect(table(root).className).toContain('hidden')
    expect(table(root).className).toContain('lg:table')
    expect(root.querySelector('main')?.innerHTML).not.toContain('overflow-x-')
    expect(table(root).querySelector('caption')?.textContent?.trim()).toContain('File du jour')
    unmount()
  })

  it('le nom du patient ouvre sa fiche pour qui peut lire les patients, quand ce module est ouvert', async () => {
    stubVisitsApi({
      permissions: FRONT_DESK,
      modules: ['today', 'patients'],
      handlers: { list: () => json(200, visitPage([visit()])) },
    })
    const open = await openApp('/aujourdhui')
    expect(linkTo(cards(open.root), '/patients/01J9ZXK4M2Q8R7S6T5V4W30011')?.textContent).toContain(
      'PATIENT FICTIF',
    )
    expect(linkTo(table(open.root), '/patients/01J9ZXK4M2Q8R7S6T5V4W30011')).not.toBeNull()
    open.unmount()
    resetEverything()

    // Module patients fermé : le nom reste lisible, sans lien vers une page qui n'existe pas.
    stubVisitsApi({
      permissions: FRONT_DESK,
      modules: ['today'],
      handlers: { list: () => json(200, visitPage([visit()])) },
    })
    const closed = await openApp('/aujourdhui')
    expect(textOf(closed.root)).toContain('PATIENT FICTIF')
    expect(closed.root.querySelector('a[href^="/patients/"]')).toBeNull()
    closed.unmount()
  })
})

describe('états de l’écran', () => {
  it('« Aucune visite ce jour » seulement quand le serveur a répondu avec une file vide', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([])) } })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).toContain('Aucune visite ce jour')
    expect(textOf(root)).not.toContain('File du jour indisponible')
    expect(cards(root)).toBeNull()
    unmount()
  })

  it('serveur injoignable : « File du jour indisponible », jamais « aucune visite » ; « Réessayer » relit', async () => {
    let up = false
    const api = stubVisitsApi({
      handlers: { list: () => (up ? json(200, visitPage([visit()])) : 'network') },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).toContain('File du jour indisponible')
    expect(textOf(root)).toContain('Cela ne prouve pas qu’aucun patient ne soit arrivé')
    expect(textOf(root)).not.toContain('Aucune visite ce jour')
    expect(cards(root)).toBeNull()

    up = true
    await click(buttonByName(root, 'Réessayer'))

    expect(listCalls(api)).toHaveLength(2)
    expect(textOf(root)).toContain('PATIENT FICTIF')
    expect(textOf(root)).not.toContain('File du jour indisponible')
    unmount()
  })

  it.each([
    ['une erreur 500', () => apiError(500, 'INTERNAL_ERROR', 'Erreur interne.')],
    [
      'une page d’erreur de l’hébergeur',
      () =>
        new Response('<!DOCTYPE html><html><body>503</body></html>', {
          status: 503,
          headers: { 'Content-Type': 'text/html' },
        }),
    ],
  ])('%s : « File du jour indisponible », pas une file vide', async (_name, reply) => {
    stubVisitsApi({ handlers: { list: reply } })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).toContain('File du jour indisponible')
    expect(textOf(root)).not.toContain('Aucune visite ce jour')
    unmount()
  })

  it('une relecture qui échoue ne présente pas l’ancienne file comme à jour', async () => {
    let attempt = 0
    stubVisitsApi({
      handlers: {
        list: () => {
          attempt += 1
          return attempt === 1 ? json(200, visitPage([visit()])) : 'network'
        },
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')
    expect(textOf(root)).toContain('PATIENT FICTIF')

    await click(buttonByName(root, 'Actualiser la file'))

    expect(textOf(root)).toContain('File du jour indisponible')
    expect(textOf(root)).not.toContain('PATIENT FICTIF')
    unmount()
  })

  it('droits insuffisants du serveur (403) : dit sans afficher de file', async () => {
    stubVisitsApi({
      handlers: { list: () => apiError(403, 'ACTION_FORBIDDEN', 'Action non autorisée.') },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).toContain('Droits insuffisants')
    expect(textOf(root)).not.toContain('Aucune visite ce jour')
    unmount()
  })

  it('refus 422 du serveur : le message du serveur est montré, sans « Réessayer » inutile', async () => {
    stubVisitsApi({
      handlers: {
        list: () =>
          apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
            page: ['La page est un entier supérieur ou égal à 1.'],
          }),
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).toContain('La file du jour n’a pas pu être lue')
    expect(textOf(root)).toContain('La page est un entier supérieur ou égal à 1.')
    expect(buttonByName(root, 'Réessayer')).toBeNull()
    unmount()
  })

  it('session expirée (401) : dite, avec « Se reconnecter », et « Réessayer » relit après la reconnexion', async () => {
    let expired = true
    const api = stubVisitsApi({
      handlers: {
        list: () =>
          expired
            ? apiError(401, 'AUTH_REQUIRED', 'Connexion requise.')
            : json(200, visitPage([visit()])),
      },
    })
    const reconnected = api.me
    const { root, unmount } = await openApp('/aujourdhui')
    api.me = 'anonymous'
    await click(buttonByName(root, 'Actualiser la file'))

    expect(textOf(root)).toContain('Session expirée')
    expect(buttonByName(root, 'Se reconnecter')).not.toBeNull()
    expect(textOf(root)).not.toContain('Aucune visite ce jour')

    expired = false
    api.me = reconnected
    await click(buttonByName(root, 'Réessayer'))
    expect(textOf(root)).toContain('PATIENT FICTIF')
    unmount()
  })

  it('page dépassée : la file a changé, retour à la première page proposé', async () => {
    const api = stubVisitsApi({
      handlers: {
        list: (_day, page) =>
          page === 1
            ? json(200, visitPage([visit()], { current_page: 1, last_page: 2, total: 51 }))
            : json(200, visitPage([], { current_page: 3, last_page: 2, total: 51 })),
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')
    await click(buttonByName(root, 'Page suivante'))

    expect(textOf(root)).toContain('Cette page ne contient plus de visites')
    expect(textOf(root)).not.toContain('Aucune visite ce jour')

    await click(buttonByName(root, 'Revenir à la première page'))
    expect(listCalls(api).at(-1)?.query.get('page')).toBe('1')
    expect(textOf(root)).toContain('PATIENT FICTIF')
    unmount()
  })
})

describe('pagination', () => {
  it('50 par page : la page demandée part au serveur, le focus va au titre de la file', async () => {
    const api = stubVisitsApi({
      handlers: {
        list: (_day, page) =>
          json(
            200,
            visitPage([visit({ id: page === 1 ? VISIT_A : VISIT_B })], {
              current_page: page,
              last_page: 3,
              total: 120,
            }),
          ),
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(textOf(root)).toContain('Page 1 sur 3 · 120 visites')
    expect(root.querySelector('nav[aria-label="Pagination de la file du jour"]')).not.toBeNull()
    expect((buttonByName(root, 'Page précédente') as HTMLButtonElement).disabled).toBe(true)

    await click(buttonByName(root, 'Page suivante'))

    expect(listCalls(api).at(-1)?.query.get('page')).toBe('2')
    expect(listCalls(api).at(-1)?.query.get('date')).toBe('2026-10-15')
    expect(textOf(root)).toContain('Page 2 sur 3 · 120 visites')
    expect(document.activeElement?.id).toBe('titre-file')
    unmount()
  })

  it('changer de jour revient à la première page', async () => {
    const api = stubVisitsApi({
      handlers: {
        list: (_day, page) =>
          json(200, visitPage([visit()], { current_page: page, last_page: 3, total: 120 })),
      },
    })
    const { root, unmount } = await openApp('/aujourdhui')
    await click(buttonByName(root, 'Page suivante'))
    expect(listCalls(api).at(-1)?.query.get('page')).toBe('2')

    await click(buttonByName(root, 'Jour précédent'))

    expect(listCalls(api).at(-1)?.query.get('date')).toBe('2026-10-14')
    expect(listCalls(api).at(-1)?.query.get('page')).toBe('1')
    unmount()
  })

  it('une seule page : aucune pagination', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([visit()])) } })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(root.querySelector('nav[aria-label="Pagination de la file du jour"]')).toBeNull()
    unmount()
  })
})

describe('annonce et fraîcheur', () => {
  it('annonce le nombre de visites aux lecteurs d’écran et donne l’heure de la réponse du serveur', async () => {
    stubVisitsApi({
      handlers: { list: () => json(200, visitPage([visit(), visit({ id: VISIT_B })])) },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    const status = [...root.querySelectorAll('[role="status"].sr-only')].map((element) =>
      element.textContent?.trim(),
    )
    expect(status).toContain('2 visites')
    // 09 h 00 UTC = 10 h 00 à Douala.
    expect(textOf(root)).toContain('Données du serveur au 15/10/2026 à 10h00.')
    unmount()
  })

  it('annonce l’indisponibilité de la file, jamais une file vide', async () => {
    stubVisitsApi({ handlers: { list: () => 'network' } })
    const { root, unmount } = await openApp('/aujourdhui')

    const status = [...root.querySelectorAll('[role="status"].sr-only')].map((element) =>
      element.textContent?.trim(),
    )
    expect(status).toContain('File du jour indisponible')
    expect(status).not.toContain('Aucune visite ce jour')
    unmount()
  })
})

describe('arrivée enregistrée : message en mémoire', () => {
  it('montre « Arrivée enregistrée : … » une seule fois, sans le mettre dans l’adresse', async () => {
    stubVisitsApi({ handlers: { list: () => json(200, visitPage([visit()])) } })
    const { root, router, unmount } = await openApp('/')
    setArrivalFlash('Arrivée enregistrée : PATIENT FICTIF Élodie Marie')

    await router.push('/aujourdhui')
    await flushPromises()
    expect(textOf(root)).toContain('Arrivée enregistrée : PATIENT FICTIF Élodie Marie')
    expect(router.currentRoute.value.fullPath).toBe('/aujourdhui')

    await router.push('/')
    await router.push('/aujourdhui')
    await flushPromises()
    expect(textOf(root)).not.toContain('Arrivée enregistrée')
    unmount()
  })

  it('n’est jamais montré à un autre compte connecté sur le même poste', async () => {
    const api = stubVisitsApi({ handlers: { list: () => json(200, visitPage([])) } })
    const { root, router, unmount } = await openApp('/')
    setArrivalFlash('Arrivée enregistrée : PATIENT FICTIF Élodie Marie')
    // Autre compte (autre onglet) : la session est relue avant d'ouvrir l'écran.
    const other = structuredClone(api.me)
    if (typeof other === 'object') other.data.id = '01J9ZXK4M2Q8R7S6T5V4W39999'
    api.me = other

    await revalidateSession()
    await router.push('/aujourdhui')
    await flushPromises()

    expect(textOf(root)).not.toContain('PATIENT FICTIF')
    unmount()
  })
})

describe('droits et module', () => {
  it('« Enregistrer une arrivée » mène au formulaire pour qui a visit.register', async () => {
    stubVisitsApi({ permissions: FRONT_DESK })
    const { root, unmount } = await openApp('/aujourdhui')

    const link = linkTo(root, '/aujourdhui/arrivee')
    expect(link?.textContent?.trim()).toBe('Enregistrer une arrivée')
    expect(link?.className).toContain('min-h-11')
    unmount()
  })

  it('agenda.read seul (caisse) : la file se lit, sans bouton d’arrivée', async () => {
    const api = stubVisitsApi({
      permissions: CASH_DESK,
      handlers: { list: () => json(200, visitPage([visit()])) },
    })
    const { root, router, unmount } = await openApp('/aujourdhui')

    expect(router.currentRoute.value.name).toBe('aujourdhui')
    expect(textOf(root)).toContain('PATIENT FICTIF')
    expect(linkTo(root, '/aujourdhui/arrivee')).toBeNull()
    expect(buttonByName(root, 'Enregistrer une arrivée')).toBeNull()
    expect(listCalls(api)).toHaveLength(1)
    unmount()
  })

  it('visit.register seul : la file se lit aussi (l’une ou l’autre permission suffit)', async () => {
    const api = stubVisitsApi({ permissions: ['visit.register'] })
    const { router, unmount } = await openApp('/aujourdhui')

    expect(router.currentRoute.value.name).toBe('aujourdhui')
    expect(listCalls(api)).toHaveLength(1)
    unmount()
  })

  it('sans visit.register ni agenda.read : accès refusé, la file n’est pas lue', async () => {
    const api = stubVisitsApi({ permissions: ['patient.read', 'invoice.read', 'clinical.read'] })
    const { root, router, unmount } = await openApp('/aujourdhui')

    expect(router.currentRoute.value.name).toBe('acces-refuse')
    expect(listCalls(api)).toHaveLength(0)
    expect(root.querySelector('#file-cartes')).toBeNull()
    unmount()
  })

  it('module « today » fermé : « Page introuvable », liens masqués, rien n’est lu', async () => {
    const api = stubVisitsApi({ modules: ['patients'] })
    const { root, unmount } = await openApp('/aujourdhui')

    expect(root.querySelector('h1')?.textContent).toBe('Page introuvable')
    expect(listCalls(api)).toHaveLength(0)
    expect(root.querySelector('a[href="/aujourdhui"]')).toBeNull()
    unmount()
  })

  it('module ouvert : le menu et l’accueil mènent à la file du jour', async () => {
    stubVisitsApi({ permissions: FRONT_DESK })
    const { root, unmount } = await openApp('/')

    const home = root.querySelector('main nav a[href="/aujourdhui"]')
    expect(home?.textContent?.trim()).toBe('Consultations du jour')
    const menu = [...root.querySelectorAll('nav[aria-label="Navigation principale"] a')].map(
      (link) => link.textContent?.trim(),
    )
    expect(menu).toContain('Aujourd’hui')
    unmount()
  })

  it('contrôles de 44 px au moins et accessibles : tous nommés', async () => {
    stubVisitsApi({
      handlers: { list: () => json(200, visitPage([visit()], { last_page: 2, total: 60 })) },
    })
    const { root, unmount } = await openApp('/aujourdhui')

    const controls = [...root.querySelectorAll<HTMLElement>('main input, main button')]
    expect(controls.length).toBeGreaterThan(5)
    for (const control of controls) {
      expect(control.className, control.outerHTML).toContain('min-h-11')
      const named =
        control instanceof HTMLButtonElement
          ? (control.textContent?.trim() ?? '') !== ''
          : (control as HTMLInputElement).labels?.length === 1
      expect(named, control.outerHTML).toBe(true)
    }
    unmount()
  })
})
