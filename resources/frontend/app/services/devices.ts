import { ApiError, apiRequest, prepareSession } from '@/app/api'
import type {
  CompatibilityDeclaration,
  CompatibilityResponse,
  EnrolledDevice,
  EnrolledDeviceList,
  EnrolledDeviceResponse,
  OfflineGrant,
  OfflineGrantList,
  OfflineGrantResponse,
  Preparation,
  PreparationResponse,
  PushRequest,
  PushResponse,
  Release,
  ReleaseResponse,
  Replacement,
  ReplacementResponse,
  SigningKeySet,
  SigningKeySetResponse,
} from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

/**
 * Postes préparés pour le hors-ligne (O02), routes du module `offline` (gérante, `offline.enroll`). Les écritures
 * exigent une réauthentification récente (423 : l'écran demande le mot de passe puis rejoue la même écriture) et
 * portent une clé d'idempotence. Aucune de ces requêtes ne transporte de donnée patient.
 */
async function write<T>(path: string, body: unknown): Promise<T> {
  const headers = { 'Idempotency-Key': newUlid() }
  try {
    return await apiRequest<T>('POST', path, body, headers)
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 419) throw error
    await prepareSession()
    return apiRequest<T>('POST', path, body, headers)
  }
}

export async function listDevices(): Promise<EnrolledDevice[]> {
  return (await apiRequest<EnrolledDeviceList>('GET', '/api/v1/devices')).data
}

export async function listDeviceGrants(deviceId: string): Promise<OfflineGrant[]> {
  return (
    await apiRequest<OfflineGrantList>(
      'GET',
      `/api/v1/devices/${encodeURIComponent(deviceId)}/grants`,
    )
  ).data
}

export async function getSigningKeys(): Promise<SigningKeySet> {
  return (await apiRequest<SigningKeySetResponse>('GET', '/api/v1/offline/signing-keys')).data
}

export async function enrollDevice(body: Record<string, unknown>): Promise<EnrolledDevice> {
  return (await write<EnrolledDeviceResponse>('/api/v1/devices/enroll', body)).data
}

export async function issueOfflineGrant(
  deviceId: string,
  userId: string,
  validHours: number | null = null,
): Promise<OfflineGrant> {
  const body: Record<string, unknown> = { expected_version: null, user_id: userId }
  if (validHours !== null) body['valid_hours'] = validHours
  return (
    await write<OfflineGrantResponse>(
      `/api/v1/devices/${encodeURIComponent(deviceId)}/grants`,
      body,
    )
  ).data
}

/** Corps de la préparation des ressources d'un poste (O03, gérante) : profils préparés, plages, quotas, factures. */
export interface PrepareDeviceInput {
  profiles: string[]
  number_ranges?: { document_type: string; quantity: number; document_year?: number | null }[]
  stock_quotas?: { stock_position_id: string; stock_lot_id: string | null; quantity: string }[]
  invoice_ids?: string[]
}

/** Réserve plages, quotas et propriétés de facture pour ce poste ; rend la préparation complète à conserver. */
export async function prepareDeviceResources(
  deviceId: string,
  input: PrepareDeviceInput,
): Promise<Preparation> {
  return (
    await write<PreparationResponse>(`/api/v1/devices/${encodeURIComponent(deviceId)}/prepare`, {
      expected_version: null,
      ...input,
    })
  ).data
}

export async function getDevicePreparation(deviceId: string): Promise<Preparation> {
  return (
    await apiRequest<PreparationResponse>(
      'GET',
      `/api/v1/devices/${encodeURIComponent(deviceId)}/preparation`,
    )
  ).data
}

/** Clôture après reprise complète : séquence et empreinte du journal du poste, refus 409 sinon (rien n'est libéré). */
export async function releaseDeviceResources(
  deviceId: string,
  input: { last_device_sequence: string; last_event_hash: string | null; reason: string },
): Promise<Release> {
  return (
    await write<ReleaseResponse>(`/api/v1/devices/${encodeURIComponent(deviceId)}/release`, {
      expected_version: null,
      ...input,
    })
  ).data
}

export async function revokeDevice(
  deviceId: string,
  status: 'REVOKED' | 'LOST',
  reason: string,
): Promise<EnrolledDevice> {
  return (
    await write<EnrolledDeviceResponse>(`/api/v1/devices/${encodeURIComponent(deviceId)}/revoke`, {
      expected_version: null,
      status,
      reason,
    })
  ).data
}

export async function revokeOfflineGrant(grantId: string, reason: string): Promise<OfflineGrant> {
  return (
    await write<OfflineGrantResponse>(
      `/api/v1/offline-grants/${encodeURIComponent(grantId)}/revoke`,
      {
        expected_version: null,
        reason,
      },
    )
  ).data
}

/**
 * Remplacement d'un poste par ce poste neuf (O07) : l'ancien passe REPLACED, ses grants sont révoqués et ses ressources
 * passent CLOSING ; aucun droit n'est transféré.
 */
export async function replaceDevice(
  oldDeviceId: string,
  replacementDeviceId: string,
  reason: string,
): Promise<Replacement> {
  return (
    await write<ReplacementResponse>(`/api/v1/devices/${encodeURIComponent(oldDeviceId)}/replace`, {
      expected_version: null,
      replacement_device_id: replacementDeviceId,
      reason,
    })
  ).data
}

/** Lot de commandes signées de l'ancien poste, transmis tel quel (mêmes identifiants, numéros et signatures). */
export async function pushRecoveredCommands(
  oldDeviceId: string,
  body: PushRequest,
): Promise<PushResponse> {
  return write<PushResponse>(
    `/api/v1/devices/${encodeURIComponent(oldDeviceId)}/recovered-push`,
    body,
  )
}

/** Réconciliation de l'ancien poste après la reprise complète de son journal exporté (O07). */
export async function reconcileReplacement(
  oldDeviceId: string,
  input: { last_device_sequence: string; last_event_hash: string | null; reason: string },
): Promise<Release> {
  return (
    await write<ReleaseResponse>(
      `/api/v1/devices/${encodeURIComponent(oldDeviceId)}/reconcile-replacement`,
      { expected_version: null, ...input },
    )
  ).data
}

/** Déclaration de compatibilité de la release du serveur (O06). */
export async function getSyncCompatibility(): Promise<CompatibilityDeclaration> {
  return (await apiRequest<CompatibilityResponse>('GET', '/api/v1/sync/compatibility')).data
}
