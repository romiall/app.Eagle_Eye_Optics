import { type AadPart, encodeAad } from '../crypto/aad'
import { open, openJson } from '../crypto/aead'
import { buffer, fromUtf8, toHex } from '../crypto/bytes'
import { deriveRecoveryKek, unwrapAesKey } from '../crypto/kdf'
import { parseRecoveryKey } from '../crypto/secrets'
import type { CommandStatusRow, DocumentRow, JournalRow } from '../vault/db'

import {
  decodeJson,
  type DeviceExportFile,
  EXPORT_FORMAT,
  EXPORT_VERSION,
  type ExportManifest,
  RecoveryError,
  sealedBytes,
} from './archive'

/**
 * Ouverture d'un export chiffré d'un poste avec la clé de récupération de la gérante (O07). Rien n'est écrit : le
 * résultat reste en mémoire le temps de la reprise (`restore.ts`), et ses clés de compartiment sont NON extractables
 * (déchiffrement seulement) ; elles ne sont jamais enveloppées ailleurs ni conservées.
 *
 * Étapes : lecture et version du format ; clé de récupération vérifiée contre le vérificateur de l'export (HKDF, sel =
 * coffre d'origine) ; déballage des clés de compartiment depuis les enveloppes `RECOVERY` ; clé de l'export ouverte sous
 * son compartiment ; contenu déchiffré (AAD « export ») et empreinte SHA-256 du texte clair vérifiée ; manifeste
 * cohérent avec l'en-tête. Toute incohérence : `RecoveryError` (code stable), rien d'autre n'est tenté.
 */
export interface OpenedExport {
  file: Omit<DeviceExportFile, 'content' | 'export_key' | 'recovery'>
  manifest: ExportManifest
  journal: JournalRow[]
  statuses: Map<string, CommandStatusRow>
  documents: DocumentRow[]
  /** Charge d'une commande du journal exporté (clé du compartiment d'origine). */
  openCommand(row: JournalRow): Promise<Record<string, unknown>>
  /** Métadonnées et octets d'une pièce exportée (PDF original). */
  openDocument(row: DocumentRow): Promise<{ meta: unknown; bytes: Uint8Array | null }>
}

interface ExportContent {
  manifest: ExportManifest
  identity: { vaultId: string; clinicId: string; deviceId: string | null }
  keys: Record<string, number>
  rows: Record<string, unknown[]>
}

export async function openExport(
  text: string,
  recoveryInput: string,
  subtle: SubtleCrypto = globalThis.crypto.subtle,
): Promise<OpenedExport> {
  let file: DeviceExportFile
  try {
    file = JSON.parse(text) as DeviceExportFile
  } catch {
    throw new RecoveryError('EXPORT_UNREADABLE')
  }
  if (typeof file !== 'object' || file === null || file.format !== EXPORT_FORMAT)
    throw new RecoveryError('EXPORT_UNREADABLE')
  if (file.version !== EXPORT_VERSION) throw new RecoveryError('EXPORT_FORMAT_UNSUPPORTED')

  const recoveryBytes = await parseRecoveryKey(recoveryInput, subtle)
  if (recoveryBytes === null) throw new RecoveryError('RECOVERY_KEY_INVALID')
  const derived = await deriveRecoveryKek(recoveryBytes, file.vault_id, { subtle })
  recoveryBytes.fill(0)
  if (derived.verifier !== file.recovery.verifier) throw new RecoveryError('RECOVERY_KEY_INVALID')

  const keys = new Map<string, CryptoKey>()
  for (const envelope of file.recovery.envelopes) {
    if (envelope.format !== 'raw') continue
    const aad: AadPart[] = [
      file.clinic_id,
      file.vault_id,
      'RECOVERY',
      envelope.slot,
      envelope.key_id,
      envelope.format,
      envelope.kek_version,
    ]
    try {
      keys.set(
        `${envelope.slot}:${envelope.key_id}`,
        await unwrapAesKey(sealedBytes(envelope.wrapped), derived.kek, aad, false, { subtle }),
      )
    } catch {
      throw new RecoveryError('EXPORT_CORRUPT')
    }
  }
  const keyOf = (compartment: string, keyId: number): CryptoKey => {
    const key = keys.get(`${compartment}:${keyId}`)
    if (key === undefined) throw new RecoveryError('RECOVERY_COMPARTMENT_MISSING')
    return key
  }

  let content: ExportContent
  try {
    const exportKeyRaw = await open(
      keyOf(file.export_key.compartment, file.export_key.key_id),
      sealedBytes(file.export_key.sealed),
      'export',
      [
        file.clinic_id,
        file.vault_id,
        file.export_key.compartment,
        file.export_key.key_id,
        'export-key',
        file.export_id,
        file.created_at,
      ],
      { subtle },
    )
    const contentKey = await subtle.importKey(
      'raw',
      buffer(exportKeyRaw),
      { name: 'AES-GCM', length: 256 },
      false,
      ['decrypt'],
    )
    exportKeyRaw.fill(0)
    const sealed = sealedBytes(file.content.sealed)
    const plaintext = new Uint8Array(
      await subtle.decrypt(
        {
          name: 'AES-GCM',
          iv: buffer(sealed.nonce),
          additionalData: buffer(
            encodeAad('export', [
              file.clinic_id,
              file.vault_id,
              'content',
              file.export_id,
              file.created_at,
            ]),
          ),
          tagLength: 128,
        },
        contentKey,
        buffer(sealed.ciphertext),
      ),
    )
    const digest = toHex(new Uint8Array(await subtle.digest('SHA-256', buffer(plaintext))))
    if (digest !== file.content.sha256) throw new RecoveryError('EXPORT_CORRUPT')
    content = decodeJson<ExportContent>(fromUtf8(plaintext))
  } catch (error) {
    if (error instanceof RecoveryError) throw error
    throw new RecoveryError('EXPORT_CORRUPT')
  }

  const manifest = content.manifest
  if (
    manifest.export_id !== file.export_id ||
    manifest.vault_id !== file.vault_id ||
    manifest.clinic_id !== file.clinic_id ||
    manifest.device_id !== file.device_id ||
    content.identity.vaultId !== file.vault_id
  ) {
    throw new RecoveryError('EXPORT_CORRUPT')
  }

  const journal = ((content.rows['command_journal'] ?? []) as JournalRow[])
    .slice()
    .sort((left, right) => left.deviceSequence - right.deviceSequence)
  const statuses = new Map(
    ((content.rows['command_status'] ?? []) as CommandStatusRow[]).map((row) => [
      row.commandId,
      row,
    ]),
  )
  const documents = (content.rows['encrypted_documents'] ?? []) as DocumentRow[]

  return {
    file: {
      format: file.format,
      version: file.version,
      export_id: file.export_id,
      created_at: file.created_at,
      release: file.release,
      clinic_id: file.clinic_id,
      vault_id: file.vault_id,
      device_id: file.device_id,
      local_schema_version: file.local_schema_version,
    },
    manifest,
    journal,
    statuses,
    documents,
    openCommand: async (row) => {
      try {
        return await openJson<Record<string, unknown>>(
          keyOf(row.compartment, row.payload.keyId),
          row.payload,
          'command',
          [
            file.clinic_id,
            file.vault_id,
            row.compartment,
            row.payload.keyId,
            'command',
            row.commandId,
            row.deviceSequence,
          ],
          { subtle },
        )
      } catch (error) {
        if (error instanceof RecoveryError) throw error
        throw new RecoveryError('EXPORT_CORRUPT')
      }
    },
    openDocument: async (row) => {
      const key = keyOf(row.compartment, row.keyId)
      const aad = (part: string): AadPart[] => [
        file.clinic_id,
        file.vault_id,
        row.compartment,
        row.keyId,
        row.kind,
        row.id,
        row.version,
        part,
      ]
      try {
        const meta = await openJson<unknown>(key, row.meta, 'document', aad('meta'), { subtle })
        const bytes =
          row.content === null
            ? null
            : await open(key, row.content, 'document', aad('content'), { subtle })
        return { meta, bytes }
      } catch (error) {
        if (error instanceof RecoveryError) throw error
        throw new RecoveryError('EXPORT_CORRUPT')
      }
    },
  }
}
