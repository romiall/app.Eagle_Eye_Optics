import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { resetSessionForTests } from '@/app/session'
import { type DeviceSyncSummary, SYNC_STATE_KEY, type SyncStateSource } from '@/app/services/sync'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import SyncBadge from '@/components/feedback/SyncBadge.vue'
import ReauthDialog from '@/components/session/ReauthDialog.vue'
import SyncStatus from '@/components/SyncStatus.vue'

import { meResponse, stubServer } from '../shell/server'

const IDLE: DeviceSyncSummary = {
  local: 0,
  pending: 0,
  waitingDependency: 0,
  conflicts: 0,
  documentsFailed: 0,
  lastExchangeAt: '2026-10-15T09:42:00.000Z',
  lastAttemptFailed: false,
}

function mountStatus(summary: DeviceSyncSummary | null, syncNow?: () => Promise<void>) {
  const source: SyncStateSource = {
    summary: ref(summary),
    ...(syncNow === undefined ? {} : { syncNow }),
  }
  return mount(SyncStatus, { global: { provide: { [SYNC_STATE_KEY as symbol]: source } } })
}

afterEach(() => {
  resetSessionForTests()
  document.body.innerHTML = ''
})

describe('SyncStatus : état de synchronisation du poste, distinct du réseau', () => {
  it('n’affiche rien sans poste préparé (aucune pièce locale à synchroniser)', () => {
    expect(mount(SyncStatus).html()).toBe('<!--v-if-->')
    expect(mountStatus(null).html()).toBe('<!--v-if-->')
  })

  it('dit « Synchronisé » seulement si rien n’attend et qu’un échange a réussi', () => {
    const wrapper = mountStatus(IDLE)
    expect(wrapper.get('[role="status"]').text()).toContain('Synchronisé le 15/10 à 10h42')
    expect(wrapper.text()).not.toContain('En ligne')

    expect(mountStatus({ ...IDLE, lastExchangeAt: null }).text()).toContain('Jamais synchronisé')
  })

  it('annonce pièces en attente, conflits et PDF à régénérer, jamais « Synchronisé » alors', () => {
    const wrapper = mountStatus({
      ...IDLE,
      local: 1,
      pending: 2,
      waitingDependency: 1,
      conflicts: 1,
      documentsFailed: 2,
      lastAttemptFailed: true,
    })
    const text = wrapper.text()

    expect(text).toContain('1 conflit à revoir')
    expect(text).toContain('4 pièces en attente de synchronisation')
    expect(text).toContain('2 PDF à régénérer')
    expect(text).toContain('Dernier échange le 15/10 à 10h42')
    expect(text).toContain('Dernier essai d’échange sans réponse du serveur')
    expect(text).not.toContain('Synchronisé le')
  })

  it('propose « Synchroniser » quand le poste le permet', async () => {
    const syncNow = vi.fn(async () => undefined)
    const wrapper = mountStatus(IDLE, syncNow)
    await wrapper.get('button').trigger('click')
    expect(syncNow).toHaveBeenCalledOnce()
  })
})

describe('SyncBadge : émis localement ou consolidé, et état du PDF', () => {
  it.each([
    ['local', 'Enregistré sur ce poste'],
    ['pending', 'En attente de synchronisation'],
    ['conflict', 'Conflit à revoir'],
    ['consolidated', 'Consolidé sur le serveur'],
    ['rejected', 'Rejeté après revue'],
  ] as const)('l’état %s se lit « %s », avec un symbole', (state, label) => {
    const wrapper = mount(SyncBadge, { props: { state } })
    expect(wrapper.text()).toContain(label)
    expect(wrapper.find('[aria-hidden="true"]').text()).not.toBe('')
  })

  it('montre l’état du PDF local à côté de celui de la pièce', () => {
    const wrapper = mount(SyncBadge, { props: { state: 'local', document: 'failed' } })
    expect(wrapper.text()).toContain('PDF à régénérer')
  })
})

describe('FreshnessNote : fraîcheur distante (AT45)', () => {
  it('donne l’heure du serveur à l’heure du cabinet', () => {
    const wrapper = mount(FreshnessNote, {
      props: { freshness: { serverAsOf: '2026-10-15T09:42:00.000Z', complete: true } },
    })
    expect(wrapper.text()).toBe('·Données du serveur au 15/10/2026 à 10h42.')
  })

  it('marque les totaux distants incomplets', () => {
    const wrapper = mount(FreshnessNote, {
      props: { freshness: { serverAsOf: '2026-10-15T09:42:00.000Z', complete: false } },
    })
    expect(wrapper.text()).toContain('Totaux distants incomplets')
  })

  it('dit quand seules les données du poste sont affichées', () => {
    const wrapper = mount(FreshnessNote, {
      props: { freshness: { serverAsOf: null, complete: false } },
    })
    expect(wrapper.text()).toContain('Données de ce poste seulement')
  })
})

describe('ReauthDialog : reconnexion sur place, même compte, saisie conservée', () => {
  it('reconnecte le même compte puis rend la main à l’écran', async () => {
    const fetchMock = stubServer({ me: 'anonymous', login: meResponse(['payment.collect']) })
    const wrapper = mount(ReauthDialog, {
      props: { open: true, login: 'demo.caisse', displayName: 'Caisse Fictive' },
      attachTo: document.body,
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Vos saisies sont conservées')
    expect(wrapper.text()).toContain('Caisse Fictive')
    await wrapper.get('input[type="password"]').setValue('mot-de-passe-fictif')
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Se reconnecter')
      ?.trigger('click')
    await flushPromises()

    const login = fetchMock.mock.calls.find(([url]) => String(url) === '/api/v1/auth/login')
    expect(JSON.parse(String(login?.[1]?.body))).toEqual({
      login: 'demo.caisse',
      password: 'mot-de-passe-fictif',
    })
    expect(wrapper.emitted('reauthenticated')).toHaveLength(1)
    wrapper.unmount()
  })

  it('un mot de passe refusé laisse la boîte ouverte avec le message, sans rien envoyer d’autre', async () => {
    stubServer({
      me: 'anonymous',
      login: {
        status: 422,
        code: 'VALIDATION_FAILED',
        message: 'Identifiant ou mot de passe incorrect.',
        fields: { login: ['Identifiant ou mot de passe incorrect.'] },
      },
    })
    const wrapper = mount(ReauthDialog, {
      props: { open: true, login: 'demo.caisse', displayName: 'Caisse Fictive' },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.get('input[type="password"]').setValue('faux')
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Se reconnecter')
      ?.trigger('click')
    await flushPromises()

    expect(wrapper.emitted('reauthenticated')).toBeUndefined()
    expect(wrapper.text()).toContain('Identifiant ou mot de passe incorrect.')
    expect((wrapper.get('input[type="password"]').element as HTMLInputElement).value).toBe('')
    wrapper.unmount()
  })
})
