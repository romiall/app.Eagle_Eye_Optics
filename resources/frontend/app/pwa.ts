import { Workbox } from 'workbox-window'

/**
 * Enregistre le service worker servi par Laravel à la racine (/sw.js, portée « / »).
 * Retourne null lorsque l'environnement ne le permet pas (serveur de développement, navigateur sans
 * service worker, contexte non sécurisé).
 */
export function registerServiceWorker(): Workbox | null {
  if (import.meta.env.DEV || typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
    return null
  }

  const workbox = new Workbox('/sw.js', { scope: '/' })
  void workbox.register()
  return workbox
}
