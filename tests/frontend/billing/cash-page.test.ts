import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  apiError,
  buttonByName,
  callsTo,
  CASHIER,
  click,
  dialogButton,
  forbiddenWordsIn,
  json,
  MANAGER,
  openApp,
  openSession,
  REGISTER_ID,
  REGISTERS,
  resetSales,
  SESSION_ID,
  stubSalesApi,
  textOf,
  typeInto,
} from './support'

// Caisse (B05 ; AT25), EN LIGNE : ouverture avec fond, attendu projeté par le serveur, clôture avec
// écart motivé, attendu changé depuis l'affichage (409) sans perte du compté, droits et module fermé.
// Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetSales()
})

const PAGE = '/ventes/caisse'

describe('caisse : ouverture', () => {
  it('sans session ouverte, le dit et ouvre la caisse avec un fond (clé d’idempotence, version nulle)', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    let current: ReturnType<typeof openSession> | null = null
    api.on('GET', /^\/api\/v1\/cash-registers$/, () => json(200, { data: REGISTERS }))
    api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: current }))
    api.on('POST', /^\/api\/v1\/cash-sessions$/, (request) => {
      current = openSession({
        id: String(request.body?.['cash_session_id']),
        opening_amount: 25000,
        collected_cash: 0,
        theoretical_cash: 25000,
      })
      return json(201, { data: current })
    })

    const { root, unmount } = await openApp(PAGE)

    expect(root.querySelector('h1')?.textContent).toBe('Caisse')
    expect(textOf(root)).toContain('Aucune session de caisse ouverte')
    expect(textOf(root)).toContain('un encaissement en espèces est impossible')
    expect(textOf(root)).toContain('Caisse principale')

    await typeInto(root, 'caisse-fond', '25 000')
    await click(buttonByName(root, 'Ouvrir la caisse'))

    const [open] = callsTo(api, 'POST', /^\/api\/v1\/cash-sessions$/)
    expect(open?.body).toMatchObject({
      expected_version: null,
      cash_register_id: REGISTER_ID,
      opening_amount: 25000,
      opened_at: '2026-10-15T09:00:00.000Z',
    })
    expect(open?.headers['Idempotency-Key']).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/)
    expect(textOf(root)).toContain('Session ouverte : Caisse principale')
    expect(textOf(root)).toContain('Espèces attendues dans le tiroir')
    expect(forbiddenWordsIn(root)).toEqual([])

    unmount()
  })

  it('refuse d’ouvrir sans fond saisi, sans rien envoyer', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    api.on('GET', /^\/api\/v1\/cash-registers$/, () => json(200, { data: REGISTERS }))
    api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: null }))

    const { root, unmount } = await openApp(PAGE)
    await click(buttonByName(root, 'Ouvrir la caisse'))

    expect(textOf(root)).toContain('Saisissez le fond de caisse')
    expect(callsTo(api, 'POST', /cash-sessions/)).toHaveLength(0)

    unmount()
  })
})

describe('caisse : clôture', () => {
  function server(permissions = CASHIER) {
    const api = stubSalesApi({ permissions })
    api.on('GET', /^\/api\/v1\/cash-registers$/, () => json(200, { data: REGISTERS }))
    api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: openSession() }))
    return api
  }

  it('dit l’écart sans jugement et exige un motif quand il n’est pas nul', async () => {
    const api = server()
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).toContain('105 500')
    await typeInto(root, 'caisse-compte', '105000')
    expect(textOf(root)).toContain('Écart : -500')
    expect(textOf(root)).toContain('Le compté est inférieur à l’attendu.')

    await click(buttonByName(root, 'Clôturer la caisse'))
    expect(textOf(root)).toContain('Un écart de caisse exige un motif.')
    expect(callsTo(api, 'POST', /close$/)).toHaveLength(0)

    unmount()
  })

  it('clôture après confirmation ; la session close est figée et affichée', async () => {
    const api = server()
    api.on('POST', /^\/api\/v1\/cash-sessions\/([^/]+)\/close$/, (request) =>
      json(200, {
        data: openSession({
          closed_at: '2026-10-15T09:00:00.000Z',
          counted_amount: Number(request.body?.['counted_amount']),
          expected_amount: 105500,
          variance: -500,
          variance_reason: String(request.body?.['variance_reason']),
          closed_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
        }),
      }),
    )
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'caisse-compte', '105000')
    await typeInto(root, 'caisse-motif', 'Écart de monnaie constaté (fictif)')
    await click(buttonByName(root, 'Clôturer la caisse'))
    expect(textOf(root)).toContain('La session clôturée ne pourra plus être modifiée')
    // Après la clôture, le serveur ne connaît plus de session ouverte.
    api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: null }))
    await click(dialogButton(root, 'Clôturer la caisse'))

    const [close] = callsTo(api, 'POST', /close$/)
    expect(close?.path).toBe(`/api/v1/cash-sessions/${SESSION_ID}/close`)
    expect(close?.body).toMatchObject({
      expected_version: null,
      cash_session_id: SESSION_ID,
      counted_amount: 105000,
      expected_amount: 105500,
      variance: -500,
      variance_reason: 'Écart de monnaie constaté (fictif)',
    })
    expect(textOf(root)).toContain('Session de caisse clôturée')
    expect(textOf(root)).toContain('La session clôturée est figée')
    expect(textOf(root)).toContain('Aucune session de caisse ouverte')

    unmount()
  })

  it('attendu changé depuis l’affichage (409) : les deux montants sont dits, le compté est conservé', async () => {
    const api = server()
    api.on('POST', /close$/, () =>
      json(409, {
        error: {
          code: 'VERSION_CONFLICT',
          message: 'Les espèces attendues ont changé depuis votre affichage.',
          details: { expected_amount: 110500 },
        },
        correlation_id: 'corr-test-0300',
      }),
    )
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'caisse-compte', '105500')
    await click(buttonByName(root, 'Clôturer la caisse'))
    await click(dialogButton(root, 'Clôturer la caisse'))

    expect(textOf(root)).toContain('Les espèces attendues ont changé depuis l’affichage')
    expect(textOf(root)).toContain('110 500')
    expect(
      (root.querySelector('[id="caisse-compte"]') as HTMLInputElement).value.replace(/\s/g, ' '),
    ).toBe('105 500')

    unmount()
  })

  it('la gérante voit les sessions de toutes les caisses et clôture celle d’une autre caissière', async () => {
    const api = server(MANAGER)
    const OTHER_SESSION = '01J9ZXK4M2Q8R7S6T5V4W3002Z'
    const OTHER_CASHIER = '01J9ZXK4M2Q8R7S6T5V4W30009'
    const other = {
      ...openSession({
        id: OTHER_SESSION,
        cashier_id: OTHER_CASHIER,
        collected_cash: 5000,
        theoretical_cash: 30000,
      }),
      cashier_name: 'Autre caisse (démonstration)',
      closed_by_name: null,
      register_code: 'CAISSE-PRINCIPALE',
      register_name: 'Caisse principale',
    }
    api.on('GET', /^\/api\/v1\/cash-sessions$/, () =>
      json(200, { data: [other], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }),
    )
    api.on('POST', /^\/api\/v1\/cash-sessions\/([^/]+)\/close$/, () =>
      json(200, {
        data: openSession({
          id: OTHER_SESSION,
          cashier_id: OTHER_CASHIER,
          closed_at: '2026-10-15T09:00:00.000Z',
          counted_amount: 30000,
          expected_amount: 30000,
          variance: 0,
          closed_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
        }),
      }),
    )
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).toContain('Sessions de toutes les caisses')
    expect(textOf(root)).toContain('Autre caisse (démonstration)')
    expect(textOf(root)).not.toContain('indisponible dans cette version')
    await click(buttonByName(root, 'Clôturer cette session'))
    await typeInto(root, 'gerante-caisse-compte', '30000')
    await click(buttonByName(root, 'Clôturer la session'))
    await click(dialogButton(root, 'Clôturer la session'))

    const [close] = callsTo(api, 'POST', /^\/api\/v1\/cash-sessions\/[^/]+\/close$/)
    expect(close?.path).toBe(`/api/v1/cash-sessions/${OTHER_SESSION}/close`)
    expect(close?.body).toMatchObject({
      cash_session_id: OTHER_SESSION,
      counted_amount: 30000,
      expected_amount: 30000,
      variance: 0,
      variance_reason: null,
      expected_version: null,
    })
    expect(close?.headers['Idempotency-Key']).toBeTruthy()
    expect(textOf(root)).toContain('Session de Autre caisse (démonstration) clôturée')
    expect(forbiddenWordsIn(root)).toEqual([])

    unmount()
  })

  it('la caissière ne voit pas les sessions des autres caisses', async () => {
    const api = server(CASHIER)
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).not.toContain('Sessions de toutes les caisses')
    expect(callsTo(api, 'GET', /^\/api\/v1\/cash-sessions$/)).toHaveLength(0)

    unmount()
  })
})

describe('caisse : droits et module', () => {
  it('module « sales » fermé : page introuvable, aucune lecture de caisse', async () => {
    const api = stubSalesApi({ permissions: CASHIER, modules: ['patients', 'today'] })
    const { root, router, unmount } = await openApp(PAGE)

    expect(router.currentRoute.value.name).toBe('introuvable')
    expect(root.querySelector('h1')?.textContent).toBe('Page introuvable')
    expect(callsTo(api, 'GET', /cash/)).toHaveLength(0)

    unmount()
  })

  it('sans cash.own_session : accès refusé', async () => {
    stubSalesApi({ permissions: ['invoice.read'] })
    const { router, unmount } = await openApp(PAGE)

    expect(router.currentRoute.value.name).toBe('acces-refuse')

    unmount()
  })

  it('serveur injoignable : la caisse est « indisponible », jamais « aucune session »', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    api.on('GET', /^\/api\/v1\/cash-registers$/, () => 'network')
    api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => 'network')
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).toContain('Serveur indisponible')
    expect(textOf(root)).not.toContain('Aucune session de caisse ouverte')

    unmount()
  })

  it('une session déjà ouverte ailleurs (409 d’état) est relue', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    let current: ReturnType<typeof openSession> | null = null
    api.on('GET', /^\/api\/v1\/cash-registers$/, () => json(200, { data: REGISTERS }))
    api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: current }))
    api.on('POST', /^\/api\/v1\/cash-sessions$/, () => {
      current = openSession()
      return apiError(
        409,
        'STATE_TRANSITION_REFUSED',
        'Une session est déjà ouverte pour cette caisse.',
      )
    })
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'caisse-fond', '25000')
    await click(buttonByName(root, 'Ouvrir la caisse'))

    expect(textOf(root)).toContain('Une session est déjà ouverte pour cette caisse.')
    expect(textOf(root)).toContain('Session ouverte : Caisse principale')

    unmount()
  })
})
