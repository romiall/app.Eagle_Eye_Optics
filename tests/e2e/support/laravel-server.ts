import { spawn, type ChildProcess } from 'node:child_process'
import { join } from 'node:path'

export interface RunningServer {
  url: string
  /** Arrête réellement le serveur : plus aucune connexion n'aboutit (coupure vraie, pas simulée). */
  stop(): Promise<void>
}

const ROOT = join(import.meta.dirname, '..', '..', '..')

async function waitUntilReady(url: string, process: ChildProcess): Promise<void> {
  const deadline = Date.now() + 30_000
  while (Date.now() < deadline) {
    if (process.exitCode !== null)
      throw new Error('Le serveur PHP de test s’est arrêté au démarrage.')
    try {
      const response = await fetch(`${url}/up`)
      if (response.ok) return
    } catch {
      // Pas encore prêt.
    }
    await new Promise((resolve) => setTimeout(resolve, 200))
  }
  throw new Error('Le serveur PHP de test n’est pas prêt après 30 secondes.')
}

/**
 * Démarre l'application Laravel (public/build) sur un port dédié, avec le même fichier .env.e2e que
 * le serveur principal. Les tests de coupure doivent l'arrêter eux-mêmes : Playwright ne simule pas
 * l'absence de réseau pour les requêtes émises par le service worker.
 *
 * `environment` surcharge des variables du fichier .env.e2e (une variable d'environnement du
 * processus l'emporte sur le fichier), par exemple APP_URL=https://… comme en production.
 */
export async function startLaravelServer(
  port: number,
  environment: Readonly<Record<string, string>> = {},
): Promise<RunningServer> {
  const router = join(
    ROOT,
    'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
  )
  // Comme « artisan serve » : répertoire courant = public/, que le routeur de Laravel utilise.
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, router], {
    cwd: join(ROOT, 'public'),
    env: { ...process.env, ...environment, APP_ENV: 'e2e' },
    stdio: 'ignore',
    // Groupe de processus dédié : l'arrêt supprime aussi d'éventuels processus enfants.
    detached: true,
  })

  const url = `http://127.0.0.1:${port}`
  try {
    await waitUntilReady(url, server)
  } catch (error) {
    server.kill('SIGKILL')
    throw error
  }

  let stopped = false

  return {
    url,
    async stop() {
      if (stopped) return
      stopped = true

      if (server.pid !== undefined && server.exitCode === null && server.signalCode === null) {
        const exited = new Promise<void>((resolve) => server.once('exit', () => resolve()))
        try {
          process.kill(-server.pid, 'SIGKILL')
        } catch {
          // Déjà arrêté.
        }
        await exited
      }

      await waitUntilRefusing(url)
    },
  }
}

/** Vérifie que le port refuse réellement les connexions : la coupure est vraie. */
async function waitUntilRefusing(url: string): Promise<void> {
  const deadline = Date.now() + 10_000
  while (Date.now() < deadline) {
    try {
      await fetch(`${url}/up`, { signal: AbortSignal.timeout(1_000) })
    } catch {
      return
    }
    await new Promise((resolve) => setTimeout(resolve, 100))
  }
  throw new Error('Le serveur PHP de test répond encore après son arrêt.')
}
