import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  apiError,
  box,
  buttonByName,
  choice,
  click,
  consultation,
  consultationReads,
  errorWithDetails,
  field,
  group,
  json,
  openConsultation,
  patchCalls,
  resetEverything,
  stubConsultationApi,
  submitForm,
  textOf,
  toggle,
  type,
} from './support'

// Échecs d'enregistrement d'une étape (C01) : refus 422 par champ, conflit de version (AT19, AT42),
// refus d'état, coupure, session expirée, garde de saisie. La saisie est toujours conservée et rien
// n'est dit enregistré avant la réponse du serveur. Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-22T09:42:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetEverything()
})

const NOT_SAVED = /Enregistré sur le serveur|Enregistré sur ce poste|Synchronisé/

describe('refus 422 par champ', () => {
  it('« step.data.complaints.1.other_text » est dit sous la précision de « Autre », dans le résumé, la saisie conservée', async () => {
    const api = stubConsultationApi({
      handlers: {
        save: () =>
          errorWithDetails(
            422,
            'VALIDATION_FAILED',
            'Données invalides.',
            {},
            {
              'step.data.complaints.1.other_text': ['Cette précision est refusée par le serveur.'],
            },
          ),
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')
    await toggle(root, 'Autre')
    await type(root, 'Autre motif : précisez', 'Gêne')

    await submitForm(root)

    expect(patchCalls(api)).toHaveLength(1)
    const summary = root.querySelector('[role="alert"]') as HTMLElement
    expect(summary.textContent).toContain('Cette précision est refusée par le serveur.')
    expect(document.activeElement).toBe(summary)
    const input = field(root, 'Autre motif : précisez')
    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(input.value).toBe('Gêne')
    expect(input.getAttribute('aria-describedby')).toContain('-erreur')
    expect(box(root, 'Douleur').checked).toBe(true)
    expect(textOf(root)).not.toMatch(NOT_SAVED)
    // Le message n'est pas répété dans un encart d'échec.
    expect(textOf(root)).not.toContain('Enregistrement refusé')

    // Corriger la saisie efface l'erreur affichée ; l'envoi suivant a sa propre clé.
    await type(root, 'Autre motif : précisez', 'Gêne au soleil')
    expect(root.querySelector('[role="alert"]')).toBeNull()
    expect(input.getAttribute('aria-invalid')).toBeNull()
    await submitForm(root)
    expect(patchCalls(api)).toHaveLength(2)
    expect(patchCalls(api)[1]?.headers['Idempotency-Key']).not.toBe(
      patchCalls(api)[0]?.headers['Idempotency-Key'],
    )
    unmount()
  })

  it('un refus sur la durée des lunettes est dit sous le champ de durée', async () => {
    stubConsultationApi({
      handlers: {
        save: () =>
          errorWithDetails(
            422,
            'VALIDATION_FAILED',
            'Données invalides.',
            {},
            {
              'step.data.glasses_duration': ['La durée est refusée.'],
            },
          ),
      },
    })
    const { root, unmount } = await openConsultation()
    await choice(root, 'Porte actuellement des lunettes', 'Oui')
    await type(root, 'Depuis combien de temps', '2 ans')

    await submitForm(root)

    expect(field(root, 'Depuis combien de temps').getAttribute('aria-invalid')).toBe('true')
    expect(textOf(root)).toContain('La durée est refusée.')
    unmount()
  })

  it('une clé inconnue du serveur mène au titre de l’étape : le message n’est jamais perdu', async () => {
    stubConsultationApi({
      handlers: {
        save: () =>
          errorWithDetails(
            422,
            'VALIDATION_FAILED',
            'Données invalides.',
            {},
            {
              'step.data.champ_inconnu': ['Valeur incohérente.'],
            },
          ),
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await submitForm(root)

    const summary = root.querySelector('[role="alert"]') as HTMLElement
    expect(summary.textContent).toContain('Valeur incohérente.')
    ;(summary.querySelector('a') as HTMLAnchorElement).click()
    expect(document.activeElement?.id).toBe('consultation-etape')
    unmount()
  })

  it('un refus 422 sans champ est dit dans un encart d’échec avec le message du serveur', async () => {
    stubConsultationApi({
      handlers: {
        save: () =>
          apiError(422, 'VALIDATION_FAILED', 'La consultation est dans un état incohérent.'),
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await submitForm(root)

    expect(textOf(root)).toContain('Enregistrement refusé')
    expect(textOf(root)).toContain('La consultation est dans un état incohérent.')
    expect(box(root, 'Douleur').checked).toBe(true)
    expect(textOf(root)).not.toMatch(NOT_SAVED)
    unmount()
  })
})

describe('conflit de version : jamais de dernier écrit gagnant', () => {
  /** Un autre poste enregistre pendant la saisie : motifs et symptômes changent, version 2. */
  function conflictServer() {
    let saves = 0
    const api = stubConsultationApi({
      handlers: {
        save: (_id, step, body) => {
          saves += 1
          if (saves === 1) {
            api.server = consultation({
              status: 'IN_PROGRESS',
              version: 2,
              steps: {
                ANAMNESIS: {
                  symptoms: 'Texte de l’autre poste',
                  wears_glasses: null,
                  glasses_duration: null,
                  previous_correction_available: null,
                  complaints: [
                    { id: '01J9ZXK4M2Q8R7S6T5V4W30801', term_code: 'REDNESS', other_text: null },
                  ],
                },
                HISTORIES: {
                  histories: [
                    {
                      id: '01J9ZXK4M2Q8R7S6T5V4W30802',
                      history_group: 'FAMILY_HISTORY',
                      known_none: true,
                      comments: null,
                      terms: [],
                    },
                  ],
                },
              },
            })
            return errorWithDetails(409, 'VERSION_CONFLICT', 'La consultation a été modifiée.', {
              current_version: 2,
            })
          }
          const sent = body['step'] as { data: unknown }
          api.server = {
            ...api.server,
            version: api.server.version + 1,
            steps: { ...api.server.steps, [step]: sent.data } as typeof api.server.steps,
          }
          return json(200, { data: api.server })
        },
      },
    })
    return api
  }

  it('garde toute la saisie et propose de recharger, sans renvoyer seul ni dire « enregistré »', async () => {
    const api = conflictServer()
    const { root, unmount } = await openConsultation()
    await type(root, 'Plaintes et symptômes', 'Ma saisie')
    await toggle(root, 'Douleur')

    await submitForm(root)

    expect(textOf(root)).toContain('La consultation a changé entre-temps')
    expect(buttonByName(root, 'Recharger la consultation')).not.toBeNull()
    expect(field(root, 'Plaintes et symptômes').value).toBe('Ma saisie')
    expect(box(root, 'Douleur').checked).toBe(true)
    expect(patchCalls(api)).toHaveLength(1)
    expect(consultationReads(api)).toHaveLength(1)
    expect(textOf(root)).not.toMatch(NOT_SAVED)
    // Rien ne se renvoie tant que la consultation n'est pas rechargée.
    expect((buttonByName(root, 'Enregistrer') as HTMLButtonElement).disabled).toBe(true)
    expect((buttonByName(root, 'Suivant') as HTMLButtonElement).disabled).toBe(true)
    await submitForm(root)
    expect(patchCalls(api)).toHaveLength(1)
    unmount()
  })

  it('« Recharger la consultation » reporte la saisie sur la version actuelle et signale les valeurs modifiées des deux côtés', async () => {
    const api = conflictServer()
    const { root, unmount } = await openConsultation()
    await type(root, 'Plaintes et symptômes', 'Ma saisie')
    await toggle(root, 'Douleur')
    await submitForm(root)

    await click(buttonByName(root, 'Recharger la consultation'))

    expect(consultationReads(api)).toHaveLength(2)
    // Mes valeurs sont gardées ; celles de l'autre poste, que je n'ai pas touchées, sont reprises.
    expect(field(root, 'Plaintes et symptômes').value).toBe('Ma saisie')
    expect(box(root, 'Douleur').checked).toBe(true)
    expect(box(root, 'Rougeur').checked).toBe(true)
    // La valeur modifiée des deux côtés est signalée avec la valeur actuelle.
    const text = textOf(root)
    expect(text).toContain('Valeurs modifiées aussi par une autre personne')
    expect(text).toContain(
      'Motif et lunettes · Plaintes et symptômes : valeur actuelle « Texte de l’autre poste »',
    )
    expect(text).toContain('version 2')
    expect(text).not.toContain('Recharger la consultation')
    expect(text).not.toMatch(NOT_SAVED)
    unmount()
  })

  it('l’envoi suivant porte la nouvelle version, une nouvelle clé, mes termes ET ceux de l’autre poste (jamais écrasés)', async () => {
    const api = conflictServer()
    const { root, unmount } = await openConsultation()
    await type(root, 'Plaintes et symptômes', 'Ma saisie')
    await toggle(root, 'Douleur')
    await submitForm(root)
    await click(buttonByName(root, 'Recharger la consultation'))

    await submitForm(root)

    expect(patchCalls(api)).toHaveLength(2)
    const [first, second] = patchCalls(api)
    expect(second?.body?.['expected_version']).toBe(2)
    expect(second?.headers['Idempotency-Key']).not.toBe(first?.headers['Idempotency-Key'])
    const data = (second?.body?.['step'] as { data: Record<string, unknown> }).data
    expect(data['symptoms']).toBe('Ma saisie')
    expect(data['complaints']).toEqual([
      // Ordre du référentiel : rougeur (autre poste, identifiant conservé) puis douleur (ma saisie).
      { id: '01J9ZXK4M2Q8R7S6T5V4W30801', term_code: 'REDNESS', other_text: null },
      { id: expect.stringMatching(/^[0-9A-Z]{26}$/), term_code: 'PAIN', other_text: null },
    ])
    expect(textOf(root)).toContain('Enregistré sur le serveur à 10h42')
    expect(textOf(root)).not.toContain('Valeurs modifiées aussi par une autre personne')
    unmount()
  })

  it('une étape que je n’ai pas modifiée reprend ce que l’autre poste a enregistré', async () => {
    conflictServer()
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')
    await submitForm(root)
    await click(buttonByName(root, 'Recharger la consultation'))

    await click(buttonByName(root, 'Suivant'))

    // L'autre poste a coché « Aucun connu » aux antécédents familiaux : repris tel quel.
    expect(box(group(root, 'Antécédents familiaux oculaires'), 'Aucun connu').checked).toBe(true)
    unmount()
  })

  it('sans valeur modifiée des deux côtés, la consultation rechargée est dite rechargée', async () => {
    let saves = 0
    const api = stubConsultationApi({
      handlers: {
        save: () => {
          saves += 1
          api.server = consultation({
            status: 'IN_PROGRESS',
            version: 2,
            steps: {
              CONCLUSION: {
                conclusion: 'Autre poste',
                referral: null,
                referral_urgency: null,
                referral_reasons: [],
              },
            },
          })
          return saves === 1
            ? errorWithDetails(409, 'VERSION_CONFLICT', 'Modifiée.', { current_version: 2 })
            : json(200, { data: api.server })
        },
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')
    await submitForm(root)

    await click(buttonByName(root, 'Recharger la consultation'))

    expect(textOf(root)).toContain('Consultation rechargée')
    expect(textOf(root)).not.toContain('Valeurs modifiées aussi par une autre personne')
    expect(box(root, 'Douleur').checked).toBe(true)
    unmount()
  })

  it('si la relecture échoue, la saisie reste, le conflit reste à traiter, « Réessayer » relit', async () => {
    let reads = 0
    const api = stubConsultationApi({
      handlers: {
        getConsultation: () => {
          reads += 1
          if (reads === 1) return json(200, { data: api.server })
          return reads === 2 ? 'network' : json(200, { data: api.server })
        },
        save: () => errorWithDetails(409, 'VERSION_CONFLICT', 'Modifiée.', { current_version: 2 }),
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')
    await submitForm(root)

    await click(buttonByName(root, 'Recharger la consultation'))

    expect(textOf(root)).toContain('Consultation indisponible')
    expect(textOf(root)).toContain('La consultation a changé entre-temps')
    expect(box(root, 'Douleur').checked).toBe(true)

    await click(buttonByName(root, 'Réessayer'))
    expect(consultationReads(api)).toHaveLength(3)
    expect(textOf(root)).not.toContain('Consultation indisponible')
    expect(textOf(root)).not.toContain('La consultation a changé entre-temps')
    expect(box(root, 'Douleur').checked).toBe(true)
    unmount()
  })
})

describe('refus d’état, droits, coupure et session', () => {
  it('consultation terminée ailleurs (409 STATE_TRANSITION_REFUSED) : dit, saisie conservée, pas de « Réessayer »', async () => {
    stubConsultationApi({
      handlers: {
        save: () =>
          errorWithDetails(
            409,
            'STATE_TRANSITION_REFUSED',
            'Cette consultation est déjà terminée.',
            {
              current_status: 'COMPLETED',
            },
          ),
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await submitForm(root)

    expect(textOf(root)).toContain('Enregistrement impossible dans l’état actuel')
    expect(textOf(root)).toContain('Cette consultation est déjà terminée.')
    expect(textOf(root)).toContain('Étape « Motif et lunettes »')
    expect(textOf(root)).not.toContain('La consultation a changé entre-temps')
    expect(buttonByName(root, 'Réessayer l’enregistrement')).toBeNull()
    expect(box(root, 'Douleur').checked).toBe(true)
    expect(textOf(root)).not.toMatch(NOT_SAVED)
    unmount()
  })

  it('droits retirés pendant la saisie (403) : « Droits insuffisants », saisie conservée', async () => {
    stubConsultationApi({
      handlers: { save: () => apiError(403, 'ACTION_FORBIDDEN', 'Action non autorisée.') },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await submitForm(root)

    expect(textOf(root)).toContain('Droits insuffisants')
    expect(textOf(root)).toContain('Action non autorisée.')
    expect(box(root, 'Douleur').checked).toBe(true)
    unmount()
  })

  it('réseau coupé : « non confirmé », « Réessayer » reprend la même clé et la même charge, sans doublon', async () => {
    let attempt = 0
    const api = stubConsultationApi({
      handlers: {
        save: (_id, step, body) => {
          attempt += 1
          if (attempt === 1) return 'network'
          const sent = body['step'] as { data: unknown }
          api.server = {
            ...api.server,
            status: 'IN_PROGRESS',
            version: api.server.version + 1,
            steps: { ...api.server.steps, [step]: sent.data } as typeof api.server.steps,
          }
          return json(200, { data: api.server })
        },
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await submitForm(root)

    expect(textOf(root)).toContain('Enregistrement non confirmé')
    expect(textOf(root)).toContain('Étape « Motif et lunettes »')
    expect(textOf(root)).toContain('Échec de l’enregistrement')
    expect(textOf(root)).not.toMatch(NOT_SAVED)
    expect(box(root, 'Douleur').checked).toBe(true)

    await click(buttonByName(root, 'Réessayer l’enregistrement'))

    const [first, second] = patchCalls(api)
    expect(patchCalls(api)).toHaveLength(2)
    expect(second?.headers['Idempotency-Key']).toBe(first?.headers['Idempotency-Key'])
    expect(second?.body).toEqual(first?.body)
    expect(textOf(root)).toContain('Enregistré sur le serveur à 10h42')
    expect(textOf(root)).not.toContain('Enregistrement non confirmé')
    unmount()
  })

  it('« Suivant » interrompu par une coupure avance après la reprise réussie', async () => {
    let attempt = 0
    const api = stubConsultationApi({
      handlers: {
        save: () => {
          attempt += 1
          if (attempt === 1) return 'network'
          api.server = consultation({ status: 'IN_PROGRESS', version: 2 })
          return json(200, { data: api.server })
        },
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await click(buttonByName(root, 'Suivant'))
    expect(root.querySelector('h2#consultation-etape')?.textContent).toContain('Étape 1 sur 3')

    await click(buttonByName(root, 'Réessayer l’enregistrement'))

    expect(patchCalls(api)).toHaveLength(2)
    expect(root.querySelector('h2#consultation-etape')?.textContent).toContain('Étape 2 sur 3')
    unmount()
  })

  it('erreur 500 du serveur ou page d’hébergeur : « non confirmé » (la commande a pu être appliquée)', async () => {
    stubConsultationApi({
      handlers: {
        save: () =>
          new Response('<!DOCTYPE html><html><body>503</body></html>', {
            status: 503,
            headers: { 'Content-Type': 'text/html' },
          }),
      },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await submitForm(root)

    expect(textOf(root)).toContain('Enregistrement non confirmé')
    expect(buttonByName(root, 'Réessayer l’enregistrement')).not.toBeNull()
    unmount()
  })

  it('trop de demandes (429) : le serveur demande de patienter, rien n’a été appliqué, « Réessayer »', async () => {
    stubConsultationApi({
      handlers: { save: () => apiError(429, 'RATE_LIMITED', 'Trop de demandes.') },
    })
    const { root, unmount } = await openConsultation()
    await toggle(root, 'Douleur')

    await submitForm(root)

    expect(textOf(root)).toContain('Le serveur demande de patienter')
    expect(buttonByName(root, 'Réessayer l’enregistrement')).not.toBeNull()
    unmount()
  })

  it('session expirée : saisie conservée, « Se reconnecter », puis « Réessayer » renvoie la même clé', async () => {
    let expired = true
    const api = stubConsultationApi({
      handlers: {
        save: (_id, step, body) => {
          if (expired) return apiError(401, 'AUTH_REQUIRED', 'Connexion requise.')
          const sent = body['step'] as { data: unknown }
          api.server = {
            ...api.server,
            status: 'IN_PROGRESS',
            version: api.server.version + 1,
            steps: { ...api.server.steps, [step]: sent.data } as typeof api.server.steps,
          }
          return json(200, { data: api.server })
        },
      },
    })
    const reconnected = api.me
    const { root, router, unmount } = await openConsultation()
    await toggle(root, 'Douleur')
    api.me = 'anonymous'

    await submitForm(root)

    expect(textOf(root)).toContain('Session expirée')
    expect(buttonByName(root, 'Se reconnecter')).not.toBeNull()
    expect(box(root, 'Douleur').checked).toBe(true)
    expect(router.currentRoute.value.name).toBe('consultation')
    expect(textOf(root)).not.toMatch(NOT_SAVED)
    // L'écran ne bascule pas en « lecture seule » : la saisie et ses boutons restent pendant la reconnexion.
    expect(textOf(root)).not.toContain('Lecture seule')
    expect(buttonByName(root, 'Enregistrer')).not.toBeNull()
    expect(box(root, 'Douleur').disabled).toBe(false)

    expired = false
    api.me = reconnected
    await click(buttonByName(root, 'Réessayer l’enregistrement'))

    const [first, second] = patchCalls(api)
    expect(second?.headers['Idempotency-Key']).toBe(first?.headers['Idempotency-Key'])
    expect(textOf(root)).toContain('Enregistré sur le serveur à 10h42')
    unmount()
  })

  it('les données saisies ne vont jamais dans le stockage du navigateur, même en cas d’échec', async () => {
    stubConsultationApi({ handlers: { save: () => 'network' } })
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
    const { root, unmount } = await openConsultation()
    await type(root, 'Plaintes et symptômes', 'Texte clinique fictif')

    await submitForm(root)

    expect(setItem).not.toHaveBeenCalled()
    expect(window.localStorage.length).toBe(0)
    expect(window.sessionStorage.length).toBe(0)
    unmount()
  })
})

describe('garde de saisie non enregistrée', () => {
  it('quitter avec une étape non enregistrée demande confirmation ; refusée, on reste avec la saisie', async () => {
    stubConsultationApi()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { root, router, unmount } = await openConsultation()
    await type(root, 'Plaintes et symptômes', 'Texte')

    await router.push('/patients')
    await flushPromises()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('consultation')
    expect(field(root, 'Plaintes et symptômes').value).toBe('Texte')

    confirm.mockReturnValue(true)
    await router.push('/patients')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('patients')
    unmount()
  })

  it('une saisie sur une AUTRE étape que celle affichée est aussi gardée', async () => {
    stubConsultationApi()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { root, router, unmount } = await openConsultation()
    await type(root, 'Plaintes et symptômes', 'Texte')
    await click(buttonByName(root, 'Suivant')) // enregistre l'étape 1
    await toggle(root, 'Diabète') // étape 2 non enregistrée
    await click(buttonByName(root, 'Précédent'))

    await router.push('/patients')
    await flushPromises()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('consultation')
    unmount()
  })

  it('fermer l’onglet avec une saisie non enregistrée déclenche l’avertissement du navigateur', async () => {
    stubConsultationApi()
    const { root, unmount } = await openConsultation()
    await type(root, 'Plaintes et symptômes', 'Texte')

    const event = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
    // Une fois enregistrée, plus d'avertissement.
    await click(buttonByName(root, 'Enregistrer'))
    const after = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(after)
    expect(after.defaultPrevented).toBe(false)
    unmount()
  })

  it('une consultation relue ou enregistrée se quitte sans confirmation ; la navigation entre étapes n’en demande pas', async () => {
    stubConsultationApi()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { root, router, unmount } = await openConsultation()
    await click(buttonByName(root, 'Suivant'))
    await click(buttonByName(root, 'Précédent'))
    await toggle(root, 'Douleur')
    await click(buttonByName(root, 'Enregistrer'))

    await router.push('/patients')
    await flushPromises()

    expect(confirm).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('patients')
    unmount()
  })

  it('en lecture seule, rien n’est à enregistrer : aucune confirmation', async () => {
    stubConsultationApi({ permissions: ['clinical.read', 'patient.read'] })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { router, unmount } = await openConsultation()

    await router.push('/patients')
    await flushPromises()

    expect(confirm).not.toHaveBeenCalled()
    unmount()
  })
})

describe('reprise après rechargement', () => {
  it('rouvrir la consultation montre ce que le serveur a enregistré, étape par étape', async () => {
    const api = stubConsultationApi()
    const first = await openConsultation()
    await toggle(first.root, 'Douleur')
    await type(first.root, 'Plaintes et symptômes', 'Gêne')
    await click(buttonByName(first.root, 'Suivant'))
    await toggle(first.root, 'Diabète')
    await click(buttonByName(first.root, 'Enregistrer'))
    first.unmount()
    const saved = api.server

    const second = await openConsultation()

    expect(saved.version).toBe(3)
    expect(box(second.root, 'Douleur').checked).toBe(true)
    expect(field(second.root, 'Plaintes et symptômes').value).toBe('Gêne')
    await click(buttonByName(second.root, 'Suivant'))
    expect(box(second.root, 'Diabète').checked).toBe(true)
    expect(textOf(second.root)).toContain('En cours')
    second.unmount()
  })
})
