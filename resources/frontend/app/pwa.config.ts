import type { VitePWAOptions } from 'vite-plugin-pwa'

/**
 * Configuration PWA partagée entre vite.config.ts (build) et les tests Vitest.
 *
 * Règles de sécurité (fichiers 09, 19 et 23) :
 *  - aucune réponse de /api n'est jamais mise en cache par le service worker ;
 *  - la coquille SPA n'est jamais servie en repli pour /api (ni pour des fichiers statiques) ;
 *  - aucun cache médical générique : seuls la coquille et les fichiers versionnés du build sont précachés.
 */

/** URL de navigation pour lesquelles le service worker ne rend jamais la coquille SPA. */
export const NAVIGATE_FALLBACK_DENYLIST: readonly RegExp[] = [
  /^\/api(?:\/|$)/,
  /^\/up$/,
  /^\/build\//,
  /^\/storage(?:\/|$)/,
  /^\/sw\.js$/,
  /^\/manifest\.webmanifest$/,
  // Dernier segment ressemblant à un fichier (extension), éventuellement suivi d'une requête.
  /^[^?]*\/[^/?]*\.[A-Za-z0-9]{1,10}(?:\?.*)?$/,
]

export const SHELL_URL = '/'

export const PWA_ICONS = [
  { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
  { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
  {
    src: '/icons/icon-maskable-512.png',
    sizes: '512x512',
    type: 'image/png',
    purpose: 'maskable',
  },
] as const

/** Les fichiers de public/ ne passent pas par Vite (laravel-vite-plugin désactive publicDir). */
export function staticPrecacheEntries(
  revisions: Readonly<Record<string, string>>,
  shellRevision: string,
): { url: string; revision: string }[] {
  return [
    { url: SHELL_URL, revision: shellRevision },
    ...PWA_ICONS.map(({ src }) => ({ url: src, revision: revisions[src] ?? shellRevision })),
    { url: '/icons/favicon.svg', revision: revisions['/icons/favicon.svg'] ?? shellRevision },
  ]
}

export function createPwaOptions(
  revisions: Readonly<Record<string, string>>,
  shellRevision: string,
): Partial<VitePWAOptions> {
  return {
    // Enregistrement manuel (app/pwa.ts) : le service worker est servi à /sw.js par Laravel.
    injectRegister: false,
    // Mise à jour explicite : un nouveau service worker n'écrase jamais une session de travail
    // ouverte (aucune commande locale en attente ne doit être interrompue).
    registerType: 'prompt',
    strategies: 'generateSW',
    filename: 'sw.js',
    manifestFilename: 'manifest.webmanifest',
    scope: '/',
    includeManifestIcons: false,
    manifest: {
      id: '/',
      name: 'Eagle’s Eye Optics Medical',
      short_name: 'Eagle’s Eye',
      description:
        'Application interne du cabinet : patients, facturation et impression, y compris hors ligne.',
      lang: 'fr',
      dir: 'ltr',
      start_url: SHELL_URL,
      scope: '/',
      display: 'standalone',
      orientation: 'any',
      // Palette du fichier 04 : fond et action principale (resources/frontend/styles/tokens.css).
      background_color: '#f6f8fb',
      theme_color: '#155e75',
      icons: PWA_ICONS.map((icon) => ({ ...icon })),
    },
    workbox: {
      globPatterns: ['assets/**/*.{js,css,woff2}'],
      additionalManifestEntries: staticPrecacheEntries(revisions, shellRevision),
      // Les chemins issus de Vite sont relatifs à public/build ; ceux de public/ commencent par « / ».
      manifestTransforms: [
        (entries) => ({
          manifest: entries.map((entry) =>
            entry.url.startsWith('/') ? entry : { ...entry, url: `/build/${entry.url}` },
          ),
          warnings: [],
        }),
      ],
      navigateFallback: SHELL_URL,
      navigateFallbackDenylist: [...NAVIGATE_FALLBACK_DENYLIST],
      // Un seul fichier : le service worker est servi à la racine sans importScripts relatif.
      inlineWorkboxRuntime: true,
      cleanupOutdatedCaches: true,
      clientsClaim: false,
      skipWaiting: false,
      sourcemap: false,
      maximumFileSizeToCacheInBytes: 8 * 1024 * 1024,
      // Explicite : l'API passe toujours par le réseau, jamais par un cache.
      runtimeCaching: [
        {
          urlPattern: ({ url }: { url: URL }) => /^\/api(?:\/|$)/.test(url.pathname),
          handler: 'NetworkOnly',
        },
      ],
    },
  }
}
