import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { createEeoApp } from '@/app/bootstrap'
import { resetNetworkForTests } from '@/app/network'
import {
  refreshSession,
  resetSessionForTests,
  revalidateSession,
  session,
  signIn,
  signOut,
} from '@/app/session'

import { meResponse, type ServerScript, stubServer } from './server'

/**
 * Cycle de vie de la session (deuxième revue de F10) : une session ouverte n'est jamais dégradée par
 * une panne de réseau ou une erreur du serveur ; une réponse 401 la rend « expirée » sans quitter
 * l'écran ni effacer les saisies, et la reconnexion se fait sur place. Données fictives.
 */
const OTHER_ACCOUNT = '01J9ZXK4M2Q8R7S6T5V4W30009'

function otherAccount() {
  return { data: { ...meResponse(['payment.collect']).data, id: OTHER_ACCOUNT } }
}

async function mountShell(script: ServerScript) {
  const fetchMock = stubServer(script)
  const { app, router } = createEeoApp(createMemoryHistory())
  await router.push('/')
  await router.isReady()
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  await flushPromises()
  return { root, router, fetchMock, app }
}

/** Retour sur l'onglet : focus de la fenêtre, onglet visible. */
async function returnToTab(): Promise<void> {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' })
  window.dispatchEvent(new Event('focus'))
  await flushPromises()
}

function headerButton(root: Element, label: string): HTMLButtonElement | undefined {
  return [...root.querySelectorAll('header button')].find(
    (button) => button.textContent?.trim() === label,
  ) as HTMLButtonElement | undefined
}

afterEach(() => {
  resetSessionForTests()
  resetNetworkForTests()
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe('relecture d’une session ouverte (retour sur l’onglet)', () => {
  it.each([
    ['réseau coupé', 'unreachable'],
    ['erreur du serveur', 'server-error'],
    ['page HTML d’un hébergeur', 'html-error'],
  ] as const)(
    '%s : la session reste ouverte, le compte et la navigation aussi',
    async (_name, failure) => {
      const script: ServerScript = { me: meResponse(['patient.read']) }
      const { root } = await mountShell(script)
      expect(session.status).toBe('authenticated')

      script.me = failure
      await returnToTab()

      expect(session.status).toBe('authenticated')
      expect(session.account?.display_name).toBe('Gérante Fictive')
      expect(headerButton(root, 'Se déconnecter')).toBeDefined()
      expect(root.textContent).not.toContain('Session non vérifiée')
      expect(root.textContent).not.toContain('Session expirée')
    },
  )

  it('401 : la session est « expirée », l’écran reste en place avec sa bannière', async () => {
    const script: ServerScript = { me: meResponse(['patient.read']) }
    const { root, router } = await mountShell(script)

    script.me = 'anonymous'
    await returnToTab()

    expect(session.status).toBe('expired')
    expect(session.account).toBeNull()
    expect(session.lastAccount?.display_name).toBe('Gérante Fictive')
    // Aucune navigation : l'écran et ses saisies restent.
    expect(router.currentRoute.value.path).toBe('/')
    const banners = [...root.querySelectorAll('header [role="status"]')]
    expect(banners.map((banner) => banner.textContent).join(' ')).toContain('Session expirée')
    expect(headerButton(root, 'Se reconnecter')).toBeDefined()
    // L'accueil dit la vérité : ce n'est pas un manque de droits.
    expect(root.textContent).toContain('Votre session a pris fin')
    expect(root.textContent).not.toContain('Demandez à la gérante')
  })

  it('« Se reconnecter » ouvre la boîte sur le champ du mot de passe, puis rétablit la session', async () => {
    const script: ServerScript = {
      me: meResponse(['patient.read']),
      login: meResponse(['patient.read']),
    }
    const { root, router } = await mountShell(script)
    script.me = 'anonymous'
    await returnToTab()

    headerButton(root, 'Se reconnecter')?.click()
    await flushPromises()

    const field = root.ownerDocument.querySelector<HTMLInputElement>('input[type="password"]')
    expect(field).not.toBeNull()
    expect(document.activeElement).toBe(field)

    field!.value = 'mot-de-passe-fictif'
    field!.dispatchEvent(new Event('input'))
    const confirm = [...document.querySelectorAll('dialog button')].find(
      (button) => button.textContent?.trim() === 'Se reconnecter',
    ) as HTMLButtonElement
    confirm.click()
    await flushPromises()

    expect(session.status).toBe('authenticated')
    expect(router.currentRoute.value.path).toBe('/')
    expect(document.querySelector('input[type="password"]')).toBeNull()
    expect(headerButton(root, 'Se reconnecter')).toBeUndefined()
    expect(headerButton(root, 'Se déconnecter')).toBeDefined()
  })

  it('un mot de passe refusé laisse la boîte ouverte, le focus revient sur le champ', async () => {
    const script: ServerScript = {
      me: meResponse(['patient.read']),
      login: {
        status: 422,
        code: 'VALIDATION_FAILED',
        message: 'Identifiant ou mot de passe incorrect.',
        fields: { login: ['Identifiant ou mot de passe incorrect.'] },
      },
    }
    const { root } = await mountShell(script)
    script.me = 'anonymous'
    await returnToTab()
    headerButton(root, 'Se reconnecter')?.click()
    await flushPromises()

    const field = document.querySelector<HTMLInputElement>('input[type="password"]')!
    field.value = 'faux'
    field.dispatchEvent(new Event('input'))
    ;(
      [...document.querySelectorAll('dialog button')].find(
        (button) => button.textContent?.trim() === 'Se reconnecter',
      ) as HTMLButtonElement
    ).click()
    await flushPromises()

    expect(session.status).toBe('expired')
    expect(document.activeElement).toBe(field)
    expect(field.getAttribute('aria-invalid')).toBe('true')
    const described = field.getAttribute('aria-describedby') ?? ''
    expect(document.getElementById(described)?.textContent).toContain(
      'Identifiant ou mot de passe incorrect.',
    )
  })
})

describe('session expirée : navigation et relecture périodique', () => {
  it('une navigation vers un autre écran protégé ouvre la connexion avec le chemin de retour', async () => {
    const script: ServerScript = { me: meResponse(['patient.read']) }
    const { router } = await mountShell(script)
    script.me = 'anonymous'
    await returnToTab()
    expect(session.status).toBe('expired')

    await router.push('/acces-refuse')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('connexion')
    expect(router.currentRoute.value.query['suite']).toBe('/acces-refuse')
  })

  it('une session non vérifiée qui se révèle expirée à la relecture ne chasse pas de l’écran', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const script: ServerScript = { me: meResponse(['patient.read']) }
    const { router, app } = await mountShell(script)
    // Un envoi dont la relecture a échoué range la session en « non vérifiée ».
    script.me = 'unreachable'
    await refreshSession()
    expect(session.status).toBe('unverified')

    script.me = 'anonymous'
    vi.advanceTimersByTime(30_000)
    await flushPromises()

    expect(session.status).toBe('expired')
    expect(router.currentRoute.value.path).toBe('/')
    app.unmount()
  })

  it('sans aucun compte connu, la relecture qui reçoit 401 ouvre la connexion', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const script: ServerScript = { me: 'html-error' }
    const { router, app } = await mountShell(script)
    expect(session.status).toBe('unverified')

    script.me = 'anonymous'
    vi.advanceTimersByTime(30_000)
    await flushPromises()

    expect(session.status).toBe('anonymous')
    expect(router.currentRoute.value.name).toBe('connexion')
    app.unmount()
  })
})

describe('états de la session (relecture et démarrage)', () => {
  it('revalidateSession : réseau coupé ou erreur 500, rien ne change', async () => {
    const script: ServerScript = { me: meResponse(['patient.read']) }
    stubServer(script)
    await refreshSession()

    for (const failure of ['unreachable', 'server-error', 'html-error'] as const) {
      script.me = failure
      expect(await revalidateSession()).toBe('authenticated')
      expect(session.account?.id).toBe(meResponse([]).data.id)
    }
  })

  it('revalidateSession : un autre compte connecté ailleurs remplace le compte affiché', async () => {
    const script: ServerScript = { me: meResponse(['patient.read']) }
    stubServer(script)
    await refreshSession()

    script.me = otherAccount()
    expect(await revalidateSession()).toBe('authenticated')
    expect(session.account?.id).toBe(OTHER_ACCOUNT)
    expect(session.lastAccount?.id).toBe(OTHER_ACCOUNT)
  })

  it('401 sans aucun compte connu : anonyme ; 401 avec un compte connu : expirée', async () => {
    const script: ServerScript = { me: 'anonymous' }
    stubServer(script)
    expect(await refreshSession()).toBe('anonymous')

    script.me = meResponse(['patient.read'])
    await refreshSession()
    script.me = 'anonymous'
    expect(await refreshSession()).toBe('expired')
    expect(session.lastAccount).not.toBeNull()
  })

  it('une session expirée reste expirée quand le serveur ne répond pas', async () => {
    const script: ServerScript = { me: meResponse(['patient.read']) }
    stubServer(script)
    await refreshSession()
    script.me = 'anonymous'
    await refreshSession()

    script.me = 'unreachable'
    expect(await refreshSession()).toBe('expired')
    script.me = 'server-error'
    expect(await refreshSession()).toBe('expired')
  })

  it('au démarrage ou à une reprise, une panne range la session en « non vérifiée »', async () => {
    const script: ServerScript = { me: 'unreachable' }
    stubServer(script)
    expect(await refreshSession()).toBe('unverified')
    expect(session.account).toBeNull()
  })

  it('la reconnexion rétablit le compte et efface l’expiration', async () => {
    const script: ServerScript = {
      me: meResponse(['patient.read']),
      login: meResponse(['patient.read']),
    }
    stubServer(script)
    await refreshSession()
    script.me = 'anonymous'
    await refreshSession()

    await signIn({ login: 'gerante.fictive', password: 'mot-de-passe-fictif' })

    expect(session.status).toBe('authenticated')
    expect(session.account?.id).toBe(meResponse([]).data.id)
  })
})

describe('réponse tardive d’une relecture', () => {
  it('une relecture terminée après la déconnexion ne ressuscite pas la session', async () => {
    const script: ServerScript = { me: meResponse(['patient.read']) }
    const base = stubServer(script)
    await refreshSession()

    let release!: (response: Response) => void
    const late = new Promise<Response>((resolve) => {
      release = resolve
    })
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: unknown, init?: RequestInit) => {
        if (String(input) === '/api/v1/auth/me') return late
        return base(input, init)
      }),
    )

    const pending = revalidateSession() // lancée avant la déconnexion
    await signOut()
    release(new Response(JSON.stringify(meResponse(['patient.read'])), { status: 200 }))
    await pending

    expect(session.status).toBe('anonymous')
    expect(session.account).toBeNull()
    expect(session.lastAccount).toBeNull()
  })
})
