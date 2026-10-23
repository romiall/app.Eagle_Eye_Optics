import { fromBase64Url, toBase64Url } from '../crypto/bytes'

/**
 * Format de l'export chiffré d'un poste (O07 ; fichier 19 : « Export chiffré des commandes/PDF à clôture et coupure
 * prolongée, sur support contrôlé ; clé de récupération scellée gérante » ; AT44).
 *
 * Fichier JSON `eeo-device-export` v1 :
 *  - en clair : identifiants opaques (cabinet, coffre, poste, export), instant, release, version du schéma local,
 *    vérificateur de la clé de récupération et enveloppes `RECOVERY` des clés de compartiment (déjà chiffrées sous la
 *    KEK de récupération, que seule la gérante redonne avec sa clé hors du poste) ;
 *  - `export_key` : clé aléatoire de l'export, scellée sous une clé de compartiment du coffre (rouvrable seulement par
 *    l'enveloppe `RECOVERY` de ce compartiment) ;
 *  - `content` : manifeste et lignes exportées, chiffrés AES-GCM sous la clé de l'export (AAD « export »), avec
 *    l'empreinte SHA-256 du texte clair pour détecter toute altération.
 * Aucune enveloppe de profil, aucune clé de signature du poste, aucun grant : un export ne clone aucun droit.
 */
export const EXPORT_FORMAT = 'eeo-device-export'
export const EXPORT_VERSION = 1

export interface SealedText {
  nonce: string
  ciphertext: string
}

export interface ExportEnvelope {
  id: string
  slot: string
  key_id: number
  format: 'raw' | 'pkcs8'
  kek_version: number
  wrapped: SealedText
}

export interface DeviceExportFile {
  format: typeof EXPORT_FORMAT
  version: typeof EXPORT_VERSION
  export_id: string
  created_at: string
  release: string
  clinic_id: string
  vault_id: string
  device_id: string | null
  local_schema_version: number
  recovery: { verifier: string; envelopes: ExportEnvelope[] }
  export_key: { compartment: string; key_id: number; sealed: SealedText }
  content: { sealed: SealedText; sha256: string }
}

/** Manifeste d'un export : comptes et repères du journal ; aucune donnée personnelle. */
export interface ExportManifest {
  export_id: string
  created_at: string
  release: string
  clinic_id: string
  vault_id: string
  device_id: string | null
  local_schema_version: number
  journal: {
    commands: number
    pending: number
    last_device_sequence: string
    last_event_hash: string | null
    first_pending_sequence: string | null
  }
  pending_by_compartment: Record<string, number>
  counts: Record<string, number>
  number_ranges: {
    document_type: string
    document_year: number
    range_start: number
    range_end: number
    next_value: number
    status: string
  }[]
}

export type RecoveryErrorCode =
  | 'EXPORT_UNREADABLE'
  | 'EXPORT_FORMAT_UNSUPPORTED'
  | 'RECOVERY_KEY_INVALID'
  | 'EXPORT_CORRUPT'
  | 'EXPORT_OTHER_CLINIC'
  | 'EXPORT_SAME_DEVICE'
  | 'EXPORT_WITHOUT_DEVICE'
  | 'RECOVERY_COMPARTMENT_MISSING'

/** Erreur typée de la reprise (code stable, aucun secret ni donnée dans le message). */
export class RecoveryError extends Error {
  constructor(readonly code: RecoveryErrorCode) {
    super(code)
    this.name = 'RecoveryError'
  }
}

export function isRecoveryError(error: unknown, code?: RecoveryErrorCode): error is RecoveryError {
  return error instanceof RecoveryError && (code === undefined || error.code === code)
}

/** JSON avec octets : un `Uint8Array` devient `{ "$b": base64url }` (et inversement). */
export function encodeJson(value: unknown): string {
  return JSON.stringify(value, (_key, item: unknown) =>
    item instanceof Uint8Array ? { $b: toBase64Url(item) } : item,
  )
}

export function decodeJson<T>(text: string): T {
  return JSON.parse(text, (_key, item: unknown) => {
    if (typeof item === 'object' && item !== null && !Array.isArray(item)) {
      const keys = Object.keys(item)
      const encoded = (item as { $b?: unknown }).$b
      if (keys.length === 1 && typeof encoded === 'string') return fromBase64Url(encoded)
    }
    return item
  }) as T
}

export function sealedText(sealed: { nonce: Uint8Array; ciphertext: Uint8Array }): SealedText {
  return { nonce: toBase64Url(sealed.nonce), ciphertext: toBase64Url(sealed.ciphertext) }
}

export function sealedBytes(text: SealedText): { nonce: Uint8Array; ciphertext: Uint8Array } {
  return { nonce: fromBase64Url(text.nonce), ciphertext: fromBase64Url(text.ciphertext) }
}

/** Nom du fichier proposé au téléchargement (identifiants opaques, instant UTC). */
export function exportFileName(deviceId: string | null, createdAt: string): string {
  const stamp = createdAt.replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z')
  const device = deviceId === null ? 'poste' : `poste-${deviceId.slice(-8).toLowerCase()}`
  return `eeo-${device}-${stamp}.eeo-export.json`
}
