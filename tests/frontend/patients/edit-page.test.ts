import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  apiError,
  buttonByName,
  callsTo,
  click,
  field,
  ID_A,
  ID_B,
  json,
  openApp,
  patient,
  READER,
  RECEPTION,
  resetEverything,
  stubPatientsApi,
  submitForm,
  textOf,
  type,
  typeDate,
} from './support'

// Modification d'une fiche (P01 ; AT04, AT42) : champs modifiés seulement, version attendue, conflit
// de version sans perte de saisie ni écrasement silencieux, erreurs par champ, droits, garde de
// saisie. Données FICTIVES.
const ONE = /^\/api\/v1\/patients\/[^/]+$/

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

function patchesOf(api: ReturnType<typeof stubPatientsApi>) {
  return callsTo(api, 'PATCH', ONE)
}

function server(options: Parameters<typeof stubPatientsApi>[0] = {}) {
  return stubPatientsApi({
    permissions: RECEPTION,
    ...options,
    handlers: {
      get: () => json(200, { data: patient() }),
      ...options.handlers,
    },
  })
}

const EDIT = `/patients/${ID_A}/modifier`

describe('formulaire de modification', () => {
  it('préremplit l’identité lue sur le serveur et montre l’en-tête de la fiche', async () => {
    server()
    const { root, unmount } = await openApp(EDIT)

    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Modifier le patient')
    expect(document.title).toBe('Modifier le patient – Eagle’s Eye Optics Medical')
    expect(textOf(root)).toContain('PATIENT FICTIF Élodie Marie')
    expect(textOf(root)).toContain('Dossier EE-2026-0001')
    expect(field(root, 'Nom').value).toBe('PATIENT FICTIF')
    expect(field(root, 'Prénom(s)').value).toBe('Élodie Marie')
    expect((field(root, 'Sexe') as HTMLSelectElement).value).toBe('F')
    expect(field(root, 'Date de naissance').value).toBe('17/05/1990')
    expect(field(root, 'Téléphone principal').value).toBe('+237600000001')
    expect(field(root, 'Adresse ou quartier').value).toBe('Quartier fictif\nDouala')
    expect(field(root, 'Profession').value).toBe('Enseignante')
    expect(buttonByName(root, 'Enregistrer les modifications')).not.toBeNull()
    expect(textOf(root)).not.toContain('Enregistré sur ce poste')
    unmount()
  })

  it('n’envoie que les champs modifiés, avec la version lue et une clé d’idempotence', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const api = server()
    const { root, router, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Infirmière')

    await submitForm(root)

    expect(patchesOf(api)).toHaveLength(1)
    const [call] = patchesOf(api)
    expect(call?.path).toBe(`/api/v1/patients/${ID_A}`)
    expect(call?.body).toEqual({
      expected_version: 3,
      patient_id: ID_A,
      changes: { profession: 'Infirmière' },
    })
    expect(call?.headers['Idempotency-Key']).toMatch(/^[0-9A-Z]{26}$/)
    expect(router.currentRoute.value.path).toBe(`/patients/${ID_A}`)
    expect(confirm).not.toHaveBeenCalled()
    expect(textOf(root)).toContain('Modifications enregistrées.')
    unmount()
  })

  it('vider un champ l’envoie à null, jamais une chaîne vide', async () => {
    const api = server()
    const { root, unmount } = await openApp(EDIT)
    await type(root, 'Profession', '   ')
    await type(root, 'Adresse ou quartier', '')

    await submitForm(root)

    expect(patchesOf(api)[0]?.body?.['changes']).toEqual({ profession: null, address: null })
    unmount()
  })

  it('rien n’a changé : aucun appel, le dit', async () => {
    const api = server()
    const { root, router, unmount } = await openApp(EDIT)

    await submitForm(root)

    expect(textOf(root)).toContain('Aucune modification à enregistrer.')
    expect(patchesOf(api)).toHaveLength(0)
    expect(router.currentRoute.value.name).toBe('patient-modifier')
    unmount()
  })

  it('un téléphone ressaisi avec d’autres espaces n’est pas une modification', async () => {
    const api = server({
      handlers: { get: () => json(200, { data: patient({ phone_primary: '+237 600 00 00 01' }) }) },
    })
    const { root, unmount } = await openApp(EDIT)
    await type(root, 'Téléphone principal', '+237600000001')

    await submitForm(root)

    expect(textOf(root)).toContain('Aucune modification à enregistrer.')
    expect(patchesOf(api)).toHaveLength(0)
    unmount()
  })

  it('applique les mêmes règles qu’à la création : naissance future refusée, raison exigée', async () => {
    const api = server()
    const { root, unmount } = await openApp(EDIT)

    await typeDate(root, 'Date de naissance', '16/10/2026')
    await submitForm(root)
    expect(textOf(root)).toContain('La date de naissance ne peut pas être dans le futur.')
    expect(patchesOf(api)).toHaveLength(0)

    await typeDate(root, 'Date de naissance', '')
    await submitForm(root)
    expect(textOf(root)).toContain('Date de naissance absente : indiquez la raison.')
    expect(patchesOf(api)).toHaveLength(0)

    await type(root, 'Raison de l’absence de date de naissance', 'Pièce perdue')
    await submitForm(root)
    expect(patchesOf(api)[0]?.body?.['changes']).toEqual({
      birth_date: null,
      birth_date_missing_reason: 'Pièce perdue',
    })
    unmount()
  })

  it('un refus 422 est dit sous le champ concerné, la saisie est conservée', async () => {
    server({
      handlers: {
        update: () =>
          apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
            'changes.phone_primary': ['Ce numéro est refusé par le serveur.'],
          }),
      },
    })
    const { root, unmount } = await openApp(EDIT)
    await type(root, 'Téléphone principal', '+237 677 00 00 00')

    await submitForm(root)

    const summary = root.querySelector('[role="alert"]') as HTMLElement
    expect(summary.textContent).toContain('Ce numéro est refusé par le serveur.')
    expect(field(root, 'Téléphone principal').getAttribute('aria-invalid')).toBe('true')
    expect(field(root, 'Téléphone principal').value).toBe('+237 677 00 00 00')
    expect(document.activeElement).toBe(summary)
    unmount()
  })

  it('un double clic n’envoie qu’une commande', async () => {
    const api = server()
    const { root, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Infirmière')
    const form = root.querySelector('main form') as HTMLFormElement

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(patchesOf(api)).toHaveLength(1)
    unmount()
  })
})

describe('conflit de version : jamais de dernier écrit gagnant', () => {
  const CHANGED_BY_SOMEONE = patient({
    version: 4,
    profession: 'Infirmière',
    address: 'Adresse changée par un autre poste',
    updated_at: '2026-10-15T08:00:00.000Z',
  })

  function conflictServer() {
    let reads = 0
    let updates = 0
    const api = server({
      handlers: {
        get: () => {
          reads += 1
          return json(200, { data: reads === 1 ? patient() : CHANGED_BY_SOMEONE })
        },
        update: (_id, body) => {
          updates += 1
          return updates === 1
            ? apiError(409, 'VERSION_CONFLICT', 'La fiche a été modifiée.')
            : json(200, { data: { patient_id: body['patient_id'], version: 5 } })
        },
      },
    })
    return api
  }

  it('garde toute la saisie et propose de recharger la fiche, sans renvoyer seul', async () => {
    const api = conflictServer()
    const { root, router, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Enseignante principale')

    await submitForm(root)

    expect(textOf(root)).toContain('La fiche a changé entre-temps')
    expect(buttonByName(root, 'Recharger la fiche')).not.toBeNull()
    expect(field(root, 'Profession').value).toBe('Enseignante principale')
    expect(patchesOf(api)).toHaveLength(1)
    expect(callsTo(api, 'GET', ONE)).toHaveLength(1)
    expect(router.currentRoute.value.name).toBe('patient-modifier')
    // L'envoi n'est pas présenté comme enregistré.
    expect(textOf(root)).not.toContain('Synchronisé avec le serveur')
    unmount()
  })

  it('« Recharger la fiche » reporte la saisie sur la version actuelle et signale les conflits', async () => {
    const api = conflictServer()
    const { root, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Enseignante principale')
    await type(root, 'Téléphone secondaire', '+237 677 00 00 00')
    await submitForm(root)

    await click(buttonByName(root, 'Recharger la fiche'))

    expect(callsTo(api, 'GET', ONE)).toHaveLength(2)
    // Mes champs gardent ma saisie ; celui que personne d'autre n'a touché aussi.
    expect(field(root, 'Profession').value).toBe('Enseignante principale')
    expect(field(root, 'Téléphone secondaire').value).toBe('+237 677 00 00 00')
    // Le champ que je n'ai pas touché reprend la valeur du serveur : rien n'est écrasé.
    expect(field(root, 'Adresse ou quartier').value).toBe('Adresse changée par un autre poste')
    // Le champ modifié des deux côtés est signalé avec la valeur actuelle.
    expect(textOf(root)).toContain('Champs modifiés aussi par une autre personne')
    expect(textOf(root)).toContain('Profession : valeur actuelle « Infirmière »')
    expect(textOf(root)).toContain('version 4')
    expect(textOf(root)).not.toContain('Recharger la fiche')
    unmount()
  })

  it('l’envoi suivant porte la nouvelle version, une nouvelle clé et mes seuls champs', async () => {
    const api = conflictServer()
    const { root, router, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Enseignante principale')
    await type(root, 'Téléphone secondaire', '+237 677 00 00 00')
    await submitForm(root)
    await click(buttonByName(root, 'Recharger la fiche'))

    await submitForm(root)

    expect(patchesOf(api)).toHaveLength(2)
    const [first, second] = patchesOf(api)
    expect(second?.body).toEqual({
      expected_version: 4,
      patient_id: ID_A,
      // L'adresse de l'autre poste n'est pas renvoyée : elle n'est pas de ma main.
      changes: { profession: 'Enseignante principale', phone_secondary: '+237677000000' },
    })
    expect(second?.headers['Idempotency-Key']).not.toBe(first?.headers['Idempotency-Key'])
    expect(router.currentRoute.value.name).toBe('patient')
    unmount()
  })

  it('si la relecture échoue, la saisie reste, le conflit reste à traiter, « Réessayer » relit', async () => {
    let reads = 0
    const api = server({
      handlers: {
        get: () => {
          reads += 1
          if (reads === 1) return json(200, { data: patient() })
          return reads === 2 ? 'network' : json(200, { data: CHANGED_BY_SOMEONE })
        },
        update: () => apiError(409, 'VERSION_CONFLICT', 'La fiche a été modifiée.'),
      },
    })
    const { root, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Enseignante principale')
    await submitForm(root)

    await click(buttonByName(root, 'Recharger la fiche'))

    expect(textOf(root)).toContain('Fiche indisponible')
    expect(textOf(root)).toContain('La fiche a changé entre-temps')
    expect(field(root, 'Profession').value).toBe('Enseignante principale')

    await click(buttonByName(root, 'Réessayer'))
    expect(callsTo(api, 'GET', ONE)).toHaveLength(3)
    expect(textOf(root)).toContain('Profession : valeur actuelle « Infirmière »')
    expect(field(root, 'Profession').value).toBe('Enseignante principale')
    unmount()
  })

  it('sans conflit de champ, la fiche rechargée est dite rechargée', async () => {
    let reads = 0
    server({
      handlers: {
        get: () => {
          reads += 1
          return json(200, {
            data: reads === 1 ? patient() : patient({ version: 4, profession: 'Infirmière' }),
          })
        },
        update: () => apiError(409, 'VERSION_CONFLICT', 'La fiche a été modifiée.'),
      },
    })
    const { root, unmount } = await openApp(EDIT)
    await type(root, 'Téléphone secondaire', '+237 677 00 00 00')
    await submitForm(root)

    await click(buttonByName(root, 'Recharger la fiche'))

    expect(textOf(root)).toContain('Fiche rechargée')
    expect(textOf(root)).not.toContain('Champs modifiés aussi par une autre personne')
    expect(field(root, 'Profession').value).toBe('Infirmière')
    expect(field(root, 'Téléphone secondaire').value).toBe('+237 677 00 00 00')
    unmount()
  })
})

describe('échecs, droits et garde de saisie', () => {
  it('réseau coupé : « non confirmé », « Réessayer » reprend la même clé et la même charge', async () => {
    let attempt = 0
    const api = server({
      handlers: {
        update: (_id, body) => {
          attempt += 1
          return attempt === 1
            ? 'network'
            : json(200, { data: { patient_id: body['patient_id'], version: 4 } })
        },
      },
    })
    const { root, router, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Infirmière')

    await submitForm(root)

    expect(textOf(root)).toContain('Enregistrement non confirmé')
    expect(field(root, 'Profession').value).toBe('Infirmière')

    await click(buttonByName(root, 'Réessayer l’enregistrement'))

    const [first, second] = patchesOf(api)
    expect(patchesOf(api)).toHaveLength(2)
    expect(second?.headers['Idempotency-Key']).toBe(first?.headers['Idempotency-Key'])
    expect(second?.body).toEqual(first?.body)
    expect(router.currentRoute.value.name).toBe('patient')
    unmount()
  })

  it('session expirée : saisie conservée, « Se reconnecter » proposé', async () => {
    const api = server({
      handlers: { update: () => apiError(401, 'AUTH_REQUIRED', 'Connexion requise.') },
    })
    const { root, router, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Infirmière')
    api.me = 'anonymous'

    await submitForm(root)

    expect(textOf(root)).toContain('Session expirée')
    expect(buttonByName(root, 'Se reconnecter')).not.toBeNull()
    expect(field(root, 'Profession').value).toBe('Infirmière')
    expect(router.currentRoute.value.name).toBe('patient-modifier')
    unmount()
  })

  it('fiche inconnue (404) : aucun formulaire, ce n’est pas un vide', async () => {
    stubPatientsApi({ permissions: RECEPTION })
    const { root, unmount } = await openApp(EDIT)

    expect(textOf(root)).toContain('Fiche introuvable')
    expect(root.querySelector('form')).toBeNull()
    unmount()
  })

  it('serveur injoignable : aucun formulaire à partir d’une copie ancienne, « Réessayer » relit', async () => {
    let up = false
    const api = server({
      handlers: { get: () => (up ? json(200, { data: patient() }) : 'network') },
    })
    const { root, unmount } = await openApp(EDIT)

    expect(textOf(root)).toContain('Fiche indisponible')
    expect(root.querySelector('form')).toBeNull()

    up = true
    await click(buttonByName(root, 'Réessayer'))

    expect(callsTo(api, 'GET', ONE)).toHaveLength(2)
    expect(field(root, 'Nom').value).toBe('PATIENT FICTIF')
    unmount()
  })

  it('lecture seule : accès refusé, aucune modification possible', async () => {
    const api = server({ permissions: READER })
    const { root, router, unmount } = await openApp(EDIT)

    expect(router.currentRoute.value.name).toBe('acces-refuse')
    expect(root.querySelector('form')).toBeNull()
    expect(callsTo(api, 'GET', ONE)).toHaveLength(0)
    unmount()
  })

  it('quitter avec une modification non enregistrée demande confirmation', async () => {
    server()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { root, router, unmount } = await openApp(EDIT)
    await type(root, 'Profession', 'Infirmière')

    await router.push('/patients')
    await flushPromises()
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('patient-modifier')
    expect(field(root, 'Profession').value).toBe('Infirmière')

    confirm.mockReturnValue(true)
    await router.push('/patients')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('patients')
    unmount()
  })

  it('une fiche relue sans modification se quitte sans confirmation', async () => {
    server()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { router, unmount } = await openApp(EDIT)

    await router.push('/patients')
    await flushPromises()

    expect(confirm).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('patients')
    unmount()
  })

  it('une autre fiche ouverte au même endroit repart d’un formulaire propre', async () => {
    server({
      handlers: {
        get: (id) =>
          json(200, {
            data:
              id === ID_A
                ? patient()
                : patient({ id: ID_B, last_name: 'AUTRE FICTIF', first_names: null, version: 9 }),
          }),
      },
    })
    const { root, router, unmount } = await openApp(EDIT)
    expect(field(root, 'Nom').value).toBe('PATIENT FICTIF')

    await router.push(`/patients/${ID_B}/modifier`)
    await flushPromises()

    expect(field(root, 'Nom').value).toBe('AUTRE FICTIF')
    expect(field(root, 'Prénom(s)').value).toBe('')
    unmount()
  })
})
