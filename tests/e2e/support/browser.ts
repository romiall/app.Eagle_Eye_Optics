import type { BrowserContext, Page } from '@playwright/test'

/** Attend que le service worker soit actif et retourne sa portée. */
export async function activeServiceWorkerScope(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready
    return registration.scope
  })
}

/** URL de toutes les requêtes émises par le contexte : pages, iframes et service worker compris. */
export function recordRequests(context: BrowserContext): string[] {
  const urls: string[] = []
  context.on('request', (request) => urls.push(request.url()))
  return urls
}

/** Requêtes réseau (http, https, ws, wss) dont l’origine n’est pas celle de l’application. */
export function foreignRequests(urls: readonly string[], origin: string): string[] {
  return urls.filter((url) => /^(?:https?|wss?):/.test(url) && new URL(url).origin !== origin)
}
