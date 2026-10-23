import { readonly, shallowRef } from 'vue'

import { ApiError } from '@/app/api'
import { APP_RELEASE } from '@/app/release'
import { installDocumentStore } from '@/app/services/documents'
import { moduleOpenOnScreen, session as appSession } from '@/app/session'
import { MemoryDocumentStore } from '@/documents/store/memory'
import { clinicTimeZone } from '@/app/clinicTime'
import type { Release, Replacement } from '@/domain/contracts/generated'
import {
  type DeviceSync,
  type ExportManifest,
  type GrantClaims,
  type ImportReport,
  type LocalCommands,
  type OfflineVault,
  type OpenedExport,
  openOfflineVault,
  type PreparedRecovery,
  type ProfileSummary,
  type RecoveredPushReport,
  type RecoveryTransport,
  type ReleaseCompatibility,
  type SessionInfo,
  type StorageHealth,
  SyncRefused,
  type UpdateReadiness,
  type VaultStatus,
} from '@/offline/api'

import {
  enrollDevice,
  getDevicePreparation,
  getSigningKeys,
  getSyncCompatibility,
  issueOfflineGrant,
  type PrepareDeviceInput,
  prepareDeviceResources,
  pushRecoveredCommands,
  reconcileReplacement,
  replaceDevice,
} from './devices'
import { httpSyncTransport } from './syncTransport'

// Ce que les écrans emploient du coffre passe par ce service (règle ESLint : jamais @/offline dans une page).
export { isRecoveryError, isVaultError, localSecrets } from '@/offline/api'
export type {
  ExportManifest,
  GrantClaims,
  ImportReport,
  PreparedRecovery,
  RecoveredPushReport,
  StorageHealth,
  UpdateReadiness,
  UpdateReason,
} from '@/offline/api'

/**
 * Coffre local du poste vu par l'application (O01, O02) : état réactif et opérations de PRÉPARATION du poste. Le
 * coffre n'est ouvert que si l'exploitant a ouvert le module `offline` : sans lui, rien ne touche à IndexedDB et
 * l'application se comporte exactement comme avant (en ligne).
 *
 * Il permet à la gérante de créer le coffre du poste, d'enrôler le poste et de préparer les profils (grants signés
 * vérifiés et conservés) et ses ressources ; le journal du poste (O04) et sa synchronisation (O05) n'agissent que
 * module `offline` ouvert et coffre ouvert. Les PDF originaux d'une session ouverte sont conservés chiffrés dans le
 * coffre.
 */
export interface VaultView {
  status: VaultStatus | 'unavailable'
  session: SessionInfo | null
  profiles: ProfileSummary[]
  deviceId: string | null
}

const state = shallowRef<VaultView>({
  status: 'unavailable',
  session: null,
  profiles: [],
  deviceId: null,
})
let instance: OfflineVault | null = null
let commands: LocalCommands | null = null
let sync: DeviceSync | null = null

/**
 * Moteur de commandes local (O04) du coffre OUVERT, si le module `offline` est ouvert : null sinon (rien ne change
 * pour l'application en ligne). Ses droits sont relus après déverrouillage, préparation et chaque commit.
 */
export function localCommandsOrNull(): LocalCommands | null {
  if (!offlineModuleOpen() || instance === null || state.value.session === null) return null
  commands ??= instance.localCommands({ timeZone: clinicTimeZone() })
  return commands
}

/**
 * Synchronisation (O05) du coffre OUVERT d'un poste enrôlé, si le module `offline` est ouvert : null sinon (aucun état
 * de synchronisation n'est alors annoncé).
 */
export function deviceSyncOrNull(): DeviceSync | null {
  if (
    !offlineModuleOpen() ||
    instance === null ||
    state.value.session === null ||
    state.value.deviceId === null
  )
    return null
  sync ??= instance.sync({ transport: httpSyncTransport })
  return sync
}

async function refreshLocalCommands(): Promise<void> {
  const current = localCommandsOrNull()
  if (current !== null) await current.refresh()
}

export const vaultView = readonly(state)

/** Vrai si le module de préparation des postes est ouvert pour l'écran en cours. */
export function offlineModuleOpen(): boolean {
  return moduleOpenOnScreen('offline')
}

function vault(): OfflineVault {
  if (!offlineModuleOpen()) throw new Error('Module de préparation des postes fermé.')
  instance ??= openOfflineVault()
  return instance
}

export async function refreshVault(): Promise<VaultView> {
  if (!offlineModuleOpen()) {
    state.value = { status: 'unavailable', session: null, profiles: [], deviceId: null }
    return state.value
  }
  const current = vault()
  const status = await current.status()
  state.value = {
    status,
    session: current.session(),
    profiles: status === 'absent' ? [] : await current.profiles(),
    deviceId: status === 'absent' ? null : await current.deviceId(),
  }
  return state.value
}

export async function vaultStorageHealth(): Promise<StorageHealth> {
  return vault().storageHealth()
}

/** Création du coffre par la gérante connectée (son identité vient de la session serveur, jamais d'une saisie). */
export async function prepareVaultForManager(secret: string): Promise<PreparedRecovery> {
  const account = appSession.account
  if (account === null) throw new Error('Session non vérifiée : reconnectez-vous.')
  return vault().prepareVault({
    clinicId: account.clinic.id,
    profile: {
      userId: account.id,
      login: account.login,
      displayName: account.display_name,
      permissions: account.permissions,
    },
    secret,
  })
}

export async function unlockVault(profileId: string, secret: string): Promise<SessionInfo> {
  const opened = await vault().unlock(profileId, secret)
  // Les PDF originaux d'une session ouverte sont conservés chiffrés dans le coffre (O01).
  installDocumentStore(vault().documentStore(), { persistent: true })
  commands = null
  sync = null
  await refreshVault()
  await refreshLocalCommands()
  return opened
}

export async function lockVault(reason = 'USER'): Promise<void> {
  if (instance === null) return
  await instance.lock(reason)
  commands = null
  sync = null
  // Coffre verrouillé : retour à la conservation volatile (l'écran le dit, comme avant).
  installDocumentStore(new MemoryDocumentStore(), { persistent: false })
  await refreshVault()
}

/**
 * Enrôle CE poste : clé ES256 du poste créée dans le coffre (secret de la gérante ressaisi), preuve de possession,
 * enrôlement serveur, liaison du coffre au `device_id`, clés publiques du serveur épinglées.
 */
export async function enrollThisDevice(input: {
  managerSecret: string
  deviceCode: string
  name: string
  appVersion: string
}): Promise<string> {
  const current = vault()
  const opened = current.session()
  const account = appSession.account
  if (opened === null || account === null)
    throw new Error('Ouvrez le coffre de ce poste avant de l’enrôler.')
  if ((await current.deviceId()) !== null) throw new Error('Ce poste est déjà enrôlé.')
  const keys = await getSigningKeys()
  await current.pinServerKeys(
    keys.keys.map((key) => ({
      kid: key.kid,
      status: key.status,
      jwk: { kty: 'EC', crv: 'P-256', x: key.jwk.x, y: key.jwk.y },
    })),
  )
  if (!opened.canSign)
    await current.createSigningKey({ profileId: opened.profileId, secret: input.managerSecret })
  const body = await current.enrollmentBody({
    deviceCode: input.deviceCode,
    name: input.name,
    responsibleUserId: account.id,
    appVersion: input.appVersion,
    issuedAt: new Date().toISOString(),
  })
  const device = await enrollDevice(body)
  await current.bindDevice(device.device_id)
  await refreshVault()
  return device.device_id
}

/** Prépare un profil de ce poste : grant signé émis par le serveur, VÉRIFIÉ localement puis conservé. */
export async function prepareProfileGrant(
  userId: string,
  validHours: number | null = null,
): Promise<GrantClaims> {
  const current = vault()
  const deviceId = await current.deviceId()
  if (deviceId === null) throw new Error('Enrôlez ce poste avant de préparer ses profils.')
  const keys = await getSigningKeys()
  await current.pinServerKeys(
    keys.keys.map((key) => ({
      kid: key.kid,
      status: key.status,
      jwk: { kty: 'EC', crv: 'P-256', x: key.jwk.x, y: key.jwk.y },
    })),
  )
  const grant = await issueOfflineGrant(deviceId, userId, validHours)
  const claims = await current.acceptGrant(grant)
  await current.noteServerTime(grant.issued_at)
  return claims
}

/**
 * Ajoute le profil local d'un compte préparé : la personne saisit ELLE-MÊME son secret local sur ce poste (aucun
 * secret initial connu de la gérante) ; la gérante ressaisit le sien pour lui envelopper les clés de ses
 * compartiments, déduits des permissions de son grant signé.
 */
export async function addLocalProfile(input: {
  managerSecret: string
  member: { id: string; login: string; display_name: string }
  claims: GrantClaims
  holderSecret: string
}): Promise<string> {
  const current = vault()
  const opened = current.session()
  if (opened === null) throw new Error('Ouvrez le coffre de ce poste avant d’y ajouter un profil.')
  const profileId = await current.addProfile(
    { profileId: opened.profileId, secret: input.managerSecret },
    {
      userId: input.member.id,
      login: input.member.login,
      displayName: input.member.display_name,
      permissions: input.claims.permissions,
    },
    input.holderSecret,
  )
  await refreshVault()
  return profileId
}

/**
 * Prépare les RESSOURCES de ce poste (O03) : plages de numéros, quotas, factures attribuées, demandés au serveur par la
 * gérante, puis conservés dans le coffre (curseurs locaux jamais reculés) avec le catalogue figé.
 */
export async function prepareThisDeviceResources(
  input: PrepareDeviceInput,
): Promise<{ ranges: number; quotas: number; owners: number }> {
  const current = vault()
  const deviceId = await current.deviceId()
  if (deviceId === null) throw new Error('Enrôlez ce poste avant de préparer ses ressources.')
  const preparation = await prepareDeviceResources(deviceId, input)
  const counts = await current.storePreparation(preparation)
  await refreshLocalCommands()
  return counts
}

/** Relit la préparation de ce poste (reconnexion) et la fusionne dans le coffre. */
export async function refreshThisDevicePreparation(): Promise<{
  ranges: number
  quotas: number
  owners: number
}> {
  const current = vault()
  const deviceId = await current.deviceId()
  if (deviceId === null) throw new Error('Enrôlez ce poste avant de relire ses ressources.')
  const counts = await current.storePreparation(await getDevicePreparation(deviceId))
  await refreshLocalCommands()
  return counts
}

// --- Mise à jour sûre (O06) -------------------------------------------------------------------------------------

/**
 * La nouvelle version proposée peut-elle s'installer maintenant ? Sans module `offline` ouvert ni coffre, rien n'attend
 * sur ce poste : FREE. Sinon la déclaration du serveur est relue (null si injoignable) et comparée aux pièces en attente.
 */
export async function updateReadinessForThisDevice(): Promise<UpdateReadiness> {
  if (!offlineModuleOpen()) return { decision: 'FREE', reasons: [], pendingCount: 0 }
  const compatibility: ReleaseCompatibility | null = await getSyncCompatibility().catch(() => null)
  return vault().updateReadiness(compatibility)
}

// --- Export et reprise d'un poste (O07) -------------------------------------------------------------------------

/** Fait télécharger un texte par le navigateur (aucun envoi réseau). */
function download(fileName: string, text: string): void {
  const blob = new Blob([text], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName
  anchor.rel = 'noopener'
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10_000)
}

/** Export chiffré de ce poste (profil ouvert), téléchargé par le navigateur ; rend le manifeste affiché à l'écran. */
export async function exportThisDevice(): Promise<{ fileName: string; manifest: ExportManifest }> {
  const result = await vault().exportDevice(APP_RELEASE)
  download(result.fileName, result.text)
  return { fileName: result.fileName, manifest: result.manifest }
}

let openedExport: OpenedExport | null = null

/** Ouvre l'export d'un ANCIEN poste avec la clé de récupération de la gérante ; rien n'est écrit. */
export async function openOldDeviceExport(
  text: string,
  recoveryKey: string,
): Promise<ExportManifest> {
  openedExport = await vault().openExport(text, recoveryKey)
  return openedExport.manifest
}

/** Range l'export ouvert dans ce coffre (commandes signées et pièces rescellées), sans rien envoyer. */
export async function importOldDeviceExport(): Promise<ImportReport> {
  if (openedExport === null) throw new Error('Ouvrez d’abord l’export de l’ancien poste.')
  const report = await vault().importExport(openedExport)
  openedExport = null
  return report
}

/** Déclare au serveur que ce poste remplace l'ancien (gérante, mot de passe confirmé). */
export async function declareReplacement(
  oldDeviceId: string,
  reason: string,
): Promise<Replacement> {
  const deviceId = await vault().deviceId()
  if (deviceId === null) throw new Error('Enrôlez ce poste avant de déclarer le remplacement.')
  return replaceDevice(oldDeviceId, deviceId, reason)
}

/** Transport de la reprise : erreurs explicites du serveur rendues comme refus de synchronisation (code stable). */
const recoveryTransport: RecoveryTransport = {
  pushRecovered: async (oldDeviceId, body) => {
    try {
      return await pushRecoveredCommands(oldDeviceId, body)
    } catch (error) {
      if (error instanceof ApiError && error.fromApplication && error.status !== 423) {
        throw new SyncRefused(error.status, error.code, error.message, error.details)
      }
      throw error
    }
  },
}

/**
 * Transmet toutes les commandes signées de l'ancien poste, lot par lot, tant que chaque lot avance. S'arrête sur une
 * commande en revue ou en attente d'une pièce liée (la gérante décide dans « Conflits de synchronisation »).
 */
export async function transmitRecoveredCommands(oldDeviceId: string): Promise<RecoveredPushReport> {
  const total: RecoveredPushReport = {
    sent: 0,
    accepted: 0,
    waiting: 0,
    conflicts: 0,
    rejected: 0,
    complete: false,
    prefix: null,
  }
  for (let round = 0; round < 200; round += 1) {
    const report = await vault().pushRecovered(recoveryTransport, oldDeviceId)
    total.sent += report.sent
    total.accepted += report.accepted
    total.waiting += report.waiting
    total.conflicts += report.conflicts
    total.rejected += report.rejected
    total.prefix = report.prefix ?? total.prefix
    total.complete = report.complete
    if (report.complete || report.sent === 0 || report.conflicts > 0 || report.waiting > 0) break
  }
  return total
}

/** Clôture côté serveur des ressources de l'ancien poste, puis trace locale de la reprise terminée. */
export async function reconcileOldDevice(input: {
  exportId: string
  oldDeviceId: string
  lastDeviceSequence: string
  lastEventHash: string | null
  reason: string
}): Promise<Release> {
  const release = await reconcileReplacement(input.oldDeviceId, {
    last_device_sequence: input.lastDeviceSequence,
    last_event_hash: input.lastEventHash,
    reason: input.reason,
  })
  await vault().markReconciled(input.exportId)
  return release
}

/** Reprises enregistrées sur ce poste (exports ouverts, commandes encore à transmettre). */
export async function deviceRecoveryStatus(): Promise<
  Awaited<ReturnType<OfflineVault['recoveryStatus']>>
> {
  return vault().recoveryStatus()
}

export function resetVaultServiceForTests(): void {
  instance?.dispose()
  instance = null
  commands = null
  sync = null
  openedExport = null
  state.value = { status: 'unavailable', session: null, profiles: [], deviceId: null }
}
