import index from '../../../../contracts/commands/index.json'
import { buffer, toHex, utf8 } from '../crypto/bytes'
import { canonicalize } from '../signing/jcs'

/**
 * Cœur d'une commande du journal du poste (contrat 23 « Signature et chiffrement » ; `contracts/sync/command-core.json`).
 * Toutes les clés sont présentes (null plutôt qu'absent) pour que `event_hash` soit identique en PHP et en
 * navigateur ; les compteurs sont des chaînes décimales sans zéro non significatif ; les montants sont des entiers sûrs.
 *
 *   event_hash = SHA-256 (hexadécimal minuscule) des octets UTF-8 de JCS(core) ;
 *   signature  = JWS compact ES256 (typ `eeo-command+jws`, kid du poste) portant {command_id, device_id, event_hash}.
 *
 * Le cœur est calculé, haché et signé HORS de toute transaction IndexedDB (contrat 23) ; la transaction ne fait que
 * revérifier la tête du journal et écrire.
 */
export const COMMAND_JWS_TYPE = 'eeo-command+jws'

export interface CommandCore {
  command_id: string
  command_name: string
  device_id: string
  actor_id: string
  grant_id: string
  clinic_id: string
  device_sequence: string
  previous_event_hash: string | null
  schema_version: string
  rules_version: string
  occurred_at_local: string
  last_server_time: string
  entity_type: string
  entity_id: string
  expected_version: number | null
  number_allocation_id: string | null
  stock_allocation_ids: string[]
  payload: Record<string, unknown>
}

export type ExpectedVersionKind = 'none' | 'entity' | 'draft' | 'ledger' | 'state'

export interface JournalContract {
  name: string
  schemaVersion: string
  entityType: string
  entityIdField: string
  permission: string
  expectedVersion: ExpectedVersionKind
  numberType: string | null
}

interface RawEntry {
  schema_version: string
  entity_type: string
  entity_id_field: string
  permission: string
  expected_version: string
  number_type?: string | null
}

const RAW = (index as { const: Record<string, RawEntry> }).const

/** Champ du numéro de pièce dans la charge, par série (même table que `app/services/commandIndex.ts`). */
export const NUMBER_FIELDS: Readonly<Record<string, string>> = {
  PATIENT: 'record_number',
  INVOICE: 'number',
  RECEIPT: 'receipt_number',
  CREDIT_NOTE: 'number',
  REFUND: 'number',
}

/** Contrat d'une commande du journal (lu dans `contracts/commands/index.json`), ou null si elle n'en a pas. */
export function journalContract(name: string): JournalContract | null {
  const raw = RAW[name]
  if (raw === undefined) return null
  const kinds: readonly ExpectedVersionKind[] = ['none', 'entity', 'draft', 'ledger', 'state']
  const kind = kinds.find((candidate) => candidate === raw.expected_version)
  if (kind === undefined) throw new Error(`Contrat de commande illisible : ${name}.`)
  return {
    name,
    schemaVersion: raw.schema_version,
    entityType: raw.entity_type,
    entityIdField: raw.entity_id_field,
    permission: raw.permission,
    expectedVersion: kind,
    numberType: raw.number_type ?? null,
  }
}

/** Compteur BIGINT du contrat : chaîne de chiffres sans zéro non significatif. */
export function counter(value: number): string {
  if (!Number.isSafeInteger(value) || value < 0)
    throw new RangeError('Compteur hors de la plage sûre.')
  return String(value)
}

async function sha256Hex(text: string, subtle: SubtleCrypto): Promise<string> {
  return toHex(new Uint8Array(await subtle.digest('SHA-256', buffer(utf8(text)))))
}

export async function eventHash(
  core: CommandCore,
  subtle: SubtleCrypto = globalThis.crypto.subtle,
): Promise<string> {
  return sha256Hex(canonicalize(core), subtle)
}

/** Empreinte de l'INTENTION (nom, charge sans numéro attribué localement, version attendue) : rejeu de la même clé. */
export async function intentHash(
  name: string,
  payload: Readonly<Record<string, unknown>>,
  expectedVersion: number | null,
  subtle: SubtleCrypto = globalThis.crypto.subtle,
): Promise<string> {
  return sha256Hex(
    canonicalize({ command_name: name, expected_version: expectedVersion, payload }),
    subtle,
  )
}

/** Cœur sans la charge, conservé EN CLAIR dans le journal (la charge est scellée à part dans son compartiment). */
export function routingCore(core: CommandCore): Record<string, unknown> {
  const routing: Record<string, unknown> = { ...core }
  delete routing['payload']
  return routing
}

/** Cœur complet reconstitué (O05) à partir des métadonnées en clair et de la charge déchiffrée. */
export function assembleCore(
  routing: Readonly<Record<string, unknown>>,
  payload: Record<string, unknown>,
): CommandCore {
  return { ...(routing as Omit<CommandCore, 'payload'>), payload }
}
