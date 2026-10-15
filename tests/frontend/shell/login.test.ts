import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it } from 'vitest'

import { createEeoApp } from '@/app/bootstrap'
import { resetNetworkForTests } from '@/app/network'
import { resetSessionForTests, session } from '@/app/session'

import { meResponse, type ServerScript, stubServer } from './server'

async function openLogin(script: ServerScript, path = '/connexion') {
  const fetchMock = stubServer(script)
  const { app, router } = createEeoApp(createMemoryHistory())
  await router.push(path)
  await router.isReady()
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  await flushPromises()
  return { root, router, fetchMock, unmount: () => app.unmount() }
}

function input(root: Element, label: string): HTMLInputElement {
  const labelElement = [...root.querySelectorAll('label')].find((candidate) =>
    candidate.textContent?.startsWith(label),
  )
  const id = labelElement?.getAttribute('for')
  return (
    id === undefined || id === null ? null : root.querySelector(`[id="${id}"]`)
  ) as HTMLInputElement
}

async function fill(root: Element, login: string, password: string): Promise<void> {
  const loginInput = input(root, 'Identifiant')
  loginInput.value = login
  loginInput.dispatchEvent(new Event('input'))
  const passwordInput = input(root, 'Mot de passe')
  passwordInput.value = password
  passwordInput.dispatchEvent(new Event('input'))
  root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

afterEach(() => {
  resetSessionForTests()
  resetNetworkForTests()
  document.body.innerHTML = ''
})

describe('connexion du personnel', () => {
  it('donne de vrais libellés aux champs avec l’autocomplétion du navigateur', async () => {
    const { root, unmount } = await openLogin({ me: 'anonymous' })

    expect(input(root, 'Identifiant').getAttribute('autocomplete')).toBe('username')
    expect(input(root, 'Mot de passe').getAttribute('type')).toBe('password')
    expect(input(root, 'Mot de passe').getAttribute('autocomplete')).toBe('current-password')
    expect(root.querySelector('button[type="submit"]')?.textContent?.trim()).toBe('Se connecter')

    unmount()
  })

  it('ouvre l’écran demandé avant la connexion, chemin interne seulement', async () => {
    const { root, router, fetchMock, unmount } = await openLogin(
      { me: 'anonymous', login: meResponse(['patient.read']) },
      '/une-page',
    )
    expect(router.currentRoute.value.query).toEqual({ suite: '/une-page' })

    await fill(root, '  demo.caisse  ', 'mot-de-passe-fictif')

    const login = fetchMock.mock.calls.find(([url]) => String(url) === '/api/v1/auth/login')
    expect(JSON.parse(String(login?.[1]?.body))).toEqual({
      login: 'demo.caisse',
      password: 'mot-de-passe-fictif',
    })
    // Le cookie CSRF est demandé avant l'envoi des identifiants.
    const order = fetchMock.mock.calls.map(([url]) => String(url))
    expect(order.indexOf('/api/sanctum/csrf-cookie')).toBeLessThan(
      order.indexOf('/api/v1/auth/login'),
    )
    expect(session.status).toBe('authenticated')
    expect(router.currentRoute.value.path).toBe('/une-page')
    expect(input(root, 'Mot de passe')).toBeNull()

    unmount()
  })

  it('n’envoie jamais vers une autre origine après la connexion', async () => {
    const { root, router, unmount } = await openLogin(
      { me: 'anonymous', login: meResponse(['patient.read']) },
      '/connexion?suite=//evil.example/piege',
    )

    await fill(root, 'demo.caisse', 'mot-de-passe-fictif')

    expect(router.currentRoute.value.name).toBe('accueil')
    unmount()
  })

  it('affiche le refus sous le champ et dans le résumé focalisé, mot de passe à ressaisir', async () => {
    const { root, unmount } = await openLogin({
      me: 'anonymous',
      login: {
        status: 422,
        code: 'VALIDATION_FAILED',
        message: 'Identifiant ou mot de passe incorrect.',
        fields: { login: ['Identifiant ou mot de passe incorrect.'] },
      },
    })

    await fill(root, 'demo.caisse', 'faux')

    const summary = root.querySelector('[role="alert"]') as HTMLElement
    expect(summary.textContent).toContain('Connexion impossible')
    expect(summary.textContent).toContain('Identifiant ou mot de passe incorrect.')
    expect(document.activeElement).toBe(summary)
    const loginInput = input(root, 'Identifiant')
    expect(loginInput.getAttribute('aria-invalid')).toBe('true')
    const describedBy = loginInput.getAttribute('aria-describedby') ?? ''
    expect(root.querySelector(`[id="${describedBy}"]`)?.textContent).toContain(
      'Identifiant ou mot de passe incorrect.',
    )
    expect(loginInput.value).toBe('demo.caisse')
    expect(input(root, 'Mot de passe').value).toBe('')

    // Le lien du résumé mène au champ en erreur.
    ;(summary.querySelector('a') as HTMLAnchorElement).click()
    expect(document.activeElement).toBe(loginInput)

    unmount()
  })

  it('signale les champs vides sans appeler le serveur', async () => {
    const { root, fetchMock, unmount } = await openLogin({ me: 'anonymous' })
    fetchMock.mockClear()

    await fill(root, '   ', '')

    expect(fetchMock).not.toHaveBeenCalled()
    expect(root.querySelector('[role="alert"]')?.textContent).toContain(
      'Saisissez votre identifiant.',
    )
    expect(root.querySelector('[role="alert"]')?.textContent).toContain(
      'Saisissez votre mot de passe.',
    )
    expect(input(root, 'Mot de passe').getAttribute('aria-invalid')).toBe('true')

    // Second envoi avec les mêmes erreurs : le résumé est de nouveau focalisé, donc annoncé.
    const summary = root.querySelector('[role="alert"]') as HTMLElement
    input(root, 'Identifiant').focus()
    root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    expect(document.activeElement).toBe(summary)

    unmount()
  })

  it('dit « trop de tentatives » sans vider l’identifiant', async () => {
    const { root, unmount } = await openLogin({
      me: 'anonymous',
      login: {
        status: 429,
        code: 'RATE_LIMITED',
        message: 'Trop de tentatives : patientez avant de réessayer.',
      },
    })

    await fill(root, 'demo.caisse', 'mot-de-passe-fictif')

    expect(root.querySelector('[role="alert"]')?.textContent).toContain('Trop de tentatives')
    expect(input(root, 'Identifiant').value).toBe('demo.caisse')

    unmount()
  })

  it('hors réseau, dit que la connexion demande le serveur sans inventer de mode hors-ligne', async () => {
    const { root, unmount } = await openLogin({ me: 'anonymous' })

    await fill(root, 'demo.caisse', 'mot-de-passe-fictif')

    expect(root.querySelector('[role="alert"]')?.textContent).toContain('Serveur injoignable')
    expect(session.status).toBe('anonymous')

    unmount()
  })

  it('un compte déjà connecté qui ouvre la connexion revient à l’accueil', async () => {
    const { router, unmount } = await openLogin({ me: meResponse(['patient.read']) })

    expect(router.currentRoute.value.name).toBe('accueil')
    unmount()
  })
})
