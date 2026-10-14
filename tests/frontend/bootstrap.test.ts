import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory } from 'vue-router'
import { describe, expect, it } from 'vitest'

import { createEeoApp } from '@/app/bootstrap'

async function mountAt(path: string) {
  const { app, router } = createEeoApp(createMemoryHistory())
  await router.push(path)
  await router.isReady()

  const root = document.createElement('div')
  document.body.append(root)
  const vm = app.mount(root)
  await flushPromises()

  return { root, vm, unmount: () => app.unmount() }
}

describe('amorçage de la coquille Vue', () => {
  it('monte la page d’accueil en français avec un titre de niveau 1', async () => {
    const { root, unmount } = await mountAt('/')

    const heading = root.querySelector('h1')
    expect(heading?.textContent).toBe('Application du cabinet')
    expect(root.textContent).toContain('Eagle’s Eye Optics Medical')
    expect(root.querySelector('main')).not.toBeNull()
    expect(root.querySelector('header')).not.toBeNull()

    unmount()
  })

  it('affiche une page « introuvable » en français pour toute URL inconnue du routeur', async () => {
    const { root, unmount } = await mountAt('/une/url/inconnue')

    expect(root.querySelector('h1')?.textContent).toBe('Page introuvable')
    expect(root.querySelector('a')?.getAttribute('href')).toBe('/')

    unmount()
  })

  it('ne déclare aucune route métier dans cette tâche', () => {
    const { router } = createEeoApp(createMemoryHistory())

    expect(router.getRoutes().map((route) => route.name)).toEqual(['accueil', 'introuvable'])
  })
})
