import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  apiError,
  buttonByName,
  callsTo,
  click,
  clinicSettings,
  CLINICIAN_PERMISSIONS,
  json,
  openApp,
  RECEPTION_PERMISSIONS,
  resetEverything,
  settle,
  stubSettingsApi,
  textOf,
  type,
} from './support'

/**
 * Paramètres du cabinet (identité imprimée sur les documents, saisie par la gérante, jamais inventée ; fuseau,
 * monnaie et documents officiels en lecture seule ; autres clés de réglage conservées), changement de son
 * propre mot de passe (tout compte), et page d'accueil des paramètres. Données fictives.
 */
afterEach(() => {
  resetEverything()
  vi.unstubAllGlobals()
})

describe('cabinet et documents', () => {
  it('affiche les paramètres, dit « documents de test » et ne propose ni fuseau ni monnaie modifiables', async () => {
    stubSettingsApi({
      handlers: { 'GET /api/v1/clinic/settings': () => json(200, { data: clinicSettings() }) },
    })
    const { root } = await openApp('/parametres/cabinet')
    await settle()

    const text = textOf(root)
    expect(text).toContain('Documents de test')
    expect(text).toContain('Africa/Douala')
    expect(text).toContain('XAF')
    expect((root.querySelector('#cabinet-nom') as HTMLInputElement).value).toBe(
      'TEST CABINET FICTIF',
    )
    expect((root.querySelector('#cabinet-pied') as HTMLTextAreaElement).value).toBe('Pied fictif')
    expect(root.querySelector('#cabinet-fuseau, input[name="timezone"]')).toBeNull()
  })

  it('enregistre après réauthentification, en conservant les autres clés de réglage', async () => {
    let attempts = 0
    const api = stubSettingsApi({
      handlers: {
        'GET /api/v1/clinic/settings': () => json(200, { data: clinicSettings() }),
        'PATCH /api/v1/clinic/settings': (request) => {
          attempts += 1
          if (attempts === 1) return apiError(423, 'REAUTHENTICATION_REQUIRED', 'Confirmez.')
          return json(200, {
            data: clinicSettings({
              tax_identifier: String(request.body?.['tax_identifier']),
              document_settings: request.body?.['document_settings'] as Record<string, unknown>,
            }),
          })
        },
        'POST /api/v1/auth/reauthenticate': () =>
          json(200, { data: { reauthenticated_until: '2026-10-22T10:00:00.000Z' } }),
      },
    })
    const { root } = await openApp('/parametres/cabinet')
    await settle()
    await type(root, 'Identifiant fiscal', 'NIU-FICTIF-0001')
    const header = root.querySelector<HTMLTextAreaElement>('#cabinet-entete')
    if (header === null) throw new Error('En-tête introuvable.')
    header.value = 'Ligne une\n\nLigne deux'
    header.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    await click(buttonByName(root, 'Enregistrer les paramètres'))

    const confirmation = document.querySelector<HTMLInputElement>('#confirmation-mot-de-passe')
    if (confirmation === null) throw new Error('Confirmation introuvable.')
    confirmation.value = 'MotDePasseGerante1'
    confirmation.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    await click(buttonByName(root, 'Confirmer et reprendre'))
    await settle()

    const patches = callsTo(api, 'PATCH', /\/clinic\/settings$/)
    expect(patches).toHaveLength(2)
    expect(patches[1]?.body).toEqual(patches[0]?.body)
    expect(patches[0]?.body?.['tax_identifier']).toBe('NIU-FICTIF-0001')
    expect(patches[0]?.body?.['document_settings']).toEqual({
      extra_key: 'conservée',
      header_lines: ['Ligne une', 'Ligne deux'],
      footer_lines: ['Pied fictif'],
      payment_terms: null,
    })
    // Jamais le fuseau, la monnaie ni l'activation des documents officiels.
    for (const forbidden of ['timezone', 'currency', 'production_documents_enabled']) {
      expect(patches[0]?.body ?? {}).not.toHaveProperty(forbidden)
    }
    expect(textOf(root)).toContain('Paramètres enregistrés sur le serveur')
  })

  it('refuse plus de six lignes de pied de page avant tout envoi', async () => {
    const api = stubSettingsApi({
      handlers: { 'GET /api/v1/clinic/settings': () => json(200, { data: clinicSettings() }) },
    })
    const { root } = await openApp('/parametres/cabinet')
    await settle()
    const footer = root.querySelector<HTMLTextAreaElement>('#cabinet-pied')
    if (footer === null) throw new Error('Pied introuvable.')
    footer.value = ['1', '2', '3', '4', '5', '6', '7'].join('\n')
    footer.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    await click(buttonByName(root, 'Enregistrer les paramètres'))

    expect(textOf(root)).toContain('6 lignes au plus')
    expect(callsTo(api, 'PATCH', /\/clinic\/settings$/)).toHaveLength(0)
  })
})

describe('accueil des paramètres', () => {
  it('montre personnel et cabinet à la gérante et rappelle que les modules appartiennent au déploiement', async () => {
    stubSettingsApi()
    const { root } = await openApp('/parametres')
    await settle()

    expect(root.querySelector('a[href="/parametres/personnel"]')).not.toBeNull()
    expect(root.querySelector('a[href="/parametres/cabinet"]')).not.toBeNull()
    expect(textOf(root)).toContain('ouverts par l’exploitant')
  })
})

describe('changer mon mot de passe', () => {
  it('tout compte change son mot de passe avec l’actuel ; rien n’est réaffiché', async () => {
    const api = stubSettingsApi({
      permissions: CLINICIAN_PERMISSIONS,
      handlers: { 'POST /api/v1/auth/password': () => new Response(null, { status: 204 }) },
    })
    const { root } = await openApp('/mon-compte/mot-de-passe')
    await type(root, 'Mot de passe actuel', 'InitialSecret2026')
    await type(root, 'Nouveau mot de passe', 'NouveauSecret2026')
    await type(root, 'Confirmation du nouveau mot de passe', 'NouveauSecret2026')
    await click(buttonByName(root, 'Changer le mot de passe'))

    const calls = callsTo(api, 'POST', /\/auth\/password$/)
    expect(calls).toHaveLength(1)
    expect(calls[0]?.body).toEqual({
      current_password: 'InitialSecret2026',
      password: 'NouveauSecret2026',
      password_confirmation: 'NouveauSecret2026',
    })
    expect(textOf(root)).toContain('Mot de passe changé sur le serveur')
    expect((root.querySelector('#mdp-nouveau') as HTMLInputElement).value).toBe('')
  })

  it('un mot de passe actuel refusé est dit sur son champ', async () => {
    stubSettingsApi({
      permissions: RECEPTION_PERMISSIONS,
      handlers: {
        'POST /api/v1/auth/password': () =>
          apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
            current_password: ['Mot de passe actuel incorrect.'],
          }),
      },
    })
    const { root } = await openApp('/mon-compte/mot-de-passe')
    await type(root, 'Mot de passe actuel', 'Faux2026Faux')
    await type(root, 'Nouveau mot de passe', 'NouveauSecret2026')
    await type(root, 'Confirmation du nouveau mot de passe', 'NouveauSecret2026')
    await click(buttonByName(root, 'Changer le mot de passe'))

    expect(textOf(root)).toContain('Mot de passe actuel incorrect.')
    expect(textOf(root)).not.toContain('Mot de passe changé')
  })
})
