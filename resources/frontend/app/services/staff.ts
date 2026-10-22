import { ApiError, apiRequest, prepareSession } from '@/app/api'
import type {
  ClinicDocumentIdentity,
  ClinicDocumentIdentityResponse,
  ClinicSettings,
  ClinicSettingsResponse,
  StaffMember,
  StaffMemberPage,
  StaffMemberResponse,
} from '@/domain/contracts/generated'

import type { ReadOutcome } from './reads'

/**
 * Comptes du personnel et paramètres du cabinet (F05 côté serveur, écrans F09) : gérante seulement
 * (`users.manage`), écritures après réauthentification (423 `REAUTHENTICATION_REQUIRED` tant que le mot de
 * passe n'a pas été confirmé récemment dans cette session : l'écran le demande, puis rejoue la même écriture).
 *
 * Ces routes n'ont pas de clé d'idempotence (contrat F06 : `x-idempotency: none`) : une modification porte
 * la version attendue du compte (409 si elle a changé), une création est protégée par l'unicité de
 * l'identifiant. Une reprise après une réponse incertaine relit donc l'état avant de renvoyer. Aucun mot de
 * passe n'est conservé par ce service : il part dans le corps de la requête, rien de plus.
 */
function answered<T>(data: T): ReadOutcome<T> {
  return { data, freshness: { serverAsOf: new Date().toISOString(), complete: true }, live: true }
}

/** Écriture : un jeton CSRF périmé (419) est renouvelé une fois avant de conclure. */
async function write<T>(method: 'POST' | 'PATCH', path: string, body: unknown): Promise<T> {
  try {
    return await apiRequest<T>(method, path, body)
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 419) throw error
    await prepareSession()
    return apiRequest<T>(method, path, body)
  }
}

export async function listStaff(page = 1): Promise<ReadOutcome<StaffMemberPage>> {
  if (!Number.isSafeInteger(page) || page < 1) throw new RangeError('Page invalide.')
  return answered(await apiRequest<StaffMemberPage>('GET', `/api/v1/staff?page=${page}`))
}

export async function getStaffMember(id: string): Promise<ReadOutcome<StaffMember>> {
  const response = await apiRequest<StaffMemberResponse>(
    'GET',
    `/api/v1/staff/${encodeURIComponent(id)}`,
  )
  return answered(response.data)
}

export interface CreateStaffInput {
  login: string
  display_name: string
  email: string | null
  password: string
  roles: string[]
}

export async function createStaffMember(input: CreateStaffInput): Promise<StaffMember> {
  return (await write<StaffMemberResponse>('POST', '/api/v1/staff', input)).data
}

export interface UpdateStaffInput {
  expected_version: number
  display_name?: string
  email?: string | null
  active?: boolean
  roles?: string[]
  reason?: string | null
}

export async function updateStaffMember(id: string, input: UpdateStaffInput): Promise<StaffMember> {
  return (
    await write<StaffMemberResponse>('PATCH', `/api/v1/staff/${encodeURIComponent(id)}`, input)
  ).data
}

export interface PermissionOverrideInput {
  expected_version: number
  permission_code: string
  effect: 'ALLOW' | 'DENY' | 'CLEAR'
  reason: string
  /** Instant UTC `AAAA-MM-JJTHH:MM:SS.mmmZ`, jamais une date civile convertie à minuit UTC. */
  expires_at: string | null
}

export async function setPermissionOverride(
  id: string,
  input: PermissionOverrideInput,
): Promise<StaffMember> {
  return (
    await write<StaffMemberResponse>(
      'POST',
      `/api/v1/staff/${encodeURIComponent(id)}/permissions`,
      input,
    )
  ).data
}

export interface ResetPasswordInput {
  expected_version: number
  password: string
  password_confirmation: string
  reason: string
}

/** Réinitialisation par la gérante (jamais pour un compte gérante : procédure de secours sur le serveur). */
export async function resetStaffPassword(id: string, input: ResetPasswordInput): Promise<void> {
  await write<null>('POST', `/api/v1/staff/${encodeURIComponent(id)}/password`, input)
}

export interface ChangeOwnPasswordInput {
  current_password: string
  password: string
  password_confirmation: string
}

/**
 * Changer SON mot de passe (tout compte connecté, `POST /auth/password`) : la session courante reste
 * ouverte, les autres sessions du compte sont fermées par le serveur.
 */
export async function changeOwnPassword(input: ChangeOwnPasswordInput): Promise<void> {
  await write<null>('POST', '/api/v1/auth/password', input)
}

export async function getClinicSettings(): Promise<ReadOutcome<ClinicSettings>> {
  return answered((await apiRequest<ClinicSettingsResponse>('GET', '/api/v1/clinic/settings')).data)
}

export interface UpdateClinicSettingsInput {
  name?: string
  address?: string | null
  phone?: string | null
  email?: string | null
  tax_identifier?: string | null
  professional_mentions?: string | null
  document_settings?: Record<string, unknown> | null
}

export async function updateClinicSettings(
  input: UpdateClinicSettingsInput,
): Promise<ClinicSettings> {
  return (await write<ClinicSettingsResponse>('PATCH', '/api/v1/clinic/settings', input)).data
}

/**
 * Identité du cabinet imprimée sur les documents (en-tête, pied) : lisible par tout profil qui produit un
 * document (le professionnel pour une ordonnance, la caisse pour un reçu), pas seulement la gérante.
 */
export async function getClinicDocumentIdentity(): Promise<ClinicDocumentIdentity> {
  return (await apiRequest<ClinicDocumentIdentityResponse>('GET', '/api/v1/clinic/document-identity'))
    .data
}
