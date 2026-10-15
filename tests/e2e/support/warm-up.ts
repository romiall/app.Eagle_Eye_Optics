/**
 * Préchauffe un serveur de test : la première requête de chaque chemin compile Laravel (OPcache le
 * garde ensuite pour ce processus). Sans ce passage, le premier test paie seul plusieurs secondes de
 * compilation sur un poste chargé. Les réponses ne sont pas vérifiées ici : les tests le font.
 */
const WARM_UP_REQUESTS: readonly (readonly [string, string])[] = [
  ['/', 'text/html'],
  ['/connexion', 'text/html'],
  ['/api/v1/auth/me', 'application/json'],
  ['/api/sanctum/csrf-cookie', 'application/json'],
  ['/sw.js', '*/*'],
  ['/manifest.webmanifest', '*/*'],
]

export async function warmUp(baseUrl: string): Promise<void> {
  for (const [path, accept] of WARM_UP_REQUESTS) {
    await fetch(`${baseUrl}${path}`, {
      headers: { Accept: accept },
      signal: AbortSignal.timeout(30_000),
    }).catch(() => undefined)
  }
}
