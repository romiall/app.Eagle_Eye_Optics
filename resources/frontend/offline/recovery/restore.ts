import type {
  PushRequest,
  PushResponse,
  SignedCommand as ContractSignedCommand,
} from '../../domain/contracts/generated'
import { assembleCore, type CommandCore } from '../command-journal/core'
import { isCompartment } from '../compartments'
import { isSyncRefused } from '../sync/transport'
import type { DocumentRow, RecoveredCommandRow, RecoveredExportRow } from '../vault/db'
import { VaultError } from '../vault/errors'
import type { Vault } from '../vault/vault'

import { RecoveryError } from './archive'
import type { OpenedExport } from './openArchive'

/**
 * Reprise d'un poste REMPLACÉ sur un poste NEUF (O07 ; « Restaurer pièces émises sur poste neuf et réconcilier origine »,
 * « Mêmes IDs/numéros pour anciens faits », « Aucun clone actif des droits non consommés » ; AT39, AT44).
 *
 *  1. `importExport` : l'export ouvert par la clé de récupération est rangé dans CE coffre, en une transaction : chaque
 *     commande signée de l'ancien poste (cœur complet, empreinte et signature d'origine) est rescellée dans son
 *     compartiment ; les pièces PDF sont rescellées (mêmes identifiants) pour la réimpression. Rien n'entre dans le
 *     journal de CE poste, aucun numéro de CE poste n'est consommé, aucun grant ni clé de l'ancien poste n'est repris.
 *     Rejouer le même export ne double rien (identifiants conservés).
 *  2. `pushRecovered` : les commandes non acquittées de l'ancien poste partent TELLES QUELLES, dans l'ordre, au nom de
 *     l'ancien poste (`POST /devices/{ancien}/recovered-push`, gérante) : le serveur vérifie la signature de la clé
 *     enrôlée de l'ancien poste et applique avec les numéros d'origine ; jamais de nouveau reçu pour reprendre un reçu
 *     ancien. Un ACK final n'est jamais rétrogradé ; un rang manquant au serveur est renvoyé depuis l'export.
 *  3. La réconciliation serveur (`ReconcileReplacement`) clôt ensuite les ressources de l'ancien poste ; CE poste reçoit
 *     ses propres grants et plages par la préparation habituelle (émissions futures).
 */
export interface RecoveryTransport {
  pushRecovered(oldDeviceId: string, body: PushRequest): Promise<PushResponse>
}

export interface ImportReport {
  exportId: string
  oldDeviceId: string
  commands: number
  pending: number
  documents: number
  lastDeviceSequence: string
  lastEventHash: string | null
  alreadyImported: boolean
}

export interface RecoveredPushReport {
  sent: number
  accepted: number
  waiting: number
  conflicts: number
  rejected: number
  /** Toutes les commandes de l'ancien poste sont finalisées (acceptées ou rejetées après revue). */
  complete: boolean
  prefix: { lastSequence: string; lastHash: string | null } | null
}

const FINAL_LOCAL = ['ACCEPTED', 'REJECTED']
const FINAL_RECOVERED: readonly RecoveredCommandRow['state'][] = [
  'ACCEPTED',
  'REJECTED',
  'FINAL_BEFORE_EXPORT',
]
export const RECOVERED_PUSH_MAX = 50

export async function importExport(
  vault: Vault,
  opened: OpenedExport,
  now: () => number,
): Promise<ImportReport> {
  const session = vault.session()
  if (session === null) throw new VaultError('VAULT_LOCKED')
  const oldDeviceId = opened.file.device_id
  if (oldDeviceId === null) throw new RecoveryError('EXPORT_WITHOUT_DEVICE')
  if (opened.file.clinic_id !== (await vault.clinicId()))
    throw new RecoveryError('EXPORT_OTHER_CLINIC')
  const thisDevice = await vault.deviceId()
  if (thisDevice === oldDeviceId) throw new RecoveryError('EXPORT_SAME_DEVICE')

  const report = (alreadyImported: boolean): ImportReport => ({
    exportId: opened.file.export_id,
    oldDeviceId,
    commands: opened.journal.length,
    pending: opened.manifest.journal.pending,
    documents: opened.documents.length,
    lastDeviceSequence: opened.manifest.journal.last_device_sequence,
    lastEventHash: opened.manifest.journal.last_event_hash,
    alreadyImported,
  })
  const tables = vault.recoveryTables()
  if ((await tables.recovered_exports.get(opened.file.export_id)) !== undefined) return report(true)

  // Phase A : tout est scellé AVANT la transaction (aucun appel Web Crypto dans Dexie).
  const at = new Date(now()).toISOString()
  const commands: RecoveredCommandRow[] = []
  for (const row of opened.journal) {
    if (!isCompartment(row.compartment)) throw new RecoveryError('EXPORT_CORRUPT')
    const payload = await opened.openCommand(row)
    const signed: { core: CommandCore; event_hash: string; signature: string } = {
      core: assembleCore(row.core, payload),
      event_hash: row.eventHash,
      signature: row.signature,
    }
    if (signed.core.device_id !== oldDeviceId || signed.core.command_id !== row.commandId)
      throw new RecoveryError('EXPORT_CORRUPT')
    const { keyId, sealed } = await vault.sealRecoveredCommand(
      row.compartment,
      row.commandId,
      signed,
    )
    const localState = opened.statuses.get(row.commandId)?.state ?? 'LOCAL'
    commands.push({
      commandId: row.commandId,
      oldDeviceId,
      exportId: opened.file.export_id,
      deviceSequence: row.deviceSequence,
      compartment: row.compartment,
      keyId,
      sealed,
      eventHash: row.eventHash,
      state: FINAL_LOCAL.includes(localState) ? 'FINAL_BEFORE_EXPORT' : 'PENDING',
      updatedAt: at,
    })
  }
  const documents: DocumentRow[] = []
  for (const row of opened.documents) {
    if (!isCompartment(row.compartment)) continue
    const { meta, bytes } = await opened.openDocument(row)
    documents.push(
      await vault.sealDocumentRow({
        id: row.id,
        kind: row.kind,
        compartment: row.compartment,
        entityId: row.entityId,
        version: row.version,
        meta,
        bytes,
      }),
    )
  }

  const exportRow: RecoveredExportRow = {
    exportId: opened.file.export_id,
    oldDeviceId,
    oldVaultId: opened.file.vault_id,
    createdAt: opened.file.created_at,
    importedAt: at,
    manifest: opened.manifest as unknown as Record<string, unknown>,
    state: 'IMPORTED',
    updatedAt: at,
  }

  // Phase C : une transaction ; une commande déjà reprise (autre export du même poste) garde son état.
  await vault.commitRecovery(async (db) => {
    if ((await db.recovered_exports.get(exportRow.exportId)) !== undefined) return
    await db.recovered_exports.add(exportRow)
    for (const command of commands) {
      if ((await db.recovered_commands.get(command.commandId)) === undefined)
        await db.recovered_commands.add(command)
    }
    for (const document of documents) {
      if ((await db.encrypted_documents.get(document.id)) === undefined)
        await db.encrypted_documents.add(document)
    }
  })
  await vault.noteRecovery('device_export_imported', {
    export: exportRow.exportId,
    old_device: oldDeviceId,
    commands: commands.length,
    pending: commands.filter((command) => command.state === 'PENDING').length,
    documents: documents.length,
  })
  return report(false)
}

/** Commandes de l'ancien poste encore à transmettre, dans l'ordre du journal d'origine. */
export async function recoveredPending(
  vault: Vault,
  oldDeviceId: string,
): Promise<RecoveredCommandRow[]> {
  const rows = await vault
    .recoveryTables()
    .recovered_commands.where('[oldDeviceId+deviceSequence]')
    .between([oldDeviceId, 0], [oldDeviceId, Number.MAX_SAFE_INTEGER])
    .toArray()
  return rows
    .filter((row) => !FINAL_RECOVERED.includes(row.state))
    .sort((left, right) => left.deviceSequence - right.deviceSequence)
}

async function rowsFrom(
  vault: Vault,
  oldDeviceId: string,
  sequence: number,
  limit: number,
): Promise<RecoveredCommandRow[]> {
  return (
    await vault
      .recoveryTables()
      .recovered_commands.where('[oldDeviceId+deviceSequence]')
      .between([oldDeviceId, sequence], [oldDeviceId, Number.MAX_SAFE_INTEGER])
      .toArray()
  )
    .sort((left, right) => left.deviceSequence - right.deviceSequence)
    .slice(0, limit)
}

async function updateStates(
  vault: Vault,
  changes: ReadonlyMap<string, RecoveredCommandRow['state']>,
  at: string,
): Promise<void> {
  await vault.commitRecovery(async (db) => {
    for (const [commandId, state] of changes) {
      const current = await db.recovered_commands.get(commandId)
      if (current === undefined || FINAL_RECOVERED.includes(current.state)) continue
      await db.recovered_commands.put({ ...current, state, updatedAt: at })
    }
  })
}

/**
 * Un lot de commandes de l'ancien poste transmis par la reprise. À rappeler tant que `complete` est faux et qu'aucune
 * commande n'attend une décision (conflit) ou une pièce liée : le lot suivant part de la plus ancienne non finalisée.
 */
export async function pushRecovered(
  vault: Vault,
  transport: RecoveryTransport,
  oldDeviceId: string,
  now: () => number,
): Promise<RecoveredPushReport> {
  const empty: RecoveredPushReport = {
    sent: 0,
    accepted: 0,
    waiting: 0,
    conflicts: 0,
    rejected: 0,
    complete: false,
    prefix: null,
  }
  let candidates = (await recoveredPending(vault, oldDeviceId)).slice(0, RECOVERED_PUSH_MAX)
  if (candidates.length === 0) return { ...empty, complete: true }

  for (let round = 0; round < 2; round += 1) {
    const signed: ContractSignedCommand[] = []
    for (const row of candidates)
      signed.push(
        (await vault.openRecoveredCommand<ContractSignedCommand>(row)) as ContractSignedCommand,
      )
    const [first, ...rest] = signed
    if (first === undefined) return { ...empty, complete: true }
    const at = new Date(now()).toISOString()
    await updateStates(
      vault,
      new Map(
        candidates
          .filter((row) => row.state === 'PENDING')
          .map((row) => [row.commandId, 'SENT' as const]),
      ),
      at,
    )

    let response: PushResponse
    try {
      response = await transport.pushRecovered(oldDeviceId, {
        device_id: oldDeviceId,
        commands: [first, ...rest],
      })
    } catch (error) {
      if (round === 0 && isSyncRefused(error, 'SYNC_SEQUENCE_GAP')) {
        const expected = Number(error.details['expected_sequence'])
        if (Number.isSafeInteger(expected) && expected >= 1) {
          // Le serveur n'a jamais reçu ces rangs (déjà acquittés sur l'ancien poste ?) : l'export les contient.
          candidates = await rowsFrom(vault, oldDeviceId, expected, RECOVERED_PUSH_MAX)
          continue
        }
      }
      throw error
    }

    const report: RecoveredPushReport = { ...empty, sent: signed.length }
    const changes = new Map<string, RecoveredCommandRow['state']>()
    for (const ack of response.results) {
      const state: RecoveredCommandRow['state'] =
        ack.status === 'ALREADY_ACCEPTED' ? 'ACCEPTED' : ack.status
      changes.set(ack.command_id, state)
      if (state === 'ACCEPTED') report.accepted += 1
      else if (state === 'WAITING_DEPENDENCY') report.waiting += 1
      else if (state === 'CONFLICT_REQUIRES_REVIEW') report.conflicts += 1
      else report.rejected += 1
    }
    await updateStates(vault, changes, at)
    report.prefix = {
      lastSequence: response.finalized_prefix.last_sequence,
      lastHash: response.finalized_prefix.last_hash,
    }
    report.complete = (await recoveredPending(vault, oldDeviceId)).length === 0
    return report
  }
  return empty
}

/** État d'une reprise après la réconciliation du serveur (ressources de l'ancien poste clôturées). */
export async function markReconciled(
  vault: Vault,
  exportId: string,
  now: () => number,
): Promise<void> {
  const at = new Date(now()).toISOString()
  await vault.commitRecovery(async (db) => {
    const row = await db.recovered_exports.get(exportId)
    if (row === undefined) return
    await db.recovered_exports.put({ ...row, state: 'RECONCILED', updatedAt: at })
  })
  await vault.noteRecovery('device_replacement_reconciled', { export: exportId })
}

/** Exports repris sur ce poste, du plus récent au plus ancien, avec le compte de commandes encore à transmettre. */
export async function recoveryStatus(vault: Vault): Promise<
  {
    exportId: string
    oldDeviceId: string
    createdAt: string
    state: RecoveredExportRow['state']
    pending: number
    lastDeviceSequence: string
    lastEventHash: string | null
  }[]
> {
  const exports = await vault.recoveryTables().recovered_exports.toArray()
  const out = []
  for (const row of exports.sort((left, right) => (left.createdAt < right.createdAt ? 1 : -1))) {
    const journal = (row.manifest['journal'] ?? {}) as {
      last_device_sequence?: unknown
      last_event_hash?: unknown
    }
    out.push({
      exportId: row.exportId,
      oldDeviceId: row.oldDeviceId,
      createdAt: row.createdAt,
      state: row.state,
      pending: (await recoveredPending(vault, row.oldDeviceId)).length,
      lastDeviceSequence:
        typeof journal.last_device_sequence === 'string' ? journal.last_device_sequence : '0',
      lastEventHash: typeof journal.last_event_hash === 'string' ? journal.last_event_hash : null,
    })
  }
  return out
}
