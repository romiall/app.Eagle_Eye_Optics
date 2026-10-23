import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  apiError,
  buttonByName,
  callsTo,
  click,
  json,
  MANAGER_ID,
  member,
  openApp,
  RECEPTION_PERMISSIONS,
  resetEverything,
  SELF_ID,
  settle,
  STAFF_ID,
  staffPage,
  stubSettingsApi,
  textOf,
  type,
} from './support'

/**
 * Écrans du personnel (F05 côté serveur, F09) : liste, création avec mot de passe initial montré une seule
 * fois, réauthentification (423) puis reprise de la MÊME écriture, modification avec motif, dérogation,
 * réinitialisation ; la gérante ne se modifie pas elle-même ; module « settings » fermé ou droit absent :
 * rien. Données fictives.
 */
afterEach(() => {
  resetEverything()
  vi.unstubAllGlobals()
})

describe('liste du personnel', () => {
  it('liste les comptes avec profils et état, et propose la création', async () => {
    stubSettingsApi({
      handlers: {
        'GET /api/v1/staff': () =>
          json(
            200,
            staffPage([
              member(),
              member({
                id: MANAGER_ID,
                display_name: 'Gérante Fictive',
                roles: ['MANAGER'],
                active: false,
              }),
            ]),
          ),
      },
    })
    const { root } = await openApp('/parametres/personnel')
    await settle()

    const text = textOf(root)
    expect(text).toContain('Accueil Fictif')
    expect(text).toContain('Accueil')
    expect(text).toContain('Désactivé')
    expect(root.querySelector(`a[href="/parametres/personnel/${STAFF_ID}"]`)).not.toBeNull()
    expect(root.querySelector('a[href="/parametres/personnel/nouveau"]')).not.toBeNull()
  })

  it('une liste non lue est « indisponible », jamais « aucun compte »', async () => {
    stubSettingsApi({ handlers: { 'GET /api/v1/staff': () => 'network' } })
    const { root } = await openApp('/parametres/personnel')
    await settle()

    expect(textOf(root)).toContain('Serveur indisponible')
    expect(textOf(root)).not.toContain('Aucun compte')
  })

  it('sans users.manage, ou module « settings » fermé, l’écran n’est pas ouvert', async () => {
    stubSettingsApi({ permissions: RECEPTION_PERMISSIONS })
    const first = await openApp('/parametres/personnel')
    await settle()
    expect(textOf(first.root)).toContain('Accès refusé')
    first.unmount()
    resetEverything()

    stubSettingsApi({ modules: ['today', 'patients'] })
    const second = await openApp('/parametres/personnel')
    await settle()
    expect(textOf(second.root)).toContain('Page introuvable')
  })
})

describe('création d’un compte', () => {
  async function fill(root: ParentNode): Promise<void> {
    await type(root, 'Nom affiché', 'Caisse Fictive')
    await type(root, 'Identifiant de connexion', 'Caisse.Fictive')
    const checkbox = [...root.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')].find(
      (input) => input.closest('label')?.textContent?.includes('Caisse') === true,
    )
    if (checkbox === undefined) throw new Error('Profil Caisse introuvable.')
    checkbox.click()
    await settle()
  }

  it('refuse avant tout envoi un mot de passe trop faible et un compte sans profil', async () => {
    const api = stubSettingsApi()
    const { root } = await openApp('/parametres/personnel/nouveau')
    await type(root, 'Nom affiché', 'Caisse Fictive')
    await type(root, 'Identifiant de connexion', 'caisse.fictive')
    await type(root, 'Mot de passe initial', 'court')
    await click(buttonByName(root, 'Créer le compte'))

    expect(textOf(root)).toContain('Certaines informations sont à corriger')
    expect(textOf(root)).toContain('Choisissez au moins un profil')
    expect(callsTo(api, 'POST', /\/api\/v1\/staff$/)).toHaveLength(0)
  })

  it('génère un mot de passe, demande la réauthentification (423), reprend la même création et montre le mot de passe UNE fois', async () => {
    let attempts = 0
    const api = stubSettingsApi({
      handlers: {
        'POST /api/v1/staff': (request) => {
          attempts += 1
          if (attempts === 1) {
            return apiError(423, 'REAUTHENTICATION_REQUIRED', 'Confirmez votre mot de passe.')
          }
          return json(201, {
            data: member({
              id: STAFF_ID,
              login: String(request.body?.['login']),
              display_name: 'Caisse Fictive',
              roles: ['CASHIER'],
              permissions: ['invoice.read'],
            }),
          })
        },
        'POST /api/v1/auth/reauthenticate': () =>
          json(200, { data: { reauthenticated_until: '2026-10-22T10:00:00.000Z' } }),
        'GET /api/v1/staff/:id': () =>
          json(200, { data: member({ display_name: 'Caisse Fictive' }) }),
      },
    })
    const { root } = await openApp('/parametres/personnel/nouveau')
    await fill(root)
    await click(buttonByName(root, 'Générer un mot de passe'))
    await click(buttonByName(root, 'Créer le compte'))

    // 423 : la boîte de confirmation du mot de passe de la gérante s'ouvre, la saisie reste derrière.
    expect(textOf(root)).toContain('Confirmer votre mot de passe')
    const confirmation = root.ownerDocument.querySelector<HTMLInputElement>(
      '[id^="confirmation-mot-de-passe"]',
    )
    if (confirmation === null) throw new Error('Champ de confirmation introuvable.')
    confirmation.value = 'MotDePasseGerante1'
    confirmation.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    await click(buttonByName(root, 'Confirmer et reprendre'))
    await settle()

    const creations = callsTo(api, 'POST', /\/api\/v1\/staff$/)
    expect(creations).toHaveLength(2)
    // La même création repart : mêmes valeurs, identifiant normalisé en minuscules.
    expect(creations[1]?.body).toEqual(creations[0]?.body)
    expect(creations[0]?.body?.['login']).toBe('caisse.fictive')
    expect(creations[0]?.body?.['roles']).toEqual(['CASHIER'])
    const generated = String(creations[0]?.body?.['password'])
    expect(generated).toHaveLength(16)

    // Le mot de passe généré est montré une fois, puis oublié en quittant l'écran.
    expect(textOf(root)).toContain('Compte créé sur le serveur')
    expect(textOf(root)).toContain(generated)
    await click(buttonByName(root, 'J’ai noté, ouvrir le compte'))
    await settle()
    expect(textOf(root)).not.toContain(generated)
    expect(textOf(root)).toContain('Compte « Caisse Fictive » créé.')
  })

  it('les erreurs du serveur sont rattachées à leurs champs', async () => {
    stubSettingsApi({
      handlers: {
        'POST /api/v1/staff': () =>
          apiError(422, 'VALIDATION_FAILED', 'Données invalides.', {
            login: ['Cet identifiant ou cette adresse électronique est déjà utilisé.'],
          }),
      },
    })
    const { root } = await openApp('/parametres/personnel/nouveau')
    await fill(root)
    await type(root, 'Mot de passe initial', 'MotDePasse2026')
    await click(buttonByName(root, 'Créer le compte'))

    expect(textOf(root)).toContain(
      'Cet identifiant ou cette adresse électronique est déjà utilisé.',
    )
    expect(textOf(root)).not.toContain('Compte créé')
  })
})

describe('fiche d’un compte', () => {
  it('modifie un compte avec sa version et exige un motif pour le désactiver', async () => {
    const api = stubSettingsApi({
      handlers: {
        'GET /api/v1/staff/:id': () => json(200, { data: member() }),
        'PATCH /api/v1/staff/:id': () => json(200, { data: member({ active: false, version: 4 }) }),
      },
    })
    const { root } = await openApp(`/parametres/personnel/${STAFF_ID}`)
    await settle()
    expect(textOf(root)).toContain('Droits effectifs')

    const active = [...root.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')].find(
      (input) => input.closest('label')?.textContent?.includes('Compte actif') === true,
    )
    active?.click()
    await settle()
    await click(buttonByName(root, 'Enregistrer le compte'))
    expect(textOf(root)).toContain('Indiquez le motif')
    expect(callsTo(api, 'PATCH', /\/api\/v1\/staff\//)).toHaveLength(0)

    await type(root, 'Motif (obligatoire)', 'Départ du cabinet (fictif)')
    await click(buttonByName(root, 'Enregistrer le compte'))
    const patches = callsTo(api, 'PATCH', /\/api\/v1\/staff\//)
    expect(patches).toHaveLength(1)
    expect(patches[0]?.body).toEqual({
      expected_version: 3,
      active: false,
      reason: 'Départ du cabinet (fictif)',
    })
    expect(textOf(root)).toContain('Compte enregistré sur le serveur')
  })

  it('un conflit de version (409) garde la saisie et le dit', async () => {
    stubSettingsApi({
      handlers: {
        'GET /api/v1/staff/:id': () => json(200, { data: member() }),
        'PATCH /api/v1/staff/:id': () => apiError(409, 'VERSION_CONFLICT', 'Version obsolète.'),
      },
    })
    const { root } = await openApp(`/parametres/personnel/${STAFF_ID}`)
    await settle()
    await type(root, 'Nom affiché', 'Accueil Renommé')
    await click(buttonByName(root, 'Enregistrer le compte'))

    expect(textOf(root)).toContain('modifié entre-temps')
    expect((root.querySelector('#profil-nom') as HTMLInputElement).value).toBe('Accueil Renommé')
  })

  it('pose une dérogation motivée, avec échéance à la fin du jour civil du cabinet', async () => {
    const api = stubSettingsApi({
      handlers: {
        'GET /api/v1/staff/:id': () => json(200, { data: member() }),
        'POST /api/v1/staff/:id/permissions': () =>
          json(200, {
            data: member({ version: 4, permissions: ['patient.read', 'invoice.read'] }),
          }),
      },
    })
    const { root } = await openApp(`/parametres/personnel/${STAFF_ID}`)
    await settle()

    const select = root.querySelector<HTMLSelectElement>('#derogation-droit')
    expect([...(select?.options ?? [])].map((option) => option.value)).not.toContain('users.manage')
    if (select === null) throw new Error('Liste des droits introuvable.')
    select.value = 'invoice.read'
    select.dispatchEvent(new Event('change', { bubbles: true }))
    const effect = root.querySelector<HTMLSelectElement>('#derogation-effet')
    if (effect === null) throw new Error('Effet introuvable.')
    effect.value = 'ALLOW'
    effect.dispatchEvent(new Event('change', { bubbles: true }))
    await settle()
    const date = root.querySelector<HTMLInputElement>('#derogation-echeance')
    if (date === null) throw new Error('Échéance introuvable.')
    date.value = '30/11/2026'
    date.dispatchEvent(new Event('input', { bubbles: true }))
    date.dispatchEvent(new Event('blur'))
    const reason = root.querySelector<HTMLTextAreaElement>('#derogation-motif')
    if (reason === null) throw new Error('Motif introuvable.')
    reason.value = 'Remplacement de la caisse (fictif)'
    reason.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    await click(buttonByName(root, 'Enregistrer la dérogation'))

    const calls = callsTo(api, 'POST', /\/permissions$/)
    expect(calls).toHaveLength(1)
    expect(calls[0]?.body).toEqual({
      expected_version: 3,
      permission_code: 'invoice.read',
      effect: 'ALLOW',
      reason: 'Remplacement de la caisse (fictif)',
      expires_at: '2026-11-30T23:00:00.000Z',
    })
    expect(textOf(root)).toContain('Dérogation enregistrée sur le serveur')
  })

  it('la gérante ne se modifie ni profils ni activation, ne se déroge pas et ne se réinitialise pas', async () => {
    stubSettingsApi({
      accountId: SELF_ID,
      handlers: {
        'GET /api/v1/staff/:id': () =>
          json(200, {
            data: member({ id: SELF_ID, roles: ['MANAGER'], display_name: 'Gérante Fictive' }),
          }),
      },
    })
    const { root } = await openApp(`/parametres/personnel/${SELF_ID}`)
    await settle()

    const boxes = [
      ...root.querySelectorAll<HTMLInputElement>('#profil-profils input, #profil-actif'),
    ]
    expect(boxes.length).toBeGreaterThan(0)
    expect(boxes.every((box) => box.disabled)).toBe(true)
    expect(buttonByName(root, 'Enregistrer la dérogation')).toBeNull()
    expect(buttonByName(root, 'Réinitialiser le mot de passe')).toBeNull()
    expect(root.querySelector('a[href="/mon-compte/mot-de-passe"]')).not.toBeNull()
  })

  it('le mot de passe d’une autre gérante ne se réinitialise pas depuis l’écran', async () => {
    stubSettingsApi({
      handlers: {
        'GET /api/v1/staff/:id': () =>
          json(200, { data: member({ id: MANAGER_ID, roles: ['MANAGER'] }) }),
      },
    })
    const { root } = await openApp(`/parametres/personnel/${MANAGER_ID}`)
    await settle()

    expect(buttonByName(root, 'Réinitialiser le mot de passe')).toBeNull()
    expect(textOf(root)).toContain('procédure de secours')
  })

  it('réinitialise le mot de passe d’un compte non gérante avec motif et confirmation', async () => {
    const api = stubSettingsApi({
      handlers: {
        'GET /api/v1/staff/:id': () => json(200, { data: member() }),
        'POST /api/v1/staff/:id/password': () => new Response(null, { status: 204 }),
      },
    })
    const { root } = await openApp(`/parametres/personnel/${STAFF_ID}`)
    await settle()
    await type(root, 'Nouveau mot de passe', 'NouveauSecret2026')
    await type(root, 'Confirmation du mot de passe', 'NouveauSecret2026')
    const reason = root.querySelector<HTMLTextAreaElement>('#reinit-motif')
    if (reason === null) throw new Error('Motif introuvable.')
    reason.value = 'Mot de passe oublié (fictif)'
    reason.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    await click(buttonByName(root, 'Réinitialiser le mot de passe'))

    const calls = callsTo(api, 'POST', /\/password$/)
    expect(calls).toHaveLength(1)
    expect(calls[0]?.body).toEqual({
      expected_version: 3,
      password: 'NouveauSecret2026',
      password_confirmation: 'NouveauSecret2026',
      reason: 'Mot de passe oublié (fictif)',
    })
    expect(textOf(root)).toContain('Mot de passe réinitialisé sur le serveur')
    // Un mot de passe saisi (non généré) n'est pas réaffiché.
    expect(textOf(root)).not.toContain('NouveauSecret2026')
  })
})
