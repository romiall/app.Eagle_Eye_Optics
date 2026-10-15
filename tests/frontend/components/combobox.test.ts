import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import ComboboxField, { type ComboboxOption } from '@/components/form/ComboboxField.vue'

// Catalogue FICTIF : aucune donnée réelle, aucun prix réel.
const CATALOG: ComboboxOption[] = [
  { value: 'ART-0001', label: 'Examen de vue', description: 'TEST' },
  { value: 'ART-0002', label: 'Monture TEST A' },
  { value: 'ART-0003', label: 'Monture TEST B' },
]

function searchCatalog(query: string): Promise<readonly ComboboxOption[]> {
  const needle = query.toLowerCase()
  return Promise.resolve(CATALOG.filter((option) => option.label.toLowerCase().includes(needle)))
}

const wrappers: VueWrapper[] = []

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()?.unmount()
  vi.useRealTimers()
})

function mountCombobox(search = searchCatalog) {
  const wrapper = mount(ComboboxField, {
    props: { label: 'Article', modelValue: null, search },
    attachTo: document.body,
  })
  wrappers.push(wrapper)
  return wrapper
}

async function typeQuery(wrapper: VueWrapper, text: string): Promise<void> {
  await wrapper.get('input').setValue(text)
  vi.advanceTimersByTime(200)
  await flushPromises()
}

function key(wrapper: VueWrapper, name: string) {
  return wrapper.get('input').trigger('keydown', { key: name })
}

describe('ComboboxField : sélection au clavier (fichier 04)', () => {
  it('suit le motif combobox ARIA : rôle, liste contrôlée, état replié', () => {
    const wrapper = mountCombobox()
    const input = wrapper.get('input')

    expect(input.attributes('role')).toBe('combobox')
    expect(input.attributes('aria-autocomplete')).toBe('list')
    expect(input.attributes('aria-expanded')).toBe('false')
    expect(
      wrapper.find(`[id="${input.attributes('aria-controls') ?? ''}"]`).attributes('role'),
    ).toBe('listbox')
    expect(wrapper.get('label').attributes('for')).toBe(input.attributes('id'))
  })

  it('attend deux caractères avant de chercher', async () => {
    const search = vi.fn(searchCatalog)
    const wrapper = mountCombobox(search)

    await typeQuery(wrapper, 'M')

    expect(search).not.toHaveBeenCalled()
    expect(wrapper.get('[role="status"]').text()).toBe('Saisissez au moins 2 caractères.')
  })

  it('parcourt les résultats avec les flèches et choisit avec Entrée', async () => {
    const wrapper = mountCombobox()
    await typeQuery(wrapper, 'mon')
    const input = wrapper.get('input')

    expect(input.attributes('aria-expanded')).toBe('true')
    expect(wrapper.findAll('[role="option"]').map((option) => option.text())).toEqual([
      'Monture TEST A',
      'Monture TEST B',
    ])
    expect(wrapper.get('[role="status"]').text()).toContain('2 résultats')
    expect(input.attributes('aria-activedescendant')).toBeUndefined()

    await key(wrapper, 'ArrowDown')
    await key(wrapper, 'ArrowDown')
    const active = input.attributes('aria-activedescendant') ?? ''
    expect(wrapper.get(`[id="${active}"]`).text()).toBe('Monture TEST B')
    expect(wrapper.get(`[id="${active}"]`).attributes('aria-selected')).toBe('true')

    await key(wrapper, 'ArrowDown')
    expect(wrapper.get(`[id="${input.attributes('aria-activedescendant') ?? ''}"]`).text()).toBe(
      'Monture TEST A',
    )
    await key(wrapper, 'ArrowUp')
    await key(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')).toEqual([['ART-0003']])
    expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ value: 'ART-0003' })
    expect((input.element as HTMLInputElement).value).toBe('Monture TEST B')
    expect(input.attributes('aria-expanded')).toBe('false')
  })

  it('ne choisit rien à la place de l’utilisateur : Entrée sans résultat actif ne sélectionne pas', async () => {
    const wrapper = mountCombobox()
    await typeQuery(wrapper, 'examen')

    await key(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('Échap referme la liste sans effacer la saisie', async () => {
    const wrapper = mountCombobox()
    await typeQuery(wrapper, 'mon')

    await key(wrapper, 'Escape')

    expect(wrapper.get('input').attributes('aria-expanded')).toBe('false')
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('mon')
  })

  it('choisit à la souris sans perdre le focus du champ', async () => {
    const wrapper = mountCombobox()
    await typeQuery(wrapper, 'examen')

    const option = wrapper.get('[role="option"]')
    const mousedown = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    option.element.dispatchEvent(mousedown)
    expect(mousedown.defaultPrevented).toBe(true)
    await option.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['ART-0001']])
  })

  it('annule le choix quand l’utilisateur modifie le texte', async () => {
    const wrapper = mountCombobox()
    await wrapper.setProps({ modelValue: 'ART-0002', selectedLabel: 'Monture TEST A' })
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('Monture TEST A')

    await typeQuery(wrapper, 'Monture TEST')

    expect(wrapper.emitted('update:modelValue')).toEqual([[null]])
    expect(wrapper.emitted('select')).toEqual([[null]])
  })

  it('distingue « aucun résultat » de « recherche indisponible »', async () => {
    const wrapper = mountCombobox()
    await typeQuery(wrapper, 'zz')
    expect(wrapper.get('[role="status"]').text()).toBe('Aucun résultat pour « zz ».')

    const offline = mountCombobox(() => Promise.reject(new Error('Serveur injoignable')))
    await typeQuery(offline, 'monture')
    const status = offline.get('[role="status"]').text()
    expect(status).toContain('Recherche indisponible')
    expect(status).not.toContain('Aucun résultat')
  })

  it('ignore une réponse arrivée après une saisie plus récente', async () => {
    const resolvers: ((options: readonly ComboboxOption[]) => void)[] = []
    const wrapper = mountCombobox(
      () => new Promise<readonly ComboboxOption[]>((resolve) => resolvers.push(resolve)),
    )

    await typeQuery(wrapper, 'mon')
    await typeQuery(wrapper, 'exa')
    resolvers[1]?.([CATALOG[0] as ComboboxOption])
    await flushPromises()
    resolvers[0]?.([CATALOG[1] as ComboboxOption, CATALOG[2] as ComboboxOption])
    await flushPromises()

    expect(wrapper.findAll('[role="option"]').map((option) => option.get('span').text())).toEqual([
      'Examen de vue',
    ])
  })
})
