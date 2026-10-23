import { encodeAad } from '../crypto/aad'
import { buffer, type RandomBytes, toHex, utf8, webRandom } from '../crypto/bytes'
import { LOCAL_SCHEMA_VERSION } from '../migrations/registry'
import type { AllocationRow, CommandStatusRow, EnvelopeRow, HeadRow, JournalRow } from '../vault/db'
import type { Vault } from '../vault/vault'
import { newUlid } from '../../domain/values/ulid'

import {
  type DeviceExportFile,
  encodeJson,
  EXPORT_FORMAT,
  EXPORT_VERSION,
  type ExportEnvelope,
  type ExportManifest,
  exportFileName,
  sealedText,
} from './archive'

/**
 * Export chiffré du poste (O07 ; fichier 19 : « persistance demandée n'est pas sauvegarde » ; export à la clôture et lors
 * d'une coupure prolongée). Exige un profil ouvert (au moins un compartiment) : la clé de l'export est scellée sous une
 * clé de compartiment de la session. Le contenu (journal commun complet avec ses charges scellées, états, têtes,
 * ressources, pièces PDF et brouillons chiffrés, reprises) reste chiffré par compartiment ET est chiffré en bloc sous la
 * clé de l'export. Seule la clé de récupération de la gérante rouvre l'ensemble, sur un autre poste.
 *
 * Rien n'est modifié dans le coffre, sauf la trace de l'export (`last_export`, journal de sécurité) : un export ne
 * supprime ni n'acquitte aucune commande. Le fichier est rendu au navigateur, qui le fait télécharger : le support
 * (clé USB chiffrée, coffre) relève de la procédure (docs/runbooks/local-recovery.md).
 */
export interface DeviceExport {
  fileName: string
  text: string
  manifest: ExportManifest
}

export interface ExportOptions {
  release: string
  now: () => number
  random?: RandomBytes
  subtle?: SubtleCrypto
}

const FINAL = ['ACCEPTED', 'REJECTED']

function envelopeOf(row: EnvelopeRow): ExportEnvelope {
  return {
    id: row.id,
    slot: row.slot,
    key_id: row.keyId,
    format: row.format,
    kek_version: row.kekVersion,
    wrapped: sealedText(row.wrapped),
  }
}

export function buildManifest(input: {
  exportId: string
  createdAt: string
  release: string
  identity: { clinicId: string; vaultId: string; deviceId: string | null }
  rows: Record<string, unknown[]>
}): ExportManifest {
  const journal = (input.rows['command_journal'] ?? []) as JournalRow[]
  const statuses = new Map(
    ((input.rows['command_status'] ?? []) as CommandStatusRow[]).map((row) => [
      row.commandId,
      row.state,
    ]),
  )
  const pending = journal.filter((row) => !FINAL.includes(statuses.get(row.commandId) ?? 'LOCAL'))
  const head = ((input.rows['aggregate_heads'] ?? []) as HeadRow[]).find(
    (row) => row.key === 'journal',
  )
  const last = journal.reduce<JournalRow | null>(
    (best, row) => (best === null || row.deviceSequence > best.deviceSequence ? row : best),
    null,
  )
  const byCompartment: Record<string, number> = {}
  for (const row of pending)
    byCompartment[row.compartment] = (byCompartment[row.compartment] ?? 0) + 1
  const counts: Record<string, number> = {}
  for (const [table, list] of Object.entries(input.rows)) counts[table] = list.length
  const ranges = ((input.rows['allocations'] ?? []) as AllocationRow[])
    .filter(
      (row): row is Extract<AllocationRow, { kind: 'NUMBER_RANGE' }> => row.kind === 'NUMBER_RANGE',
    )
    .map((row) => ({
      document_type: row.documentType,
      document_year: row.documentYear,
      range_start: row.rangeStart,
      range_end: row.rangeEnd,
      next_value: row.nextValue,
      status: row.status,
    }))
  const firstPending = pending.reduce<JournalRow | null>(
    (best, row) => (best === null || row.deviceSequence < best.deviceSequence ? row : best),
    null,
  )
  return {
    export_id: input.exportId,
    created_at: input.createdAt,
    release: input.release,
    clinic_id: input.identity.clinicId,
    vault_id: input.identity.vaultId,
    device_id: input.identity.deviceId,
    local_schema_version: LOCAL_SCHEMA_VERSION,
    journal: {
      commands: journal.length,
      pending: pending.length,
      last_device_sequence: String(head?.deviceSequence ?? last?.deviceSequence ?? 0),
      last_event_hash: head?.eventHash ?? last?.eventHash ?? null,
      first_pending_sequence: firstPending === null ? null : String(firstPending.deviceSequence),
    },
    pending_by_compartment: byCompartment,
    counts,
    number_ranges: ranges,
  }
}

export async function exportDevice(vault: Vault, options: ExportOptions): Promise<DeviceExport> {
  const subtle = options.subtle ?? globalThis.crypto.subtle
  const random = options.random ?? webRandom
  const material = await vault.exportMaterial()
  const rows = await vault.exportRows()
  const exportId = newUlid()
  const createdAt = new Date(options.now()).toISOString()
  const identity = material.identity
  const manifest = buildManifest({ exportId, createdAt, release: options.release, identity, rows })

  const plaintext = utf8(
    encodeJson({ manifest, identity, keys: material.keys, format: material.format, rows }),
  )
  const digest = toHex(new Uint8Array(await subtle.digest('SHA-256', buffer(plaintext))))
  const raw = random(32)
  const contentKey = await subtle.importKey(
    'raw',
    buffer(raw),
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt'],
  )
  const nonce = random(12)
  const ciphertext = new Uint8Array(
    await subtle.encrypt(
      {
        name: 'AES-GCM',
        iv: buffer(nonce),
        additionalData: buffer(
          encodeAad('export', [
            identity.clinicId,
            identity.vaultId,
            'content',
            exportId,
            createdAt,
          ]),
        ),
        tagLength: 128,
      },
      contentKey,
      buffer(plaintext),
    ),
  )
  const exportKey = await vault.sealExportKey(raw, exportId, createdAt)
  raw.fill(0)

  const file: DeviceExportFile = {
    format: EXPORT_FORMAT,
    version: EXPORT_VERSION,
    export_id: exportId,
    created_at: createdAt,
    release: options.release,
    clinic_id: identity.clinicId,
    vault_id: identity.vaultId,
    device_id: identity.deviceId,
    local_schema_version: LOCAL_SCHEMA_VERSION,
    recovery: {
      verifier: material.recoveryVerifier,
      envelopes: material.recoveryEnvelopes.map(envelopeOf),
    },
    export_key: {
      compartment: exportKey.compartment,
      key_id: exportKey.keyId,
      sealed: sealedText(exportKey.sealed),
    },
    content: { sealed: sealedText({ nonce, ciphertext }), sha256: digest },
  }
  const text = JSON.stringify(file)
  await vault.noteExport({
    exportId,
    at: createdAt,
    pending: manifest.journal.pending,
    bytes: text.length,
  })
  return { fileName: exportFileName(identity.deviceId, createdAt), text, manifest }
}
