/**
 * État du stockage du poste (vault.md D24 ; fichier 19, « Préparation du poste », étape 6) : persistance demandée
 * au navigateur, place disponible, aller-retour d'écriture, soupçon de mode privé (heuristique, jamais une preuve) et
 * durabilité des transactions. O02 l'appelle avant l'enrôlement ; l'écran dit honnêtement ce qui manque. La
 * persistance demandée n'est PAS une sauvegarde.
 */
export interface StoragePort {
  persisted(): Promise<boolean>
  persist(): Promise<boolean>
  estimate(): Promise<{ quota: number | null; usage: number | null }>
  /** Écrit puis relit une valeur témoin dans le stockage du coffre ; vrai si l'aller-retour réussit. */
  roundTrip(): Promise<boolean>
  /** Vrai si le navigateur garantit le vidage disque à chaque transaction (Chromium en mode strict). */
  strictDurability(): boolean
}

export interface StorageHealth {
  persisted: boolean
  quotaBytes: number | null
  usageBytes: number | null
  roundTrip: boolean
  privateModeSuspected: boolean
  strictDurability: boolean
  /** Ce qui empêche de préparer le poste (vide = rien de bloquant). */
  blockers: StorageBlocker[]
}

export type StorageBlocker =
  'ROUND_TRIP_FAILED' | 'PRIVATE_MODE_SUSPECTED' | 'NOT_PERSISTED' | 'LOW_QUOTA'

/** En dessous de 100 Mo de quota, le mode privé est soupçonné (heuristique des navigateurs Chromium). */
const PRIVATE_MODE_QUOTA = 120 * 1024 * 1024
/** Place minimale proposée pour un poste principal (PDF, journal, annuaire). */
const MINIMUM_QUOTA = 200 * 1024 * 1024

export async function storageHealth(port: StoragePort): Promise<StorageHealth> {
  let persisted = await port.persisted().catch(() => false)
  if (!persisted) persisted = await port.persist().catch(() => false)
  const { quota, usage } = await port.estimate().catch(() => ({ quota: null, usage: null }))
  const roundTrip = await port.roundTrip().catch(() => false)
  const privateModeSuspected = quota !== null && quota < PRIVATE_MODE_QUOTA
  const blockers: StorageBlocker[] = []
  if (!roundTrip) blockers.push('ROUND_TRIP_FAILED')
  if (privateModeSuspected) blockers.push('PRIVATE_MODE_SUSPECTED')
  if (!persisted) blockers.push('NOT_PERSISTED')
  if (quota !== null && !privateModeSuspected && quota - (usage ?? 0) < MINIMUM_QUOTA)
    blockers.push('LOW_QUOTA')
  return {
    persisted,
    quotaBytes: quota,
    usageBytes: usage,
    roundTrip,
    privateModeSuspected,
    strictDurability: port.strictDurability(),
    blockers,
  }
}

/** Port réel du navigateur (`navigator.storage`) ; l'aller-retour est fourni par le coffre. */
export function browserStoragePort(roundTrip: () => Promise<boolean>): StoragePort {
  const storage = typeof navigator === 'undefined' ? undefined : navigator.storage
  return {
    persisted: async () => (storage?.persisted ? storage.persisted() : false),
    persist: async () => (storage?.persist ? storage.persist() : false),
    estimate: async () => {
      if (!storage?.estimate) return { quota: null, usage: null }
      const estimate = await storage.estimate()
      return { quota: estimate.quota ?? null, usage: estimate.usage ?? null }
    },
    roundTrip,
    strictDurability: () =>
      typeof navigator !== 'undefined' &&
      /Chrome\//.test(navigator.userAgent) &&
      !/Firefox\//.test(navigator.userAgent),
  }
}
