import Dexie, { type Table } from 'dexie'

import type { Compartment } from '../compartments'
import { declareLocalSchema, LOCAL_SCHEMA_VERSION } from '../migrations/registry'

import { VaultError } from './errors'

/**
 * Base IndexedDB du poste `eeo-device` (vault.md « Tables locales », D20, D21). Les index ne portent que des
 * identifiants opaques et des classifications ; aucun nom de patient, téléphone ni contenu clinique n'y figure.
 * Le nom et l'identifiant (login) des PROFILS du poste restent en clair (limite déclarée : il faut les lister à
 * l'écran de déverrouillage).
 *
 * Versions du schéma : AJOUTS seulement (D21) ; une version ne détruit jamais une table tant que des pièces
 * attendent. Version 1 : coffre (O01). Version 2 : grants signés du poste (O02). Version 3 : allocations (O03),
 * journal commun, état des commandes et têtes CAS (O04). Version 4 : curseurs de pull par profil (O05). Version 5 :
 * reprise d'un poste remplacé (O07). Les déclarations viennent du registre `offline/migrations/registry.ts` (O06).
 *
 * Durabilité : `chromeTransactionDurability: 'strict'` (D20) ; Firefox et Safari restent relâchés (prescription
 * matérielle au runbook).
 */
export const VAULT_DB_NAME = 'eeo-device'

/** Format du coffre lu et écrit par ce client (D21). */
export const VAULT_FORMAT_VERSION = 1
export const VAULT_MIN_WRITER_VERSION = 1

export interface SealedRow {
  nonce: Uint8Array
  ciphertext: Uint8Array
}

export interface MetaRow {
  key: string
  value: unknown
}

export interface ProfileRow {
  id: string
  userId: string
  login: string
  displayName: string
  state: 'ACTIVE' | 'REMOVED'
  kdf: { salt: string; iterations: number; normalization: 'NFKC' }
  kekVersion: number
  kcv: SealedRow
  attempts: { failures: number; blockedUntil: number | null }
  createdAt: string
  updatedAt: string
}

export type EnvelopeSlot = Compartment | 'DEVICE_SIGNING'

export interface EnvelopeRow {
  /** `<propriétaire>:<emplacement>:<keyId>` ; propriétaire = identifiant du profil ou `RECOVERY`. */
  id: string
  profileId: string
  slot: EnvelopeSlot
  keyId: number
  format: 'raw' | 'pkcs8'
  wrapped: SealedRow
  kekVersion: number
}

export interface EntityRow extends SealedRow {
  /** `<type>:<identifiant>` (identifiant opaque). */
  id: string
  compartment: Compartment
  type: string
  entityId: string
  version: number
  keyId: number
}

export interface DocumentRow {
  /** Clé de la pièce (`type|source|révision`, identifiants opaques seulement). */
  id: string
  compartment: Compartment
  kind: string
  entityId: string | null
  version: number
  keyId: number
  meta: SealedRow
  content: SealedRow | null
}

export interface DraftRow extends SealedRow {
  id: string
  profileId: string
  compartment: Compartment
  version: number
  keyId: number
  savedAt: string
}

export interface EventRow {
  seq?: number
  type: string
  at: string
  profileId: string | null
  detail: Record<string, string | number | boolean | null>
}

export interface GrantRow {
  grantId: string
  userId: string
  deviceId: string
  /** JWS compact signé par le serveur, tel que reçu. */
  jws: string
  /** Charge vérifiée : identifiants, permissions, compartiments, versions, validité ; aucune donnée personnelle. */
  claims: Record<string, unknown>
  validUntil: string
  storedAt: string
}

/**
 * Ressource préparée par le serveur pour ce poste (O03), en clair : identifiants opaques, séries, bornes de numéros,
 * quantités, propriétés de facture. Aucune donnée personnelle (limite déclarée « métadonnées en clair »). Le curseur
 * local `nextValue` d'une plage ne recule JAMAIS (une nouvelle préparation ne le ramène pas en arrière) ; il avance
 * dans la même transaction que la pièce qui consomme le numéro (O04).
 */
export type AllocationRow =
  | {
      id: string
      kind: 'NUMBER_RANGE'
      status: 'ACTIVE' | 'CLOSING' | 'CLOSED'
      grantId: string
      documentType: string
      documentYear: number
      rangeStart: number
      rangeEnd: number
      /** Prochain numéro libre de ce poste ; > rangeEnd : plage épuisée. */
      nextValue: number
      prefix: string
      width: number
      version: number
      updatedAt: string
    }
  | {
      id: string
      kind: 'STOCK_QUOTA'
      status: 'ACTIVE' | 'CLOSING' | 'CLOSED'
      itemId: string
      stockPositionId: string
      stockLotId: string | null
      /** Quantités DECIMAL(12,3) en millièmes (entiers exacts). */
      allocatedUnits: number
      consumedUnits: number
      returnedUnits: number
      reservedLocalUnits: number
      consumedLocalUnits: number
      version: number
      updatedAt: string
    }
  | {
      id: string
      kind: 'WRITE_OWNER'
      status: 'ACTIVE' | 'CLOSING' | 'CLOSED'
      invoiceId: string
      epoch: number
      version: number
      updatedAt: string
    }

/**
 * Entrée du journal COMMUN du poste (contrat 23 « Journal local et commit ») : un seul journal ordonné par
 * `deviceSequence`, commun aux profils. Métadonnées de routage en clair (identifiants opaques, nom de commande,
 * compartiment, séquences, empreintes) ; la charge métier est SCELLÉE dans le compartiment de la commande. Une entrée
 * n'est JAMAIS supprimée : son état vit dans `command_status`, et seul un ACK final (O05) la retire de la file active.
 */
export interface JournalRow {
  commandId: string
  deviceSequence: number
  idempotencyKey: string
  /** SHA-256 de la charge (JCS) de l'intention : un même clé avec une autre charge est un conflit. */
  intentHash: string
  commandName: string
  compartment: Compartment
  /** Cœur sans la charge (`payload` scellée à part) : champs du contrat 23, compteurs en chaînes décimales. */
  core: Record<string, unknown>
  previousEventHash: string | null
  eventHash: string
  signature: string
  payload: SealedRow & { keyId: number }
  entityType: string
  entityId: string
  recordedAt: string
}

export type CommandState =
  'LOCAL' | 'SENT' | 'ACCEPTED' | 'WAITING_DEPENDENCY' | 'CONFLICT_REQUIRES_REVIEW' | 'REJECTED'

export interface CommandStatusRow {
  commandId: string
  state: CommandState
  attempts: number
  updatedAt: string
  /** Références rendues par le serveur à l'ACK (identifiants, versions), jamais de donnée clinique. */
  references: Record<string, unknown> | null
}

/** Têtes CAS du poste : `journal` (dernière séquence et empreinte). */
export interface HeadRow {
  key: string
  deviceSequence: number
  eventHash: string | null
  updatedAt: string
}

/**
 * Curseur de pull d'un PROFIL (O05) : opaque et signé par le serveur, propre au poste, au compte et à ses
 * compartiments. Il n'avance qu'avec le commit local de TOUTE la page qu'il borne (même transaction).
 */
export interface PullCursorRow {
  userId: string
  cursor: string
  pulledAt: string
}

/**
 * Export d'un ANCIEN poste ouvert sur ce poste (O07) : identifiants opaques, manifeste vérifié (comptes, séquences,
 * empreintes ; aucune donnée personnelle), état de la reprise. Jamais un droit ni une clé de l'ancien poste.
 */
export interface RecoveredExportRow {
  exportId: string
  oldDeviceId: string
  oldVaultId: string
  createdAt: string
  importedAt: string
  /** Manifeste de l'export (comptes, dernière séquence et empreinte du journal exporté). */
  manifest: Record<string, unknown>
  state: 'IMPORTED' | 'PUSHED' | 'RECONCILED'
  updatedAt: string
}

/**
 * Commande SIGNÉE de l'ancien poste à transmettre telle quelle (O07) : cœur complet (avec sa charge) scellé dans CE
 * coffre (compartiment d'origine), empreinte et signature d'origine en clair. Jamais supprimée : son état suit l'ACK.
 */
export interface RecoveredCommandRow {
  commandId: string
  oldDeviceId: string
  exportId: string
  deviceSequence: number
  compartment: Compartment
  keyId: number
  sealed: SealedRow
  eventHash: string
  /** `FINAL_BEFORE_EXPORT` : déjà acquittée sur l'ancien poste (conservée pour répondre à un rang manquant). */
  state:
    | 'PENDING'
    | 'SENT'
    | 'ACCEPTED'
    | 'WAITING_DEPENDENCY'
    | 'CONFLICT_REQUIRES_REVIEW'
    | 'REJECTED'
    | 'FINAL_BEFORE_EXPORT'
  updatedAt: string
}

export class VaultDatabase extends Dexie {
  vault_meta!: Table<MetaRow, string>
  profiles!: Table<ProfileRow, string>
  key_envelopes!: Table<EnvelopeRow, string>
  encrypted_entities!: Table<EntityRow, string>
  encrypted_documents!: Table<DocumentRow, string>
  encrypted_drafts!: Table<DraftRow, string>
  vault_events!: Table<EventRow, number>
  grants!: Table<GrantRow, string>
  allocations!: Table<AllocationRow, string>
  command_journal!: Table<JournalRow, string>
  command_status!: Table<CommandStatusRow, string>
  aggregate_heads!: Table<HeadRow, string>
  pull_cursors!: Table<PullCursorRow, string>
  recovered_exports!: Table<RecoveredExportRow, string>
  recovered_commands!: Table<RecoveredCommandRow, string>

  /**
   * `schemaVersion` : version du registre à déclarer (tests de migration seulement ; la production ouvre toujours la
   * version courante). Une base plus RÉCENTE que ce client refuse de s'ouvrir (Dexie `VersionError`) : jamais purgée.
   */
  constructor(
    name: string = VAULT_DB_NAME,
    options: ConstructorParameters<typeof Dexie>[1] = {},
    schemaVersion: number = LOCAL_SCHEMA_VERSION,
  ) {
    super(name, { chromeTransactionDurability: 'strict', ...options })
    declareLocalSchema(this, schemaVersion)
    // Dexie 4 ouvre une base plus récente avec le schéma déclaré : ce client plus ancien la refuse (rien n'est
    // écrit ni purgé) au lieu de la modifier avec un schéma qu'il ne connaît pas.
    this.on('ready', () => {
      // Dexie multiplie par 10 la version IndexedDB qu'il déclare.
      if (Math.round(this.backendDB().version / 10) > schemaVersion)
        throw new VaultError('VAULT_FORMAT_TOO_NEW')
    })
  }
}
