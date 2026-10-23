/**
 * Verrou du journal du poste entre onglets (contrat 23 : « Le verrou Web Locks/BroadcastChannel peut réduire la
 * contention entre onglets, mais la preuve d'exclusion est le CAS transactionnel et les clés uniques »). Il ÉVITE que
 * deux onglets calculent en même temps deux candidats pour la même séquence ; il ne PROUVE rien : sans lui (navigateur
 * sans Web Locks, test), le commit local revérifie la tête du journal, le curseur des plages et les versions dans sa
 * transaction et recommence le calcul en cas de collision.
 */
export const JOURNAL_LOCK = 'eeo-device-journal'

interface LockManagerLike {
  request<T>(name: string, options: { mode: 'exclusive' }, callback: () => Promise<T>): Promise<T>
}

function lockManager(): LockManagerLike | null {
  const candidate = (globalThis as { navigator?: { locks?: unknown } }).navigator?.locks
  if (typeof candidate !== 'object' || candidate === null) return null
  const manager = candidate as Partial<LockManagerLike>
  return typeof manager.request === 'function' ? (candidate as LockManagerLike) : null
}

/** Exécute `work` sous le verrou exclusif du journal quand le navigateur le fournit, sinon directement. */
export async function withJournalLock<T>(
  work: () => Promise<T>,
  manager: LockManagerLike | null = lockManager(),
): Promise<T> {
  if (manager === null) return work()
  return manager.request(JOURNAL_LOCK, { mode: 'exclusive' }, work)
}
