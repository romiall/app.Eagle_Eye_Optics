/* eslint-disable vue/one-component-per-file -- composants hôtes propres à chaque test */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import ErrorSummary from '@/components/feedback/ErrorSummary.vue'
import SaveStatus from '@/components/feedback/SaveStatus.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'

const wrappers: VueWrapper[] = []
function attached<T extends VueWrapper>(wrapper: T): T {
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()?.unmount()
  document.body.innerHTML = ''
})

describe('AppButton', () => {
  it('se désactive pendant la commande et dit ce qu’il fait', async () => {
    const wrapper = attached(
      mount(AppButton, {
        props: { pending: false, pendingLabel: 'Enregistrement du paiement…' },
        slots: { default: 'Enregistrer le paiement' },
      }),
    )
    expect(wrapper.text()).toBe('Enregistrer le paiement')
    expect(wrapper.attributes('type')).toBe('button')

    await wrapper.setProps({ pending: true })
    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toBe('Enregistrement du paiement…')
    expect(wrapper.classes().some((name) => name.includes('opacity'))).toBe(false)
  })

  it('a une cible tactile d’au moins 44 px', () => {
    const wrapper = attached(mount(AppButton, { slots: { default: 'Émettre la facture' } }))
    expect(wrapper.classes()).toContain('min-h-11')
  })
})

describe('ErrorSummary', () => {
  const ErrorForm = defineComponent({
    setup() {
      const errors = ref<{ fieldId: string; message: string }[]>([])
      return { errors }
    },
    render() {
      return h('div', [
        h(ErrorSummary, { errors: this.errors }),
        h('label', { for: 'montant' }, 'Montant'),
        h('input', { id: 'montant' }),
      ])
    },
  })

  it('prend le focus quand des erreurs apparaissent et mène à chaque champ', async () => {
    const wrapper = attached(mount(ErrorForm, { attachTo: document.body }))
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    ;(wrapper.vm as unknown as { errors: unknown[] }).errors = [
      { fieldId: 'montant', message: 'Le montant dépasse le reste dû (12 000 FCFA).' },
    ]
    await flushPromises()

    const summary = wrapper.get('[role="alert"]')
    expect(document.activeElement).toBe(summary.element)
    expect(summary.attributes('aria-labelledby')).toBe(summary.get('h2').attributes('id'))
    expect(summary.text()).toContain('Certaines informations sont à corriger')

    await summary.get('a').trigger('click')
    expect(document.activeElement).toBe(wrapper.get('#montant').element)
  })

  it('reprend le focus quand les erreurs changent, et sur demande quand elles se répètent', async () => {
    const wrapper = attached(
      mount(ErrorSummary, {
        props: { errors: [{ fieldId: 'a', message: 'Premier' }] },
        attachTo: document.body,
      }),
    )
    await flushPromises()
    const summary = wrapper.get('[role="alert"]').element as HTMLElement
    expect(document.activeElement).toBe(summary)

    summary.blur()
    await wrapper.setProps({ errors: [{ fieldId: 'a', message: 'Second' }] })
    await flushPromises()
    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)

    ;(document.activeElement as HTMLElement).blur()
    await (wrapper.vm as unknown as { focus: () => Promise<void> }).focus()
    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)
  })
})

describe('ConfirmDialog', () => {
  const Host = defineComponent({
    props: { tone: { type: String as () => 'default' | 'danger', default: 'default' } },
    emits: ['confirmed'],
    setup(props, { emit }) {
      const open = ref(false)
      const pending = ref(false)
      return { open, pending, props, emit }
    },
    render() {
      return h('div', [
        h('button', { id: 'declencheur', onClick: () => (this.open = true) }, 'Émettre'),
        h(
          ConfirmDialog,
          {
            open: this.open,
            pending: this.pending,
            title: 'Émettre la facture ?',
            confirmLabel: 'Émettre la facture',
            tone: this.props.tone,
            onConfirm: () => this.emit('confirmed'),
            onCancel: () => (this.open = false),
          },
          () => 'Une facture émise ne se modifie plus : seule une pièce liée la corrige.',
        ),
      ])
    },
  })

  async function openFrom(wrapper: VueWrapper): Promise<HTMLButtonElement> {
    const trigger = wrapper.get('#declencheur').element as HTMLButtonElement
    trigger.focus()
    trigger.click()
    await flushPromises()
    await nextTick()
    return trigger
  }

  it('s’ouvre en modale avec titre et texte annoncés, focus sur l’action', async () => {
    const showModal = vi.fn(function (this: HTMLDialogElement) {
      this.setAttribute('open', '')
    })
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {
      value: showModal,
      configurable: true,
    })
    try {
      const wrapper = attached(mount(Host, { attachTo: document.body }))
      await openFrom(wrapper)

      const dialog = wrapper.get('dialog')
      expect(showModal).toHaveBeenCalledOnce()
      expect(wrapper.get(`[id="${dialog.attributes('aria-labelledby') ?? ''}"]`).text()).toBe(
        'Émettre la facture ?',
      )
      expect(wrapper.get(`[id="${dialog.attributes('aria-describedby') ?? ''}"]`).text()).toContain(
        'Une facture émise ne se modifie plus',
      )
      expect(document.activeElement?.textContent?.trim()).toBe('Émettre la facture')
    } finally {
      Reflect.deleteProperty(HTMLDialogElement.prototype, 'showModal')
    }
  })

  it('focalise « Annuler » pour une action risquée et rend le focus au déclencheur', async () => {
    const wrapper = attached(mount(Host, { props: { tone: 'danger' }, attachTo: document.body }))
    const trigger = await openFrom(wrapper)

    expect(document.activeElement?.textContent?.trim()).toBe('Annuler')
    ;(document.activeElement as HTMLButtonElement).click()
    await flushPromises()

    expect(wrapper.get('dialog').attributes('open')).toBeUndefined()
    expect(document.activeElement).toBe(trigger)
  })

  it('Échap annule par le parent, sauf pendant la commande', async () => {
    const wrapper = attached(mount(Host, { attachTo: document.body }))
    await openFrom(wrapper)
    const vm = wrapper.vm as unknown as { open: boolean; pending: boolean }

    vm.pending = true
    await flushPromises()
    const blocked = new Event('cancel', { cancelable: true })
    wrapper.get('dialog').element.dispatchEvent(blocked)
    await flushPromises()
    expect(blocked.defaultPrevented).toBe(true)
    expect(vm.open).toBe(true)

    vm.pending = false
    await flushPromises()
    const escape = new Event('cancel', { cancelable: true })
    wrapper.get('dialog').element.dispatchEvent(escape)
    await flushPromises()
    expect(escape.defaultPrevented).toBe(true)
    expect(vm.open).toBe(false)
  })

  it('prévient le parent si le navigateur ferme la boîte de lui-même', async () => {
    const wrapper = attached(mount(Host, { attachTo: document.body }))
    await openFrom(wrapper)

    const dialog = wrapper.get('dialog').element as HTMLDialogElement
    dialog.removeAttribute('open')
    dialog.dispatchEvent(new Event('close'))
    await flushPromises()

    expect((wrapper.vm as unknown as { open: boolean }).open).toBe(false)
  })

  it('confirme par le bouton qui dit la conséquence', async () => {
    const wrapper = attached(mount(Host, { attachTo: document.body }))
    await openFrom(wrapper)

    const confirm = wrapper
      .findAll('dialog button')
      .find((button) => button.text() === 'Émettre la facture')
    await confirm?.trigger('click')

    expect(wrapper.emitted('confirmed')).toHaveLength(1)
  })
})

describe('SaveStatus', () => {
  it('dit « Enregistré sur ce poste à 10h42 » à l’heure du cabinet (Africa/Douala)', () => {
    const wrapper = attached(
      mount(SaveStatus, { props: { state: 'saved-local', at: '2026-10-15T09:42:00.000Z' } }),
    )

    expect(wrapper.attributes('role')).toBe('status')
    expect(wrapper.attributes('aria-live')).toBe('polite')
    expect(wrapper.get('[aria-hidden="true"]').text()).toBe('✓')
    expect(wrapper.get('span:not([aria-hidden])').text()).toBe('Enregistré sur ce poste à 10h42')
  })

  it('distingue synchronisé et échec, sans rien annoncer au repos', async () => {
    const wrapper = attached(mount(SaveStatus, { props: { state: 'idle' } }))
    expect(wrapper.text()).toBe('')

    await wrapper.setProps({ state: 'synced' })
    expect(wrapper.text()).toContain('Synchronisé avec le serveur')

    await wrapper.setProps({ state: 'failed' })
    expect(wrapper.text()).toContain('Échec de l’enregistrement')
    expect(wrapper.text()).toContain('vos saisies restent affichées')
  })
})

describe('StateMessage', () => {
  it.each([
    ['loading', 'status'],
    ['empty', 'status'],
    ['success', 'status'],
    ['offline', 'status'],
    ['forbidden', 'status'],
    ['error', 'alert'],
    ['conflict', 'alert'],
  ] as const)('l’état %s a le rôle %s, un symbole et un titre', (state, role) => {
    const wrapper = attached(
      mount(StateMessage, {
        props: { state, title: 'Titre de l’état' },
        slots: { default: 'Texte' },
      }),
    )

    expect(wrapper.attributes('role')).toBe(role)
    expect(wrapper.find('[aria-hidden="true"]').text()).not.toBe('')
    expect(wrapper.text()).toContain('Titre de l’état')
    expect(wrapper.text()).toContain('Texte')
  })

  it('marque le chargement comme occupé', () => {
    const wrapper = attached(
      mount(StateMessage, { props: { state: 'loading', title: 'Chargement' } }),
    )
    expect(wrapper.attributes('aria-busy')).toBe('true')
  })
})
