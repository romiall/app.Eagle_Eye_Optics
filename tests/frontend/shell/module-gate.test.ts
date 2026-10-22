import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it } from 'vitest'

import { createEeoApp } from '@/app/bootstrap'
import type { AppModule, NavigationCatalog } from '@/app/navigation'
import { resetNetworkForTests } from '@/app/network'
import { resetSessionForTests, session } from '@/app/session'

import { meResponse, stubServer } from './server'

/**
 * Mise en service progressive : un module livré dans le code reste invisible (menu, accueil, route)
 * tant que l'exploitant ne l'a pas ouvert (`clinic.enabled_modules` de `/auth/me`). Données fictives.
 */
function module(id: string, label: string, permissions: string[]): AppModule {
  return {
    id,
    label,
    path: `/${id}`,
    permissions,
    deliveredBy: 'P01',
    delivered: true,
    mobileShortcut: false,
  }
}

const CATALOG: NavigationCatalog = {
  modules: [
    module('patients', 'Patients', ['patient.read']),
    module('sales', 'Ventes et caisse', ['invoice.read']),
  ],
  homeActions: [
    {
      id: 'new-patient',
      label: 'Nouveau patient',
      path: '/patients/nouveau',
      permissions: ['patient.create'],
      deliveredBy: 'P01',
      delivered: true,
      module: 'patients',
    },
  ],
}

const Screen = (title: string) => defineComponent({ render: () => h('h1', title) })

async function mountShell(enabled: string[], path = '/') {
  stubServer({
    me: meResponse(['patient.read', 'patient.create', 'invoice.read'], 'Gérante', enabled),
  })
  const { app, router } = createEeoApp(createMemoryHistory(), { navigation: CATALOG })
  for (const entry of CATALOG.modules) {
    router.addRoute({
      path: entry.path,
      name: entry.id,
      component: Screen(entry.label),
      meta: { module: entry.id, permissions: entry.permissions },
    })
  }
  await router.push(path)
  await router.isReady()
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  await flushPromises()
  return { root, router, app }
}

function links(root: Element): string[] {
  return [...root.querySelectorAll('a')].map((link) => link.textContent?.trim() ?? '')
}

afterEach(() => {
  resetSessionForTests()
  resetNetworkForTests()
  document.body.innerHTML = ''
})

describe('mise en service progressive dans la coquille', () => {
  it('sans module ouvert : ni menu ni action, même livrés et autorisés', async () => {
    const { root, app } = await mountShell([])

    expect(session.account?.clinic.enabled_modules).toEqual([])
    expect(root.querySelector('nav[aria-label="Navigation principale"]')).toBeNull()
    expect(links(root)).not.toContain('Patients')
    expect(links(root)).not.toContain('Nouveau patient')
    app.unmount()
  })

  it('un seul module ouvert : son menu et ses actions apparaissent, pas les autres', async () => {
    const { root, app } = await mountShell(['patients'])

    expect(links(root)).toContain('Patients')
    expect(links(root)).toContain('Nouveau patient')
    expect(links(root)).not.toContain('Ventes et caisse')
    app.unmount()
  })

  it('une route d’un module fermé répond « page introuvable », comme le serveur (404)', async () => {
    const { root, router, app } = await mountShell(['patients'], '/')

    await router.push('/sales')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('introuvable')
    expect(root.textContent).toContain('Page introuvable')
    expect(root.textContent).not.toContain('Ventes et caisse')
    app.unmount()
  })

  it('une route d’un module ouvert s’affiche', async () => {
    const { root, router, app } = await mountShell(['patients', 'sales'], '/')

    await router.push('/sales')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('sales')
    expect(root.querySelector('main h1')?.textContent).toBe('Ventes et caisse')
    app.unmount()
  })
})
