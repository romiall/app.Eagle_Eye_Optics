import { type Compartment, isCompartment } from '../compartments'
import { type PublicJwk, JwsRefused, thumbprint, verifyCompact } from '../signing/jws'
import type { GrantRow } from '../vault/db'
import { VaultError } from '../vault/errors'
import type { GrantCheck, SessionAuthority, Vault } from '../vault/vault'

/**
 * Enrôlement du poste et grants signés, côté poste (O02 ; fichier 19, « Préparation du poste » ; fichier 23,
 * « Signature et chiffrement »). Aucun appel réseau ici : les services d'application transportent les requêtes ;
 * ce module prépare ce qui part (clé publique, preuve de possession signée par la clé du poste) et vérifie ce qui
 * revient (grants JWS ES256 signés par une clé du serveur ÉPINGLÉE dans le coffre) avant de les conserver.
 *
 * Un grant n'est accepté que si : signature valide pour une clé épinglée désignée par son `kid`, en-tête exact,
 * charge JCS, `v = 1`, cabinet, poste et compte attendus, compartiments connus. Son expiration ne le rend pas
 * invalide : elle passe la session en lecture seule (aucune nouvelle opération sensible, données déjà émises
 * conservées). Une heure locale antérieure à son émission (au-delà de 5 minutes) rend la session suspecte.
 */
export const GRANT_TYPE = 'eeo-offline-grant+jws'
export const DEVICE_PROOF_TYPE = 'eeo-device-proof+jws'

export interface PinnedKey {
  kid: string
  status: string
  jwk: PublicJwk
}

export interface GrantClaims {
  v: 1
  grant_id: string
  clinic_id: string
  device_id: string
  user_id: string
  permissions: string[]
  compartments: Compartment[]
  rules_version: string
  catalog_version: string
  catalog_sha256: string
  issued_at: string
  valid_until: string
}

const CLOCK_SKEW_MS = 5 * 60 * 1000

function headerKid(jws: string): string | null {
  try {
    const [header = ''] = jws.split('.')
    const decoded = JSON.parse(atob(header.replace(/-/g, '+').replace(/_/g, '/'))) as {
      kid?: unknown
    }
    return typeof decoded.kid === 'string' ? decoded.kid : null
  } catch {
    return null
  }
}

function asClaims(value: Record<string, unknown>): GrantClaims | null {
  const strings = [
    'grant_id',
    'clinic_id',
    'device_id',
    'user_id',
    'rules_version',
    'catalog_version',
    'catalog_sha256',
    'issued_at',
    'valid_until',
  ]
  if (value['v'] !== 1) return null
  if (strings.some((key) => typeof value[key] !== 'string')) return null
  const permissions = value['permissions']
  const compartments = value['compartments']
  if (!Array.isArray(permissions) || permissions.some((item) => typeof item !== 'string'))
    return null
  if (!Array.isArray(compartments) || compartments.some((item) => !isCompartment(item))) return null
  return value as unknown as GrantClaims
}

/**
 * Vérifie un grant reçu du serveur contre les clés épinglées et les attentes (cabinet, poste, compte). Lève
 * `JwsRefused` si le jeton ou sa charge ne conviennent pas.
 */
export async function verifyGrant(
  jws: string,
  pinned: readonly PinnedKey[],
  expected: { clinicId: string; deviceId: string; userId: string },
): Promise<GrantClaims> {
  const kid = headerKid(jws)
  const key = pinned.find((candidate) => candidate.kid === kid)
  if (kid === null || key === undefined)
    throw new JwsRefused('Grant signé par une clé non épinglée.')
  const claims = asClaims(await verifyCompact(jws, key.jwk, kid, GRANT_TYPE))
  if (claims === null) throw new JwsRefused('Charge de grant inattendue.')
  if (
    claims.clinic_id !== expected.clinicId ||
    claims.device_id !== expected.deviceId ||
    claims.user_id !== expected.userId
  ) {
    throw new JwsRefused('Grant d’un autre cabinet, d’un autre poste ou d’un autre compte.')
  }
  return claims
}

/** Autorité d'une session d'après la validité du grant et l'horloge du poste. */
export function authorityOf(claims: GrantClaims, now: number): SessionAuthority {
  if (now < Date.parse(claims.issued_at) - CLOCK_SKEW_MS) return 'clock-suspect'
  return now < Date.parse(claims.valid_until) ? 'granted' : 'read-only'
}

/** Contrôle des grants au déverrouillage, avec les clés épinglées dans le coffre. */
export function pinnedGrantCheck(
  pinned: () => Promise<readonly PinnedKey[]>,
  clinicId: () => Promise<string>,
): GrantCheck {
  return {
    async evaluate(rows: readonly GrantRow[], userId: string, deviceId: string, now: number) {
      const own = rows.filter((row) => row.userId === userId && row.deviceId === deviceId)
      if (own.length === 0)
        return {
          grant: null,
          compartments: null,
          authority: 'read-only' as SessionAuthority,
          invalid: false,
        }
      const keys = await pinned()
      const clinic = await clinicId()
      let best: { row: GrantRow; claims: GrantClaims } | null = null
      for (const row of own) {
        try {
          const claims = await verifyGrant(row.jws, keys, { clinicId: clinic, deviceId, userId })
          if (best === null || claims.issued_at > best.claims.issued_at) best = { row, claims }
        } catch {
          // Un grant altéré n'ouvre rien ; un seul grant valide suffit.
        }
      }
      if (best === null)
        return {
          grant: null,
          compartments: null,
          authority: 'read-only' as SessionAuthority,
          invalid: true,
        }
      return {
        grant: best.row,
        compartments: best.claims.compartments,
        authority: authorityOf(best.claims, now),
        invalid: false,
      }
    },
  }
}

export interface EnrollmentInput {
  deviceCode: string
  name: string
  responsibleUserId: string
  appVersion: string
  issuedAt: string
}

/**
 * Corps de `POST /devices/enroll` : clé publique du poste et preuve de possession signée par sa clé privée (session
 * ouverte du coffre). La clé du poste doit avoir été créée (`Vault.createSigningKey`).
 */
export async function enrollmentBody(
  vault: Vault,
  input: EnrollmentInput,
): Promise<Record<string, unknown>> {
  const jwk = await vault.signingPublicKey()
  if (jwk === null) throw new VaultError('SIGNING_KEY_ABSENT')
  const kid = await thumbprint(jwk)
  const proof = await vault.signJws(
    {
      clinic_id: await vault.clinicId(),
      device_code: input.deviceCode,
      issued_at: input.issuedAt,
      jwk_thumbprint: kid,
      purpose: 'enroll',
      v: 1,
    },
    kid,
    DEVICE_PROOF_TYPE,
  )
  return {
    expected_version: null,
    device_code: input.deviceCode,
    name: input.name,
    public_key_jwk: jwk,
    responsible_user_id: input.responsibleUserId,
    app_version: input.appVersion,
    proof,
  }
}

/** Conserve un grant reçu après vérification complète ; rend sa charge vérifiée. */
export async function acceptGrant(
  vault: Vault,
  grant: { grant_id: string; user_id: string; device_id: string; signed_payload: string },
  storedAt: string,
): Promise<GrantClaims> {
  const deviceId = await vault.deviceId()
  if (deviceId === null) throw new VaultError('DEVICE_NOT_BOUND')
  const claims = await verifyGrant(grant.signed_payload, await vault.serverKeys(), {
    clinicId: await vault.clinicId(),
    deviceId,
    userId: grant.user_id,
  })
  if (claims.grant_id !== grant.grant_id || grant.device_id !== deviceId)
    throw new JwsRefused('Grant incohérent avec sa ressource.')
  await vault.storeGrant({
    grantId: claims.grant_id,
    userId: claims.user_id,
    deviceId: claims.device_id,
    jws: grant.signed_payload,
    claims: { ...claims },
    validUntil: claims.valid_until,
    storedAt,
  })
  return claims
}
