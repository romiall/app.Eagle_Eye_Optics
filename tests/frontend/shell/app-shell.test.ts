import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { createEeoApp } from '@/app/bootstrap'
import type { AppModule, HomeAction, NavigationCatalog } from '@/app/navigation'
import { resetNetworkForTests } from '@/app/network'
import { resetSessionForTests, session } from '@/app/session'

import { meResponse, type ServerScript, stubServer } from './server'

/** Catalogue fictif : écrans « livrés » pour vérifier la coquille sans écran métier réel. */
function module(id: string, label: string, permissions: string[], mobile: boolean): AppModule {
  return {
    id,
    label,
    path: `/${id}`,
    permissions,
    deliveredBy: 'P01',
    delivered: true,
    mobileShortcut: mobile,
  }
}

const CATALOG: NavigationCatalog = {
  modules: [
    module('aujourdhui', 'Aujourd’hui', ['visit.register'], true),
    module('patients', 'Patients', ['patient.read'], true),
    module('agenda', 'Agenda', ['agenda.read'], true),
    module('ventes', 'Ventes et caisse', ['invoice.read'], false),
    module('parametres', 'Paramètres', ['users.manage'], false),
  ],
  homeActions: [
    {
      id: 'recherche',
      label: 'Rechercher',
      path: '/patients',
      permissions: ['patient.read'],
      deliveredBy: 'P02',
      delivered: true,
    },
    {
      id: 'parametres',
      label: 'Paramètres',
      path: '/parametres',
      permissions: ['users.manage'],
      deliveredBy: 'B06',
      delivered: true,
    } satisfies HomeAction,
  ],
}

const Screen = (title: string) => defineComponent({ render: () => h('h1', title) })

async function mountShell(
  permissions: string[],
  path = '/',
  logout: ServerScript['logout'] = 'ok',
) {
  const fetchMock = stubServer({
    me: meResponse(permissions),
    login: meResponse(permissions),
    logout,
  })
  const { app, router } = createEeoApp(createMemoryHistory(), { navigation: CATALOG })
  for (const entry of CATALOG.modules) {
    router.addRoute({ path: entry.path, name: entry.id, component: Screen(entry.label) })
  }
  await router.push(path)
  await router.isReady()
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  await flushPromises()
  return { root, router, fetchMock, unmount: () => app.unmount() }
}

function linkTexts(container: Element | null): string[] {
  return [...(container?.querySelectorAll('a') ?? [])].map((link) => link.textContent?.trim() ?? '')
}

afterEach(() => {
  resetSessionForTests()
  resetNetworkForTests()
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe('coquille : navigation des modules livrés et autorisés', () => {
  it('montre sur ordinateur l’accueil puis les modules autorisés, dans l’ordre', async () => {
    const { root, unmount } = await mountShell(['visit.register', 'patient.read', 'invoice.read'])

    const desktop = root.querySelector('nav[aria-label="Navigation principale"]')
    expect(linkTexts(desktop)).toEqual(['Accueil', 'Aujourd’hui', 'Patients', 'Ventes et caisse'])
    expect(desktop?.querySelector('a[aria-current="page"]')?.textContent).toBe('Accueil')

    unmount()
  })

  it('propose sur téléphone les raccourcis autorisés et les autres modules sous « Plus »', async () => {
    const { root, unmount } = await mountShell(['patient.read', 'agenda.read', 'users.manage'])

    const mobile = root.querySelector('nav[aria-label="Raccourcis"]')
    const shortcuts = [...(mobile?.querySelectorAll(':scope > ul > li > a') ?? [])].map((link) =>
      link.textContent?.trim(),
    )
    expect(shortcuts).toEqual(['Patients', 'Agenda'])
    expect(mobile?.querySelector('summary')?.textContent).toBe('Plus')
    expect(linkTexts(mobile?.querySelector('details') ?? null)).toEqual(['Paramètres'])

    unmount()
  })

  it('n’affiche pas « Plus » quand tous les modules autorisés sont des raccourcis', async () => {
    const { root, unmount } = await mountShell(['patient.read'])

    const mobile = root.querySelector('nav[aria-label="Raccourcis"]')
    expect(linkTexts(mobile)).toEqual(['Patients'])
    expect(mobile?.querySelector('details')).toBeNull()

    unmount()
  })

  it('referme « Plus » après l’ouverture d’un écran et avec Échap, focus rendu au bouton', async () => {
    const { root, router, unmount } = await mountShell(['patient.read', 'users.manage'])
    const details = root.querySelector('details') as HTMLDetailsElement
    const summary = details.querySelector('summary') as HTMLElement

    details.open = true
    await router.push('/parametres')
    await flushPromises()
    expect(details.open).toBe(false)

    details.open = true
    details
      .querySelector('a')
      ?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(details.open).toBe(false)
    expect(document.activeElement).toBe(summary)

    unmount()
  })

  it('présente les actions d’accueil livrées et autorisées', async () => {
    const { root, unmount } = await mountShell(['patient.read'])

    const actions = root.querySelector('main nav')
    expect(linkTexts(actions)).toEqual(['Rechercher'])
    expect(root.textContent).not.toContain('Aucun module métier livré')

    unmount()
  })

  it('dit qu’aucune action n’est ouverte à un compte sans droit, sans parler de livraison', async () => {
    const { root, unmount } = await mountShell(['agenda.read'])

    expect(root.querySelector('main nav')).toBeNull()
    expect(root.textContent).toContain('Aucune action ouverte à ce compte')
    expect(root.textContent).not.toContain('Aucun module métier livré')

    unmount()
  })
})

describe('coquille : clavier, focus et titre', () => {
  it('le premier lien est « Aller au contenu » et il donne le focus au contenu principal', async () => {
    const { root, unmount } = await mountShell(['patient.read'])

    const skip = root.querySelector('a') as HTMLAnchorElement
    expect(skip.textContent).toBe('Aller au contenu')
    expect(skip.getAttribute('href')).toBe('#contenu')
    skip.click()
    expect(document.activeElement?.id).toBe('contenu')

    unmount()
  })

  it('après une navigation, le contenu reçoit le focus et l’onglet prend le titre de l’écran', async () => {
    const { router, unmount } = await mountShell(['patient.read'])

    await router.push('/acces-refuse')
    await flushPromises()

    expect(document.activeElement?.id).toBe('contenu')
    expect(document.title).toBe('Accès refusé – Eagle’s Eye Optics Medical')

    unmount()
  })

  it('n’enlève pas le focus au premier affichage', async () => {
    const { unmount } = await mountShell(['patient.read'])

    expect(document.activeElement).toBe(document.body)
    expect(document.title).toBe('Accueil – Eagle’s Eye Optics Medical')

    unmount()
  })
})

describe('coquille : réseau, serveur et déconnexion', () => {
  it('annonce « Hors ligne » quand le navigateur perd le réseau, puis « En ligne »', async () => {
    const { root, unmount } = await mountShell(['patient.read'])
    const status = () => root.querySelector('header [role="status"]')?.textContent

    expect(status()).toContain('En ligne')
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    window.dispatchEvent(new Event('offline'))
    await flushPromises()
    expect(status()).toContain('Hors ligne')

    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true)
    window.dispatchEvent(new Event('online'))
    await flushPromises()
    expect(status()).toContain('En ligne')

    unmount()
  })

  it('relit la session quand le réseau revient après un démarrage serveur injoignable', async () => {
    const fetchMock = stubServer({ me: 'unreachable' })
    const { app, router } = createEeoApp(createMemoryHistory(), { navigation: CATALOG })
    await router.push('/')
    await router.isReady()
    const root = document.createElement('div')
    document.body.append(root)
    app.mount(root)
    await flushPromises()

    expect(session.status).toBe('unverified')
    expect(root.querySelector('header [role="status"]')?.textContent).toContain(
      'Serveur injoignable',
    )

    // Le réseau tombe puis revient : c'est le retour qui déclenche la relecture.
    const onLine = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    window.dispatchEvent(new Event('offline'))
    await flushPromises()
    stubServer({ me: meResponse(['patient.read']) })
    onLine.mockReturnValue(true)
    window.dispatchEvent(new Event('online'))
    await flushPromises()

    expect(session.status).toBe('authenticated')
    expect(root.querySelector('h1')?.textContent).toBe('Bonjour Gérante Fictive')
    expect(root.querySelector('header [role="status"]')?.textContent).toContain('En ligne')
    expect(fetchMock).toHaveBeenCalled()

    app.unmount()
  })

  it('réessaie la session toutes les 30 secondes tant que le serveur ne répond pas', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    stubServer({ me: 'unreachable' })
    const { app, router } = createEeoApp(createMemoryHistory(), { navigation: CATALOG })
    await router.push('/')
    await router.isReady()
    const root = document.createElement('div')
    document.body.append(root)
    app.mount(root)
    await flushPromises()
    expect(session.status).toBe('unverified')

    stubServer({ me: 'anonymous' })
    vi.advanceTimersByTime(30_000)
    await flushPromises()

    expect(session.status).toBe('anonymous')
    expect(router.currentRoute.value.name).toBe('connexion')

    app.unmount()
  })

  it('« Se déconnecter » ferme la session au serveur et ouvre la connexion', async () => {
    const { root, router, fetchMock, unmount } = await mountShell(['patient.read'])

    const button = [...root.querySelectorAll('header button')].find(
      (candidate) => candidate.textContent?.trim() === 'Se déconnecter',
    ) as HTMLButtonElement
    button.click()
    await flushPromises()

    expect(
      fetchMock.mock.calls.some(
        ([url, init]) => String(url) === '/api/v1/auth/logout' && init?.method === 'POST',
      ),
    ).toBe(true)
    expect(router.currentRoute.value.name).toBe('connexion')
    expect(root.querySelector('nav')).toBeNull()
    expect(root.querySelector('header')?.textContent).not.toContain('Gérante Fictive')

    unmount()
  })

  it('une erreur du serveur sur la session n’est ni une déconnexion ni un succès', async () => {
    stubServer({ me: 'server-error' })
    const { app, router } = createEeoApp(createMemoryHistory(), { navigation: CATALOG })
    await router.push('/')
    await router.isReady()
    const root = document.createElement('div')
    document.body.append(root)
    app.mount(root)
    await flushPromises()

    expect(session.status).toBe('unverified')
    expect(router.currentRoute.value.name).toBe('accueil')
    // Le serveur répond (en erreur) : l'en-tête ne dit pas « injoignable », l'accueil dit la vérité.
    expect(root.querySelector('header [role="status"]')?.textContent).toContain('En ligne')
    expect(root.querySelector('main')?.textContent).toContain('Session non vérifiée')
    expect(root.querySelector('main')?.textContent).toContain('a répondu par une erreur')
    expect(root.querySelector('nav')).toBeNull()

    app.unmount()
  })

  it('n’ouvre aucun écran protégé tant que les droits ne sont pas vérifiés', async () => {
    stubServer({ me: 'unreachable' })
    const { app, router } = createEeoApp(createMemoryHistory(), { navigation: CATALOG })
    router.addRoute({
      path: '/reserve',
      name: 'reserve',
      component: Screen('Réservé'),
      meta: { permissions: ['patient.read'] },
    })
    await router.push('/reserve')
    await router.isReady()

    expect(session.status).toBe('unverified')
    expect(router.currentRoute.value.name).toBe('accueil')
    app.unmount()
  })

  it('sans réponse du serveur, la déconnexion n’est pas prétendue : la session reste affichée', async () => {
    const { root, router, unmount } = await mountShell(['patient.read'], '/', 'unreachable')

    const button = [...root.querySelectorAll('header button')].find(
      (candidate) => candidate.textContent?.trim() === 'Se déconnecter',
    ) as HTMLButtonElement
    button.click()
    await flushPromises()

    expect(session.status).toBe('authenticated')
    expect(router.currentRoute.value.name).toBe('accueil')
    expect(root.querySelector('header')?.textContent).toContain('Gérante Fictive')
    expect(root.querySelector('main [role="alert"]')?.textContent).toContain(
      'La session reste ouverte sur ce poste',
    )

    unmount()
  })

  it('une session déjà expirée au serveur (401) est bien fermée', async () => {
    const { router, unmount } = await mountShell(['patient.read'], '/', {
      status: 401,
      code: 'AUTH_REQUIRED',
      message: 'Connexion requise.',
    })

    const button = [...document.querySelectorAll('header button')].find(
      (candidate) => candidate.textContent?.trim() === 'Se déconnecter',
    ) as HTMLButtonElement
    button.click()
    await flushPromises()

    expect(session.status).toBe('anonymous')
    expect(router.currentRoute.value.name).toBe('connexion')
    unmount()
  })
})
