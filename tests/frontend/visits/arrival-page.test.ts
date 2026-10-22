import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { MutationDispatcher } from '@/app/services/mutations'
import { isUlid } from '@/domain/values/ulid'

import { meResponse } from '../shell/server'
import {
  apiError,
  buttonByName,
  CASH_DESK,
  callsTo,
  choose,
  chooseResult,
  click,
  field,
  FRONT_DESK,
  hasField,
  ID_A,
  ID_B,
  json,
  linkTo,
  listCalls,
  openApp,
  pageOf,
  patient,
  registerCalls,
  resetEverything,
  searchPatientField,
  stubVisitsApi,
  submitForm,
  summaryOf,
  textOf,
  type,
  VISIT_A,
  visit,
  visitPage,
  type VisitsApi,
} from './support'

// Enregistrer une arrivée (P03 ; AT05) : choix du patient par recherche ou depuis sa fiche, motif
// explicite, note administrative facultative, enregistrement EN LIGNE (« Synchronisé » seulement après
// la réponse), reprise avec la même clé, erreurs par champ, session expirée, avertissement non
// bloquant d'une autre visite du jour. Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

const FORM = '/aujourdhui/arrivee'
const NEVER_SAID = /pay[ée]|solde|soldé|gratuit|prêt pour|démarrer l.examen/i
const FOUND = patient({ id: ID_A, last_name: 'NGONO FICTIVE', first_names: 'Marie' })

/** Serveur dont la recherche trouve NGONO FICTIVE Marie et dont l'enregistrement réussit. */
function server(options: Parameters<typeof stubVisitsApi>[0] = {}): VisitsApi {
  return stubVisitsApi({
    ...options,
    handlers: {
      search: () => json(200, pageOf([summaryOf(FOUND)])),
      get: (id) =>
        id === ID_A
          ? json(200, { data: FOUND })
          : apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.'),
      ...options.handlers,
    },
  })
}

async function choosePatient(root: HTMLElement, text = 'ngono'): Promise<void> {
  const input = await searchPatientField(root, text)
  await chooseResult(input, 0)
}

async function chooseReason(root: HTMLElement, value: string): Promise<void> {
  await choose(root, 'Motif de la visite', value)
}

function pastDialog(): void {
  vi.spyOn(window, 'confirm').mockReturnValue(true)
}

describe('formulaire d’arrivée', () => {
  it('propose le patient, le motif et la note, avec de vrais libellés, sans aucune parole sur le règlement', async () => {
    server()
    const { root, unmount } = await openApp(FORM)

    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Enregistrer une arrivée')
    expect(document.title).toBe('Enregistrer une arrivée – Eagle’s Eye Optics Medical')
    for (const label of ['Patient', 'Motif de la visite', 'Note administrative (facultative)']) {
      expect(hasField(root, label), label).toBe(true)
    }
    expect(root.querySelector('label[for="arrivee-patient"]')?.textContent).toContain(
      '(obligatoire)',
    )
    expect(root.querySelector('label[for="arrivee-motif"]')?.textContent).toContain('(obligatoire)')
    expect(field(root, 'Patient').getAttribute('role')).toBe('combobox')
    expect(field(root, 'Note administrative').tagName).toBe('TEXTAREA')

    const reason = field(root, 'Motif de la visite') as HTMLSelectElement
    expect([...reason.options].map((option) => option.textContent?.trim())).toEqual([
      'Choisir le motif',
      'Examen de vue',
      'Achat direct',
      'Retrait de commande',
      'Contrôle de suivi',
    ])
    expect(reason.value).toBe('')
    expect(buttonByName(root, 'Enregistrer l’arrivée')).not.toBeNull()
    expect(root.querySelector('main a[href="/aujourdhui"]')?.textContent?.trim()).toBe('Annuler')
    expect(textOf(root)).not.toContain('Synchronisé')
    expect(textOf(root)).not.toContain('Enregistré sur ce poste')
    expect(textOf(root)).not.toMatch(NEVER_SAID)
    unmount()
  })

  it('contrôles de 44 px au moins et accessibles : tous nommés, aucun sans libellé', async () => {
    server()
    const { root, unmount } = await openApp(FORM)

    const controls = [
      ...root.querySelectorAll<HTMLElement>('main input, main select, main textarea, main button'),
    ]
    expect(controls.length).toBeGreaterThanOrEqual(4)
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

  it('explique le motif choisi : examen sans contrôle du règlement, achat ou retrait sans examen', async () => {
    server()
    const { root, unmount } = await openApp(FORM)

    await chooseReason(root, 'EXAM')
    expect(textOf(root)).toContain('Le contrôle du paiement de l’examen n’est pas encore actif')
    expect(textOf(root)).toContain('n’est pas bloqué faute de règlement')

    await chooseReason(root, 'DIRECT_PURCHASE')
    expect(textOf(root)).toContain('Aucun examen ni consultation n’est créé pour cette visite.')
    expect(textOf(root)).not.toContain('Le contrôle du paiement de l’examen n’est pas encore actif')

    await chooseReason(root, 'PICKUP')
    expect(textOf(root)).toContain('Aucun examen ni consultation n’est créé pour cette visite.')

    await chooseReason(root, 'FOLLOW_UP')
    expect(textOf(root)).toContain('Le contrôle du paiement de l’examen n’est pas encore actif')
    expect(textOf(root)).not.toMatch(NEVER_SAID)
    unmount()
  })

  it('ne propose aucun bouton ni lien d’examen ou de consultation', async () => {
    server()
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')

    const labels = [...root.querySelectorAll('main button, main a')].map((element) =>
      element.textContent?.replace(/\s+/g, ' ').trim(),
    )
    expect(labels.filter((label) => /démarrer|commencer|consultation/i.test(label ?? ''))).toEqual(
      [],
    )
    unmount()
  })
})

describe('choix du patient par recherche', () => {
  it('cherche à partir de deux caractères, montre les résultats et se choisit au clavier', async () => {
    const api = server()
    const { root, unmount } = await openApp(FORM)
    const input = field(root, 'Patient') as HTMLInputElement

    // Un seul caractère : aucune recherche, la consigne est donnée.
    await searchPatientField(root, 'n')
    expect(callsTo(api, 'GET', /^\/api\/v1\/patients$/)).toHaveLength(0)
    expect(textOf(root)).toContain('Saisissez au moins 2 caractères.')

    await searchPatientField(root, 'ngo')
    const searches = callsTo(api, 'GET', /^\/api\/v1\/patients$/)
    expect(searches).toHaveLength(1)
    expect(searches[0]?.query.get('query')).toBe('ngo')
    expect(searches[0]?.query.get('page')).toBe('1')
    const option = root.querySelector('[role="option"]') as HTMLElement
    expect(textOf(option)).toContain('NGONO FICTIVE Marie')
    expect(textOf(option)).toContain('Dossier EE-2026-0001 · 36 ans · +237600000001')
    expect(input.getAttribute('aria-expanded')).toBe('true')

    await chooseResult(input, 0)

    expect(input.value).toBe('NGONO FICTIVE Marie')
    expect(input.getAttribute('aria-expanded')).toBe('false')
    // L'identité choisie est montrée avant d'enregistrer : dossier, sexe et âge.
    const text = textOf(root)
    expect(text).toContain('Dossier EE-2026-0001')
    expect(text).toContain('Féminin')
    expect(text).toContain('36 ans')
    expect(text).toContain('Patient choisi : NGONO FICTIVE Marie.')
    unmount()
  })

  it('modifier le texte annule le choix précédent : rien n’est enregistré pour un autre patient', async () => {
    const api = server()
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')

    await searchPatientField(root, 'autre nom')
    await submitForm(root)

    expect(registerCalls(api)).toHaveLength(0)
    expect(textOf(root)).toContain('Choisissez le patient dans la liste des résultats.')
    unmount()
  })

  it('recherche indisponible : « Recherche serveur indisponible », jamais « Aucun résultat »', async () => {
    server({ handlers: { search: () => 'network' } })
    const { root, unmount } = await openApp(FORM)

    await searchPatientField(root, 'ngono')

    expect(textOf(root)).toContain('Recherche serveur indisponible')
    expect(textOf(root)).toContain('ne prouve pas que le patient n’existe pas')
    expect(textOf(root)).not.toContain('Aucun résultat')
    unmount()
  })

  it('droits de recherche refusés par le serveur (403) : dit, sans « Aucun résultat »', async () => {
    server({
      handlers: { search: () => apiError(403, 'ACTION_FORBIDDEN', 'Action non autorisée.') },
    })
    const { root, unmount } = await openApp(FORM)

    await searchPatientField(root, 'ngono')

    expect(textOf(root)).toContain('Ce compte n’a pas le droit de rechercher des patients.')
    expect(textOf(root)).not.toContain('Aucun résultat')
    unmount()
  })

  it('« Aucun résultat » seulement quand le serveur a répondu sans fiche, avec le lien pour créer une fiche', async () => {
    server({ handlers: { search: () => json(200, pageOf([])) } })
    const { root, unmount } = await openApp(FORM)

    await searchPatientField(root, 'inconnu')

    expect(textOf(root)).toContain('Aucun résultat pour « inconnu ».')
    expect(linkTo(root, '/patients/nouveau')?.textContent?.trim()).toBe('Créer une fiche patient')
    unmount()
  })

  it('plus de résultats que la première page : l’avertissement est donné', async () => {
    server({
      handlers: {
        search: () => json(200, pageOf([summaryOf(FOUND)], { total: 40, last_page: 2 })),
      },
    })
    const { root, unmount } = await openApp(FORM)

    await searchPatientField(root, 'ngono')

    expect(textOf(root)).toContain('40 fiches correspondent : précisez la recherche')
    unmount()
  })

  it('« Créer une fiche patient » seulement avec patient.create et le module patients ouvert', async () => {
    server({ permissions: ['visit.register', 'patient.read'] })
    const noRight = await openApp(FORM)
    expect(linkTo(noRight.root, '/patients/nouveau')).toBeNull()
    noRight.unmount()
    resetEverything()

    server({ permissions: FRONT_DESK, modules: ['today'] })
    const closed = await openApp(FORM)
    expect(linkTo(closed.root, '/patients/nouveau')).toBeNull()
    closed.unmount()
  })

  it('une fiche archivée est dite et ne peut pas recevoir d’arrivée', async () => {
    const api = server({
      handlers: {
        search: () => json(200, pageOf([summaryOf(patient({ id: ID_B, archived: true }))])),
      },
    })
    const { root, unmount } = await openApp(FORM)
    const input = await searchPatientField(root, 'archive')
    expect(textOf(root.querySelector('[role="option"]') as Element)).toContain('Fiche archivée')

    await chooseResult(input, 0)
    expect(textOf(root)).toContain('Cette fiche est archivée')
    expect(field(root, 'Patient').getAttribute('aria-invalid')).toBe('true')

    await chooseReason(root, 'EXAM')
    await submitForm(root)

    expect(registerCalls(api)).toHaveLength(0)
    expect((root.querySelector('[role="alert"]') as HTMLElement).textContent).toContain(
      'Cette fiche est archivée',
    )
    unmount()
  })

  it('une fiche fusionnée est dite et ne peut pas recevoir d’arrivée', async () => {
    const api = server({
      handlers: {
        search: () =>
          json(200, pageOf([summaryOf(patient({ id: ID_B, merged_into_patient_id: ID_A }))])),
      },
    })
    const { root, unmount } = await openApp(FORM)
    const input = await searchPatientField(root, 'fusion')
    await chooseResult(input, 0)
    await chooseReason(root, 'EXAM')

    await submitForm(root)

    expect(textOf(root)).toContain('Cette fiche a été fusionnée dans une autre')
    expect(registerCalls(api)).toHaveLength(0)
    unmount()
  })
})

describe('patient pré-choisi depuis sa fiche', () => {
  it('lit la fiche demandée par l’adresse, la montre et l’envoie avec le motif choisi', async () => {
    const api = server()
    const { root, unmount } = await openApp(`${FORM}?patient=${ID_A}`)

    expect(callsTo(api, 'GET', /^\/api\/v1\/patients\/[^/]+$/).map((call) => call.path)).toEqual([
      `/api/v1/patients/${ID_A}`,
    ])
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('NGONO FICTIVE Marie')
    expect(textOf(root)).toContain('Dossier EE-2026-0001')

    await chooseReason(root, 'PICKUP')
    await submitForm(root)

    expect(registerCalls(api)).toHaveLength(1)
    expect(registerCalls(api)[0]?.body).toMatchObject({ patient_id: ID_A, reason: 'PICKUP' })
    unmount()
  })

  it('un identifiant qui n’est pas un ULID est dit, sans appel au serveur, et la recherche reste ouverte', async () => {
    const api = server()
    const { root, unmount } = await openApp(`${FORM}?patient=pas-un-identifiant`)

    expect(textOf(root)).toContain('Le patient indiqué dans l’adresse n’est pas valide')
    expect(callsTo(api, 'GET', /^\/api\/v1\/patients\/[^/]+$/)).toHaveLength(0)
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('')
    expect((field(root, 'Patient') as HTMLInputElement).disabled).toBe(false)
    unmount()
  })

  it('fiche inconnue (404) : dite, et la recherche reste utilisable', async () => {
    server({
      handlers: { get: () => apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.') },
    })
    const { root, unmount } = await openApp(`${FORM}?patient=${ID_B}`)

    expect(textOf(root)).toContain('Fiche introuvable')
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('')
    await choosePatient(root)
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('NGONO FICTIVE Marie')
    unmount()
  })

  it('serveur muet : fiche indisponible, « Réessayer » la relit et la choisit', async () => {
    let up = false
    const api = server({
      handlers: { get: () => (up ? json(200, { data: FOUND }) : 'network') },
    })
    const { root, unmount } = await openApp(`${FORM}?patient=${ID_A}`)

    expect(textOf(root)).toContain('Fiche indisponible')
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('')

    up = true
    await click(buttonByName(root, 'Réessayer'))

    expect(callsTo(api, 'GET', /^\/api\/v1\/patients\/[^/]+$/)).toHaveLength(2)
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('NGONO FICTIVE Marie')
    unmount()
  })

  it('un patient déjà choisi à la main n’est pas remplacé par la fiche qui arrive ensuite', async () => {
    let release: (response: Response) => void = () => undefined
    server({
      handlers: {
        get: () =>
          new Promise<Response>((resolve) => {
            release = resolve
          }),
        search: () =>
          json(200, pageOf([summaryOf(patient({ id: ID_B, last_name: 'CHOISI FICTIF' }))])),
      },
    })
    const { root, unmount } = await openApp(`${FORM}?patient=${ID_A}`)
    await choosePatient(root, 'choisi')
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('CHOISI FICTIF Élodie Marie')

    release(json(200, { data: FOUND }))
    await flushPromises()

    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('CHOISI FICTIF Élodie Marie')
    unmount()
  })

  it('une fiche archivée pré-choisie est dite et ne peut pas recevoir d’arrivée', async () => {
    const api = server({
      handlers: { get: () => json(200, { data: patient({ id: ID_A, archived: true }) }) },
    })
    const { root, unmount } = await openApp(`${FORM}?patient=${ID_A}`)
    await chooseReason(root, 'EXAM')

    await submitForm(root)

    expect(textOf(root)).toContain('Cette fiche est archivée')
    expect(registerCalls(api)).toHaveLength(0)
    unmount()
  })
})

describe('règles avant tout envoi', () => {
  it('une saisie vide est refusée localement : erreurs par champ, résumé focalisé, aucun appel', async () => {
    const api = server()
    const { root, unmount } = await openApp(FORM)

    await submitForm(root)

    const summary = root.querySelector('[role="alert"]') as HTMLElement
    expect(summary.textContent).toContain('Certaines informations sont à corriger')
    expect(summary.textContent).toContain('Choisissez le patient dans la liste des résultats.')
    expect(summary.textContent).toContain('Choisissez le motif de la visite.')
    expect(document.activeElement).toBe(summary)
    expect(field(root, 'Patient').getAttribute('aria-invalid')).toBe('true')
    expect(field(root, 'Motif de la visite').getAttribute('aria-invalid')).toBe('true')
    expect(textOf(root.querySelector('#arrivee-motif-erreur') as Element)).toContain(
      'Choisissez le motif de la visite.',
    )
    expect(registerCalls(api)).toHaveLength(0)

    // Chaque erreur du résumé mène à son champ.
    ;(summary.querySelector('a') as HTMLAnchorElement).click()
    expect(document.activeElement?.id).toBe('arrivee-patient')

    // La correction efface l'erreur du champ.
    await chooseReason(root, 'EXAM')
    expect(field(root, 'Motif de la visite').getAttribute('aria-invalid')).toBeNull()
    unmount()
  })

  it('une note trop longue est refusée avec un message, sans être tronquée', async () => {
    const api = server()
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    const note = field(root, 'Note administrative') as HTMLTextAreaElement
    expect(note.getAttribute('maxlength')).toBe('4000')
    await type(root, 'Note administrative', 'a'.repeat(4001))

    await submitForm(root)

    expect(textOf(root)).toContain('La note ne peut pas dépasser 4000 caractères.')
    expect(note.value).toHaveLength(4001)
    expect(registerCalls(api)).toHaveLength(0)
    unmount()
  })
})

describe('enregistrement en ligne', () => {
  it('enregistre l’arrivée : charge conforme, clé d’idempotence, heure de l’envoi, puis la file du jour avec le message', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const api = server()
    const { root, router, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    await type(root, 'Note administrative', '  Accompagnée de sa mère  ')

    // L'heure d'arrivée est celle de l'ENVOI, pas celle de l'ouverture du formulaire.
    vi.setSystemTime(new Date('2026-10-15T09:07:30.250Z'))
    await submitForm(root)

    expect(registerCalls(api)).toHaveLength(1)
    const [call] = registerCalls(api)
    expect(call?.headers['Idempotency-Key']).toMatch(/^[0-9A-Z]{26}$/)
    expect(call?.body).toEqual({
      expected_version: null,
      visit_id: call?.body?.['visit_id'],
      patient_id: ID_A,
      reason: 'EXAM',
      occurred_at: '2026-10-15T09:07:30.250Z',
      appointment_id: null,
      administrative_note: 'Accompagnée de sa mère',
    })
    expect(isUlid(String(call?.body?.['visit_id']))).toBe(true)

    // File du jour : message en mémoire, file relue APRÈS l'enregistrement, aucune garde de sortie.
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    expect(router.currentRoute.value.fullPath).toBe('/aujourdhui')
    expect(confirm).not.toHaveBeenCalled()
    expect(textOf(root)).toContain('Arrivée enregistrée : NGONO FICTIVE Marie')
    const postIndex = api.requests.findIndex((request) => request.method === 'POST')
    const lastRead = api.requests.findLastIndex(
      (request) => request.method === 'GET' && request.path === '/api/v1/visits',
    )
    expect(lastRead).toBeGreaterThan(postIndex)
    unmount()
  })

  it('une note vide part en null, jamais en chaîne vide ; un achat direct est enregistré tel quel', async () => {
    const api = server()
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'DIRECT_PURCHASE')
    await type(root, 'Note administrative', '   ')

    await submitForm(root)

    expect(registerCalls(api)[0]?.body).toMatchObject({
      reason: 'DIRECT_PURCHASE',
      administrative_note: null,
      appointment_id: null,
    })
    unmount()
  })

  it('chaque motif part avec sa valeur du contrat', async () => {
    for (const reason of ['EXAM', 'DIRECT_PURCHASE', 'PICKUP', 'FOLLOW_UP']) {
      const api = server()
      const { root, unmount } = await openApp(FORM)
      await choosePatient(root)
      await chooseReason(root, reason)
      await submitForm(root)
      expect(registerCalls(api)[0]?.body?.['reason'], reason).toBe(reason)
      unmount()
      resetEverything()
    }
  })

  it('un double clic n’envoie qu’une commande', async () => {
    const api = server()
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    const form = root.querySelector('main form') as HTMLFormElement

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(registerCalls(api)).toHaveLength(1)
    unmount()
  })

  it('« Synchronisé » n’est jamais dit avant la réponse du serveur ; la saisie est figée pendant l’envoi', async () => {
    let release: (response: Response) => void = () => undefined
    const api = server({
      handlers: {
        register: () =>
          new Promise<Response>((resolve) => {
            release = resolve
          }),
      },
    })
    const { root, router, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')

    await submitForm(root)

    expect(registerCalls(api)).toHaveLength(1)
    expect(textOf(root)).toContain('Enregistrement en cours…')
    expect(textOf(root)).not.toContain('Synchronisé avec le serveur')
    expect(textOf(root)).not.toContain('Arrivée enregistrée')
    expect(router.currentRoute.value.name).toBe('arrivee')
    expect((field(root, 'Patient') as HTMLInputElement).disabled).toBe(true)
    expect((field(root, 'Motif de la visite') as HTMLSelectElement).disabled).toBe(true)
    expect(buttonByName(root, 'Enregistrement en cours…')?.disabled).toBe(true)

    const body = registerCalls(api)[0]?.body ?? {}
    release(
      json(201, {
        data: visit({ id: String(body['visit_id']), occurred_at: String(body['occurred_at']) }),
      }),
    )
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('une commande enregistrée par le journal du poste est dite « sur ce poste », jamais synchronisée', async () => {
    const api = server()
    const dispatcher: MutationDispatcher = {
      dispatch: async () => ({ kind: 'saved-local', at: '2026-10-15T09:42:00.000Z' }),
    }
    const { root, router, unmount } = await openApp(FORM, { dispatcher })
    await choosePatient(root)
    await chooseReason(root, 'EXAM')

    await submitForm(root)

    expect(textOf(root)).toContain('Enregistré sur ce poste à 10h42')
    expect(textOf(root)).not.toContain('Synchronisé')
    expect(registerCalls(api)).toHaveLength(0)
    expect(router.currentRoute.value.name).toBe('arrivee')
    unmount()
  })
})

describe('refus du serveur (422) : la saisie reste, chaque erreur est sous son champ', () => {
  it('patient_id et reason refusés : messages sous les champs et dans le résumé ; la correction repart avec une nouvelle clé', async () => {
    let attempt = 0
    const api = server({
      handlers: {
        register: (body) => {
          attempt += 1
          return attempt === 1
            ? apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
                patient_id: ['Patient introuvable dans ce cabinet.'],
                reason: ['Ce motif est refusé par le serveur.'],
              })
            : json(201, { data: visit({ id: String(body['visit_id']) }) })
        },
      },
    })
    const { root, router, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))

    await submitForm(root)

    const summary = root.querySelector('[role="alert"]') as HTMLElement
    expect(summary.textContent).toContain('Patient introuvable dans ce cabinet.')
    expect(summary.textContent).toContain('Ce motif est refusé par le serveur.')
    expect(textOf(root.querySelector('#arrivee-patient-erreur') as Element)).toContain(
      'Patient introuvable dans ce cabinet.',
    )
    expect(textOf(root.querySelector('#arrivee-motif-erreur') as Element)).toContain(
      'Ce motif est refusé par le serveur.',
    )
    expect((field(root, 'Patient') as HTMLInputElement).value).toBe('NGONO FICTIVE Marie')
    expect((field(root, 'Motif de la visite') as HTMLSelectElement).value).toBe('EXAM')
    expect(document.activeElement).toBe(summary)
    expect(router.currentRoute.value.name).toBe('arrivee')

    // Correction : autre motif, donc nouvelle intention (nouvelle clé), même identifiant de visite.
    vi.setSystemTime(new Date('2026-10-15T09:02:00.000Z'))
    await chooseReason(root, 'FOLLOW_UP')
    await submitForm(root)

    expect(registerCalls(api)).toHaveLength(2)
    const [first, second] = registerCalls(api)
    expect(second?.body?.['visit_id']).toBe(first?.body?.['visit_id'])
    expect(second?.headers['Idempotency-Key']).not.toBe(first?.headers['Idempotency-Key'])
    expect(second?.body?.['reason']).toBe('FOLLOW_UP')
    // Un refus n'a rien appliqué : la nouvelle tentative prend l'heure de son envoi.
    expect(first?.body?.['occurred_at']).toBe('2026-10-15T09:00:00.000Z')
    expect(second?.body?.['occurred_at']).toBe('2026-10-15T09:02:00.000Z')
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('note refusée : message sous la note', async () => {
    server({
      handlers: {
        register: () =>
          apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
            administrative_note: ['Cette note est refusée par le serveur.'],
          }),
      },
    })
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    await type(root, 'Note administrative', 'Note fictive')

    await submitForm(root)

    expect(textOf(root.querySelector('#arrivee-note-erreur') as Element)).toContain(
      'Cette note est refusée par le serveur.',
    )
    expect((field(root, 'Note administrative') as HTMLTextAreaElement).value).toBe('Note fictive')
    unmount()
  })

  it('conflit d’identifiant de visite : l’arrivée a peut-être déjà été enregistrée, dit avec le lien vers la file', async () => {
    const api = server({
      handlers: {
        register: () =>
          apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
            visit_id: ['Cet identifiant de visite est déjà utilisé.'],
          }),
      },
    })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { root, router, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')

    await submitForm(root)

    expect(textOf(root)).toContain('Cette arrivée est peut-être déjà enregistrée')
    expect(textOf(root)).toContain('Vérifiez la file du jour avant de recommencer')
    expect(textOf(root)).not.toContain('Arrivée enregistrée :')
    expect(linkTo(root, '/aujourdhui')?.textContent).toBeTruthy()
    expect(registerCalls(api)).toHaveLength(1)
    expect(textOf(root)).not.toContain('Synchronisé avec le serveur')

    // Aller voir la file ne demande aucune confirmation : rien de plus à perdre.
    const link = [...root.querySelectorAll('a')].find((a) =>
      a.textContent?.includes('Ouvrir la file du jour'),
    )
    await click(link ?? null)
    expect(confirm).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('heure d’arrivée refusée (horloge de l’appareil en avance) : le message du serveur et le conseil', async () => {
    server({
      handlers: {
        register: () =>
          apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
            occurred_at: ['L’heure d’arrivée ne peut pas être dans le futur.'],
          }),
      },
    })
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')

    await submitForm(root)

    const summary = (root.querySelector('[role="alert"]') as HTMLElement).textContent
    expect(summary).toContain('L’heure d’arrivée ne peut pas être dans le futur.')
    expect(summary).toContain('Vérifiez la date et l’heure de cet appareil.')
    unmount()
  })

  it('un refus sans champ (403) est dit dans le résumé, la saisie est conservée', async () => {
    server({
      handlers: { register: () => apiError(403, 'ACTION_FORBIDDEN', 'Action non autorisée.') },
    })
    const { root, router, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')

    await submitForm(root)

    expect((root.querySelector('[role="alert"]') as HTMLElement).textContent).toContain(
      'Action non autorisée.',
    )
    expect((field(root, 'Motif de la visite') as HTMLSelectElement).value).toBe('EXAM')
    expect(router.currentRoute.value.name).toBe('arrivee')
    unmount()
  })
})

describe('échecs d’enregistrement : la saisie reste, la reprise ne crée jamais deux arrivées', () => {
  it.each([
    ['réseau coupé', 'network' as const],
    ['erreur 500', undefined],
  ])(
    '%s : « Enregistrement non confirmé », puis « Réessayer l’enregistrement » reprend la même clé, la même charge et la même heure',
    async (_name, reply) => {
      let attempt = 0
      const api = server({
        handlers: {
          register: (body) => {
            attempt += 1
            if (attempt === 1) return reply ?? apiError(500, 'INTERNAL_ERROR', 'Erreur interne.')
            return json(201, { data: visit({ id: String(body['visit_id']) }) })
          },
        },
      })
      const { root, router, unmount } = await openApp(FORM)
      await choosePatient(root)
      await chooseReason(root, 'EXAM')
      vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))

      await submitForm(root)

      expect(textOf(root)).toContain('Enregistrement non confirmé')
      expect(textOf(root)).toContain('la commande a pu être appliquée')
      expect(textOf(root)).not.toContain('Synchronisé avec le serveur')
      expect((field(root, 'Motif de la visite') as HTMLSelectElement).value).toBe('EXAM')
      expect((field(root, 'Patient') as HTMLInputElement).disabled).toBe(false)
      expect(router.currentRoute.value.name).toBe('arrivee')

      // Du temps passe avant la reprise : l'arrivée garde l'heure de la première tentative.
      vi.setSystemTime(new Date('2026-10-15T09:05:00.000Z'))
      await click(buttonByName(root, 'Réessayer l’enregistrement'))

      expect(registerCalls(api)).toHaveLength(2)
      const [first, second] = registerCalls(api)
      expect(second?.headers['Idempotency-Key']).toBe(first?.headers['Idempotency-Key'])
      expect(second?.body).toEqual(first?.body)
      expect(second?.body?.['occurred_at']).toBe('2026-10-15T09:00:00.000Z')
      expect(router.currentRoute.value.name).toBe('aujourdhui')
      unmount()
    },
  )

  it('renvoyer la même saisie par le bouton principal après un échec réseau vaut une reprise, sans doublon', async () => {
    let attempt = 0
    const api = server({
      handlers: {
        register: (body) => {
          attempt += 1
          return attempt === 1
            ? 'network'
            : json(201, { data: visit({ id: String(body['visit_id']) }) })
        },
      },
    })
    const { root, router, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    await submitForm(root)
    vi.setSystemTime(new Date('2026-10-15T09:10:00.000Z'))

    await submitForm(root)

    const [first, second] = registerCalls(api)
    expect(registerCalls(api)).toHaveLength(2)
    expect(second?.headers['Idempotency-Key']).toBe(first?.headers['Idempotency-Key'])
    expect(second?.body).toEqual(first?.body)
    // Même heure d'arrivée : celle de la première tentative, pas celle de la reprise.
    expect(second?.body?.['occurred_at']).not.toBe('2026-10-15T09:10:00.000Z')
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('une saisie modifiée après un échec réseau est une nouvelle intention : nouvelle clé, même visite', async () => {
    let attempt = 0
    const api = server({
      handlers: {
        register: () => {
          attempt += 1
          return attempt === 1
            ? 'network'
            : apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
                visit_id: ['Cet identifiant de visite est déjà utilisé.'],
              })
        },
      },
    })
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    await submitForm(root)

    await type(root, 'Note administrative', 'Précision ajoutée')
    await submitForm(root)

    const [first, second] = registerCalls(api)
    expect(second?.headers['Idempotency-Key']).not.toBe(first?.headers['Idempotency-Key'])
    expect(second?.body?.['visit_id']).toBe(first?.body?.['visit_id'])
    // Le serveur dit l'identifiant pris : la première tentative avait peut-être été appliquée.
    expect(textOf(root)).toContain('Cette arrivée est peut-être déjà enregistrée')
    unmount()
  })

  it('un double clic sur « Réessayer l’enregistrement » ne renvoie qu’une commande', async () => {
    let attempt = 0
    const api = server({
      handlers: {
        register: (body) => {
          attempt += 1
          return attempt === 1
            ? 'network'
            : json(201, { data: visit({ id: String(body['visit_id']) }) })
        },
      },
    })
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    await submitForm(root)

    const retry = buttonByName(root, 'Réessayer l’enregistrement')
    retry?.click()
    retry?.click()
    await flushPromises()

    expect(registerCalls(api)).toHaveLength(2)
    unmount()
  })

  it('session expirée : saisie conservée, « Se reconnecter » proposé, reprise avec la même clé', async () => {
    let attempt = 0
    const api = server({
      handlers: {
        register: (body) => {
          attempt += 1
          return attempt === 1
            ? apiError(401, 'AUTH_REQUIRED', 'Connexion requise.')
            : json(201, { data: visit({ id: String(body['visit_id']) }) })
        },
      },
    })
    const { root, router, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    const reconnected = api.me
    api.me = 'anonymous'

    await submitForm(root)

    expect(textOf(root)).toContain('Session expirée')
    expect(textOf(root)).toContain('Session expirée : vos saisies sont conservées')
    expect(buttonByName(root, 'Se reconnecter')).not.toBeNull()
    expect((field(root, 'Motif de la visite') as HTMLSelectElement).value).toBe('EXAM')
    expect(router.currentRoute.value.name).toBe('arrivee')

    // Reconnexion faite (ici dans un autre onglet) : la reprise garde la clé.
    api.me = reconnected
    await click(buttonByName(root, 'Réessayer l’enregistrement'))

    expect(registerCalls(api)).toHaveLength(2)
    expect(registerCalls(api)[1]?.headers['Idempotency-Key']).toBe(
      registerCalls(api)[0]?.headers['Idempotency-Key'],
    )
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('un autre compte connecté entre-temps : rien n’est envoyé en son nom', async () => {
    const api = server({
      handlers: { register: () => apiError(401, 'AUTH_REQUIRED', 'Connexion requise.') },
    })
    const { root, unmount } = await openApp(FORM)
    await choosePatient(root)
    await chooseReason(root, 'EXAM')
    api.me = 'anonymous'
    await submitForm(root)

    api.me = meResponse(FRONT_DESK, 'Autre Personne', ['today', 'patients'])
    api.me.data.id = '01J9ZXK4M2Q8R7S6T5V4W39999'
    await click(buttonByName(root, 'Réessayer l’enregistrement'))

    expect(registerCalls(api)).toHaveLength(1)
    expect(textOf(root)).toContain('Compte différent')
    expect((field(root, 'Motif de la visite') as HTMLSelectElement).value).toBe('EXAM')
    unmount()
  })
})

describe('autre visite du même patient le même jour : avertissement, jamais un blocage', () => {
  const earlier = visit({
    id: VISIT_A,
    patient_id: ID_A,
    reason: 'EXAM',
    occurred_at: '2026-10-15T08:15:00.000Z',
    status: 'ARRIVED',
  })

  it('signale la visite déjà enregistrée, et l’enregistrement reste possible', async () => {
    const api = server({ handlers: { list: () => json(200, visitPage([earlier])) } })
    const { root, router, unmount } = await openApp(FORM)
    expect(listCalls(api)[0]?.query.get('date')).toBe('2026-10-15')

    expect(textOf(root)).not.toContain('a déjà une arrivée')
    await choosePatient(root)

    const text = textOf(root)
    expect(text).toContain(
      'Ce patient a déjà une arrivée enregistrée aujourd’hui : Examen de vue à 09h15 (Arrivé).',
    )
    expect(text).toContain('vérifiez qu’il ne s’agit pas d’un doublon')
    // Avertissement seulement : pas de boîte de confirmation, le bouton reste actif.
    expect(root.querySelector('dialog[open]')).toBeNull()
    expect((buttonByName(root, 'Enregistrer l’arrivée') as HTMLButtonElement).disabled).toBe(false)

    await chooseReason(root, 'DIRECT_PURCHASE')
    await submitForm(root)

    expect(registerCalls(api)).toHaveLength(1)
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('compte plusieurs visites et ignore celles d’un autre patient ou annulées', async () => {
    server({
      handlers: {
        list: () =>
          json(
            200,
            visitPage([
              earlier,
              visit({
                id: '01J9ZXK4M2Q8R7S6T5V4W30024',
                patient_id: ID_A,
                reason: 'PICKUP',
                occurred_at: '2026-10-15T09:20:00.000Z',
                status: 'COMPLETED',
              }),
              visit({ id: '01J9ZXK4M2Q8R7S6T5V4W30025', patient_id: ID_A, status: 'CANCELLED' }),
              visit({ id: '01J9ZXK4M2Q8R7S6T5V4W30026', patient_id: ID_B }),
            ]),
          ),
      },
    })
    const { root, unmount } = await openApp(FORM)

    await choosePatient(root)

    const text = textOf(root)
    expect(text).toContain('Ce patient a déjà 2 arrivées enregistrées aujourd’hui')
    expect(text).toContain('Examen de vue à 09h15 (Arrivé), Retrait de commande à 10h20 (Terminé)')
    unmount()
  })

  it('aucun avertissement pour un patient sans visite aujourd’hui (la file a bien répondu)', async () => {
    server({
      handlers: { list: () => json(200, visitPage([visit({ patient_id: ID_B, id: VISIT_A })])) },
    })
    const { root, unmount } = await openApp(FORM)

    await choosePatient(root)

    expect(textOf(root)).not.toContain('a déjà')
    expect(textOf(root)).not.toContain('n’a pas pu être lue')
    unmount()
  })

  it('cherche aussi dans les pages suivantes de la file du jour', async () => {
    const api = server({
      handlers: {
        list: (_day, page) =>
          page === 1
            ? json(
                200,
                visitPage([visit({ id: VISIT_A, patient_id: ID_B })], {
                  current_page: 1,
                  last_page: 2,
                  total: 51,
                }),
              )
            : json(200, visitPage([earlier], { current_page: 2, last_page: 2, total: 51 })),
      },
    })
    const { root, unmount } = await openApp(FORM)

    await choosePatient(root)

    expect(listCalls(api).map((call) => call.query.get('page'))).toEqual(['1', '2'])
    expect(textOf(root)).toContain('Ce patient a déjà une arrivée enregistrée aujourd’hui')
    unmount()
  })

  it('file du jour illisible : dit que l’éventuelle autre arrivée ne peut pas être signalée, sans affirmer qu’il n’y en a pas', async () => {
    server({ handlers: { list: () => 'network' } })
    const { root, unmount } = await openApp(FORM)
    expect(textOf(root)).not.toContain('n’a pas pu être lue en entier')

    await choosePatient(root)

    expect(textOf(root)).toContain(
      'La file du jour n’a pas pu être lue en entier : une autre arrivée du même patient ne peut pas être signalée.',
    )
    expect(textOf(root)).not.toContain('Aucune autre')
    // L'enregistrement n'est pas bloqué pour autant.
    expect((buttonByName(root, 'Enregistrer l’arrivée') as HTMLButtonElement).disabled).toBe(false)
    unmount()
  })
})

describe('droits, module et garde de saisie', () => {
  it('sans visit.register (caisse) : accès refusé, aucun formulaire ni lecture', async () => {
    const api = server({ permissions: CASH_DESK })
    const { root, router, unmount } = await openApp(FORM)

    expect(router.currentRoute.value.name).toBe('acces-refuse')
    expect(root.querySelector('form')).toBeNull()
    expect(listCalls(api)).toHaveLength(0)
    expect(registerCalls(api)).toHaveLength(0)
    unmount()
  })

  it('module « today » fermé : « Page introuvable »', async () => {
    const api = server({ modules: ['patients'] })
    const { root, unmount } = await openApp(FORM)

    expect(root.querySelector('h1')?.textContent).toBe('Page introuvable')
    expect(listCalls(api)).toHaveLength(0)
    unmount()
  })

  it('quitter avec une saisie non enregistrée demande confirmation ; un formulaire vierge se quitte librement', async () => {
    server()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { root, router, unmount } = await openApp(FORM)

    await router.push('/aujourdhui')
    await flushPromises()
    expect(confirm).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('aujourdhui')

    await router.push(FORM)
    await flushPromises()
    await chooseReason(root, 'EXAM')
    await router.push('/aujourdhui')
    await flushPromises()
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('arrivee')
    expect((field(root, 'Motif de la visite') as HTMLSelectElement).value).toBe('EXAM')

    pastDialog()
    confirm.mockReturnValue(true)
    await router.push('/aujourdhui')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })

  it('arriver depuis une fiche (patient pré-choisi) puis repartir sans rien saisir ne demande rien', async () => {
    server()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { router, unmount } = await openApp(`${FORM}?patient=${ID_A}`)

    await router.push('/aujourdhui')
    await flushPromises()

    expect(confirm).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('aujourdhui')
    unmount()
  })
})
