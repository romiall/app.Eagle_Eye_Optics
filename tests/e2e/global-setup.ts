import { MAIN_PORT } from './support/ports'
import { warmUp } from './support/warm-up'

/** Après le démarrage du serveur principal (webServer), avant le premier test. */
export default async function globalSetup(): Promise<void> {
  await warmUp(`http://127.0.0.1:${MAIN_PORT}`)
}
