import { expect, test, type BrowserContext, type Page } from '@playwright/test'

import { startLaravelServer } from './support/laravel-server'

const OFFLINE_PORT = Number(process.env['EEO_E2E_PORT'] ?? 8123) + 1

/** Attend que le service worker soit actif et retourne sa portée. */
async function activeServiceWorkerScope(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready
    return registration.scope
  })
}

/** URL de toutes les requêtes émises par le contexte : pages, iframes et service worker compris. */
function recordRequests(context: BrowserContext): string[] {
  const urls: string[] = []
  context.on('request', (request) => urls.push(request.url()))
  return urls
}

/** Requêtes réseau (http, https, ws, wss) dont l’origine n’est pas celle de l’application. */
function foreignRequests(urls: readonly string[], origin: string): string[] {
  return urls.filter((url) => /^(?:https?|wss?):/.test(url) && new URL(url).origin !== origin)
}

test.describe('application construite : coquille, PWA et API', () => {
  test('charge la coquille en français sans erreur de console', async ({ page }) => {
    const problems: string[] = []
    page.on('console', (message) => {
      if (message.type() === 'error') problems.push(message.text())
    })
    page.on('pageerror', (error) => problems.push(error.message))

    await page.goto('/')

    await expect(page).toHaveTitle(/Eagle’s Eye Optics — Gestion du cabinet/)
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Application du cabinet' }),
    ).toBeVisible()
    await expect(page.getByRole('banner')).toContainText('Eagle’s Eye Optics Medical')
    expect(problems).toEqual([])
  })

  test('enregistre le service worker à la racine avec la portée de toute l’origine', async ({
    page,
  }) => {
    await page.goto('/')

    const scope = await activeServiceWorkerScope(page)
    expect(scope).toBe(`${new URL(page.url()).origin}/`)

    const script = await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.ready
      return registration.active?.scriptURL ?? null
    })
    expect(script).toBe(`${new URL(page.url()).origin}/sw.js`)
  })

  test('sert un manifeste français et des icônes accessibles', async ({ page, request }) => {
    const response = await request.get('/manifest.webmanifest')
    expect(response.ok()).toBe(true)

    const manifest = (await response.json()) as {
      lang: string
      start_url: string
      scope: string
      icons: { src: string }[]
    }
    expect(manifest.lang).toBe('fr')
    expect(manifest.start_url).toBe('/')
    expect(manifest.scope).toBe('/')

    for (const icon of manifest.icons) {
      const iconResponse = await page.request.get(icon.src)
      expect(iconResponse.status(), icon.src).toBe(200)
      expect(iconResponse.headers()['content-type']).toContain('image/png')
    }
  })

  test('recharge la coquille serveur arrêté grâce au précache, mais jamais /api', async ({
    page,
  }) => {
    // Playwright n'applique pas « hors ligne » aux requêtes du service worker : on coupe vraiment le
    // serveur. L'origine dédiée (port voisin) a son propre service worker et son propre précache.
    const server = await startLaravelServer(OFFLINE_PORT)
    try {
      await page.goto(`${server.url}/`)
      await activeServiceWorkerScope(page)
      // Le contrôle du service worker commence à la navigation suivante (clientsClaim désactivé).
      await page.reload()
      await expect
        .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
        .toBe(true)

      await server.stop()

      await page.reload()
      await expect(
        page.getByRole('heading', { level: 1, name: 'Application du cabinet' }),
      ).toBeVisible()

      // Une URL du routeur Vue est aussi servie depuis la coquille précachée.
      await page.goto(`${server.url}/patients`)
      await expect(page.getByRole('heading', { level: 1, name: 'Page introuvable' })).toBeVisible()

      // /api n'est jamais remplacé par la coquille ni servi depuis un cache.
      await expect(page.goto(`${server.url}/api/v1/inconnu`)).rejects.toThrow(/net::ERR_/)
      const apiFetch = await page.evaluate(async () => {
        try {
          const response = await fetch('/api/v1/inconnu')
          return `reponse-${response.status}`
        } catch {
          return 'echec-reseau'
        }
      })
      expect(apiFetch).toBe('echec-reseau')
    } finally {
      await server.stop()
    }
  })

  test('une URL /api inconnue répond en JSON 404 même avec le service worker actif', async ({
    page,
  }) => {
    await page.goto('/')
    await activeServiceWorkerScope(page)
    await page.reload()
    await expect
      .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
      .toBe(true)

    const result = await page.evaluate(async () => {
      const response = await fetch('/api/v1/inconnu', { headers: { Accept: 'text/html' } })
      return {
        status: response.status,
        contentType: response.headers.get('content-type'),
        body: await response.text(),
      }
    })

    expect(result.status).toBe(404)
    expect(result.contentType).toContain('application/json')
    expect(result.body).not.toContain('id="app"')
  })
})

test.describe('une seule origine : aucune ressource tierce, sonde de santé comprise', () => {
  test('la sonde /up ne charge aucune ressource hors de l’origine (ni script, ni fonte, ni style tiers)', async ({
    page,
    context,
    baseURL,
  }) => {
    const origin = new URL(baseURL ?? '').origin
    const requested = recordRequests(context)

    await page.goto('/up')
    await page.waitForLoadState('networkidle')

    expect(requested).toContain(`${origin}/up`)
    expect(foreignRequests(requested, origin)).toEqual([])
  })

  test('la sonde /up répond en JSON minimal, jamais mis en cache', async ({ page }) => {
    const response = await page.goto('/up')

    expect(response?.status()).toBe(200)
    expect(response?.headers()['content-type']).toContain('application/json')
    expect(response?.headers()['cache-control']).toContain('no-store')
    expect(await response?.json()).toEqual({ status: 'ok' })
  })

  test('la sonde /up reste servie par le réseau, jamais par la coquille, même sous service worker', async ({
    page,
    context,
    baseURL,
  }) => {
    const origin = new URL(baseURL ?? '').origin

    await page.goto('/')
    await activeServiceWorkerScope(page)
    await page.reload()
    await expect
      .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
      .toBe(true)

    const requested = recordRequests(context)
    const response = await page.goto('/up')
    await page.waitForLoadState('networkidle')

    expect(response?.status()).toBe(200)
    expect(await response?.text()).not.toContain('id="app"')
    expect(await response?.json()).toEqual({ status: 'ok' })
    expect(foreignRequests(requested, origin)).toEqual([])
  })

  test('la coquille et son service worker ne chargent aucune ressource hors de l’origine', async ({
    page,
    context,
    baseURL,
  }) => {
    const origin = new URL(baseURL ?? '').origin
    const requested = recordRequests(context)

    await page.goto('/')
    await activeServiceWorkerScope(page)
    await page.reload()
    await page.waitForLoadState('networkidle')

    expect(requested.length).toBeGreaterThan(0)
    expect(foreignRequests(requested, origin)).toEqual([])
  })
})
