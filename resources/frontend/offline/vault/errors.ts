/**
 * Erreurs typées du coffre (vault.md, « Opérations ») : code STABLE, message sans aucun secret ni donnée patient.
 * Les écrans traduisent le code ; ils n'affichent jamais un détail technique de chiffrement.
 */
export type VaultErrorCode =
  | 'INSECURE_CONTEXT'
  | 'WEBCRYPTO_SELFTEST_FAILED'
  | 'VAULT_ABSENT'
  | 'VAULT_ALREADY_EXISTS'
  | 'VAULT_DAMAGED'
  | 'VAULT_FORMAT_TOO_NEW'
  | 'VAULT_LOCKED'
  | 'VAULT_SESSION_STALE'
  | 'AUTH_FAILED'
  | 'AUTH_THROTTLED'
  | 'KDF_PARAMS_REJECTED'
  | 'SECRET_TOO_WEAK'
  | 'STEP_UP_FAILED'
  | 'COMPARTMENT_NOT_AVAILABLE'
  | 'COMPARTMENT_MISMATCH'
  | 'ENTITY_TYPE_UNREGISTERED'
  | 'PROFILE_WITHOUT_COMPARTMENT'
  | 'PROFILE_EXISTS'
  | 'PROFILE_NOT_FOUND'
  | 'LAST_MANAGER_REQUIRED'
  | 'ROW_DECRYPT_FAILED'
  | 'ENVELOPE_CORRUPT'
  | 'RECOVERY_KEY_INVALID'
  | 'RECOVERY_KEY_MISMATCH'
  | 'VERSION_NOT_NEWER'
  | 'STORAGE_NOT_DURABLE'
  | 'DEVICE_ALREADY_BOUND'
  | 'DEVICE_NOT_BOUND'
  | 'SIGNING_KEY_ABSENT'
  | 'GRANT_INVALID'
  /** O04 : aucune écriture locale sans grant signé valide (session en lecture seule ou horloge suspecte). */
  | 'GRANT_REQUIRED'
  /** O04 : la commande n'appartient pas aux droits du grant de la session. */
  | 'COMMAND_NOT_GRANTED'
  /** O04 : aucune plage de numéros active pour cette série et cette année, ou plage épuisée. */
  | 'NUMBER_RANGE_EXHAUSTED'
  /** O04 : même clé d'idempotence, autre charge : nouvelle intention, nouvelle clé exigée. */
  | 'IDEMPOTENCY_PAYLOAD_MISMATCH'
  /** O04 : la version attendue ne correspond plus à la version locale (la saisie est conservée). */
  | 'LOCAL_VERSION_CONFLICT'
  /** O04 : le journal a avancé pendant le calcul plus de fois que le nombre de reprises admis. */
  | 'LOCAL_COMMIT_CONTENDED'
  /** O04 : facture dont ce poste n'est pas propriétaire (encaissement sur la caisse propriétaire). */
  | 'WRITE_OWNER_REQUIRED'
  /** O04 : quota local insuffisant pour la réserve demandée. */
  | 'LOCAL_QUOTA_EXCEEDED'

export interface VaultErrorDetail {
  /** Instant UTC (ms) à partir duquel une nouvelle tentative est admise (AUTH_THROTTLED). */
  retryAt?: number
  table?: string
  id?: string
  profileId?: string
  slot?: string
}

export class VaultError extends Error {
  readonly code: VaultErrorCode
  readonly detail: VaultErrorDetail

  constructor(code: VaultErrorCode, detail: VaultErrorDetail = {}) {
    super(`Coffre local : ${code}`)
    this.name = 'VaultError'
    this.code = code
    this.detail = detail
  }
}

export function isVaultError(error: unknown, code?: VaultErrorCode): error is VaultError {
  return error instanceof VaultError && (code === undefined || error.code === code)
}
