import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { createEeoApp } from '@/app/bootstrap'
import type { AppModule, NavigationCatalog } from '@/app/navigation'
import { resetNetworkForTests } from '@/app/network'
import { resetSessionForTests, session } from '@/app/session'
import { useUnsavedChangesGuard } from '@/app/unsavedChanges'

import { meResponse, type ServerScript, stubServer } from './server'

/**
 * Robustesse de la coquille (constats de la revue contradictoire de F09) : réponses HTML d'un
 * hébergeur, joignabilité vérifiée par requête, déconnexion soumise à la garde de saisie, focus au
 * premier affichage, retour après connexion sans paramètre de requête, accueil d'un module sans
 * action d'accueil.
 */
function module(id: string, label: string, permissions: string[]): AppModule {
  return {
    id,
    label,
    path: `/${id}`,
    permissions,
    deliveredBy: 'I01',
    delivered: true,
    mobileShortcut: false,
  }
}

async function mount(script: ServerScript, path = '/', navigation?: NavigationCatalog) {
  const fetchMock = stubServer(script)
  const { app, router } = createEeoApp(
    createMemoryHistory(),
    navigation === undefined ? {} : { navigation },
  )
  await router.push(path)
  await router.isReady()
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  await flushPromises()
  return { root, router, fetchMock, app }
}

function headerStatus(root: Element): string {
  return root.querySelector('header [role="status"]')?.textContent ?? ''
}

function logoutButton(root: Element): HTMLButtonElement {
  return [...root.querySelectorAll('header button')].find(
    (button) => button.textContent?.trim() === 'Se déconnecter',
  ) as HTMLButtonElement
}

afterEach(() => {
  resetSessionForTests()
  resetNetworkForTests()
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe('réponses HTML d’un hébergeur (503, pare-feu, portail captif)', () => {
  it('au démarrage : session non vérifiable, serveur dit injoignable, relecture programmée', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const { root, router, app } = await mount({ me: 'html-error' })

    expect(session.status).toBe('unverified')
    expect(router.currentRoute.value.name).toBe('accueil')
    expect(headerStatus(root)).toContain('Serveur injoignable')
    expect(root.querySelector('main')?.textContent).toContain('Serveur injoignable')

    // Le serveur revient : la relecture périodique rétablit la session.
    stubServer({ me: meResponse(['patient.read']) })
    vi.advanceTimersByTime(30_000)
    await flushPromises()
    expect(session.status).toBe('authenticated')
    app.unmount()
  })

  it('à la connexion : un message clair, jamais une promesse rejetée sans réponse', async () => {
    const { root, app } = await mount({ me: 'anonymous', login: 'html-error' })
    const [login, password] = [...root.querySelectorAll('input')]
    ;(login as HTMLInputElement).value = 'demo.caisse'
    login?.dispatchEvent(new Event('input'))
    ;(password as HTMLInputElement).value = 'mot-de-passe-fictif'
    password?.dispatchEvent(new Event('input'))
    root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(root.querySelector('[role="alert"]')?.textContent).toContain(
      'Réponse inattendue du serveur (503)',
    )
    expect(session.status).toBe('anonymous')
    app.unmount()
  })
})

describe('joignabilité vérifiée par requête', () => {
  it('l’en-tête redevient « En ligne » quand le serveur répond de nouveau, sans action', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const { root, app } = await mount({
      me: meResponse(['patient.read']),
      logout: 'unreachable',
    })
    logoutButton(root).click()
    await flushPromises()
    expect(headerStatus(root)).toContain('Serveur injoignable')

    vi.advanceTimersByTime(30_000)
    await flushPromises()
    expect(headerStatus(root)).toContain('En ligne')
    app.unmount()
  })

  it('une liaison perdue sans changement de navigator.onLine est détectée par la sonde', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const { root, app } = await mount({ me: meResponse(['patient.read']) })
    expect(headerStatus(root)).toContain('En ligne')

    stubServer({ me: 'unreachable' })
    vi.advanceTimersByTime(60_000)
    await flushPromises()
    expect(headerStatus(root)).toContain('Serveur injoignable')
    app.unmount()
  })
})

describe('déconnexion et saisie en cours', () => {
  it('si l’utilisateur garde sa saisie, la session n’est pas fermée', async () => {
    const dirty = ref(true)
    const confirmLeave = vi.fn(() => false)
    const Editor = defineComponent({
      setup() {
        useUnsavedChangesGuard(dirty, confirmLeave)
        return () => h('h1', 'Saisie')
      },
    })
    const fetchMock = stubServer({ me: meResponse(['patient.read']) })
    const { app, router } = createEeoApp(createMemoryHistory())
    router.addRoute({ path: '/saisie', name: 'saisie', component: Editor })
    await router.push('/saisie')
    await router.isReady()
    const root = document.createElement('div')
    document.body.append(root)
    app.mount(root)
    await flushPromises()

    logoutButton(root).click()
    await flushPromises()

    expect(confirmLeave).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.name).toBe('saisie')
    expect(session.status).toBe('authenticated')
    expect(fetchMock.mock.calls.some(([url]) => String(url) === '/api/v1/auth/logout')).toBe(false)
    app.unmount()
  })
})

describe('focus, retour après connexion et accueil', () => {
  it('le premier affichage ne déplace pas le focus, même monté avant la fin de la navigation', async () => {
    stubServer({ me: meResponse(['patient.read']) })
    const { app, router } = createEeoApp(createMemoryHistory())
    const root = document.createElement('div')
    document.body.append(root)
    // Monté tout de suite : la navigation initiale se termine après le montage.
    app.mount(root)
    await router.isReady()
    await flushPromises()

    expect(root.querySelector('main#contenu')).not.toBeNull()
    expect(document.activeElement).toBe(document.body)
    app.unmount()
  })

  it('le retour après connexion garde le chemin, jamais les paramètres de requête', async () => {
    const { router, app } = await mount({ me: 'anonymous' }, '/une-page?nom=NGONO&tel=600000000')

    expect(router.currentRoute.value.name).toBe('connexion')
    expect(router.currentRoute.value.query).toEqual({ suite: '/une-page' })
    app.unmount()
  })

  it('un module livré sans action d’accueil : l’accueil renvoie au menu, sans parler de livraison', async () => {
    const { root, app } = await mount({ me: meResponse(['stock.read']) }, '/', {
      modules: [module('stock', 'Stock', ['stock.read'])],
      homeActions: [],
    })

    expect(root.querySelector('nav[aria-label="Navigation principale"]')?.textContent).toContain(
      'Stock',
    )
    expect(root.querySelector('main')?.textContent).toContain(
      'Les écrans ouverts à ce compte sont dans le menu.',
    )
    expect(root.querySelector('main')?.textContent).not.toContain('Aucun module métier livré')
    app.unmount()
  })
})
