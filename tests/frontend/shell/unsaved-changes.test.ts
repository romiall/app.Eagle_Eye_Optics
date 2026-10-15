import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

import { UNSAVED_CHANGES_MESSAGE, useUnsavedChangesGuard } from '@/app/unsavedChanges'

const dirty = ref(false)
const confirmLeave = vi.fn<(message: string) => boolean>(() => false)

const Editor = defineComponent({
  setup() {
    useUnsavedChangesGuard(dirty, confirmLeave)
    return () => h('h1', 'Nouveau patient')
  },
})

async function setup() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/saisie', component: Editor },
      { path: '/ailleurs', component: { render: () => h('h1', 'Ailleurs') } },
    ],
  })
  await router.push('/saisie')
  await router.isReady()
  const wrapper = mount({ render: () => h(RouterView) }, { global: { plugins: [router] } })
  await flushPromises()
  return { router, wrapper }
}

describe('saisies non enregistrées (fichier 04)', () => {
  it('laisse partir sans question quand tout est enregistré', async () => {
    dirty.value = false
    confirmLeave.mockClear()
    const { router, wrapper } = await setup()

    await router.push('/ailleurs')

    expect(confirmLeave).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/ailleurs')
    wrapper.unmount()
  })

  it('demande confirmation et reste sur l’écran si l’utilisateur refuse', async () => {
    dirty.value = true
    confirmLeave.mockClear().mockReturnValue(false)
    const { router, wrapper } = await setup()

    await router.push('/ailleurs')

    expect(confirmLeave).toHaveBeenCalledWith(UNSAVED_CHANGES_MESSAGE)
    expect(router.currentRoute.value.path).toBe('/saisie')

    confirmLeave.mockReturnValue(true)
    await router.push('/ailleurs')
    expect(router.currentRoute.value.path).toBe('/ailleurs')
    wrapper.unmount()
  })

  it('avertit le navigateur à la fermeture de l’onglet tant que la saisie n’est pas enregistrée', async () => {
    dirty.value = true
    const { wrapper } = await setup()

    const leaving = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(leaving)
    expect(leaving.defaultPrevented).toBe(true)

    dirty.value = false
    const saved = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(saved)
    expect(saved.defaultPrevented).toBe(false)

    wrapper.unmount()
    dirty.value = true
    const afterUnmount = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(afterUnmount)
    expect(afterUnmount.defaultPrevented).toBe(false)
  })
})
