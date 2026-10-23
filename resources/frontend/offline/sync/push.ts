import type {
  Ack,
  PushResponse,
  SignedCommand as ContractSignedCommand,
} from '../../domain/contracts/generated'

import { CommandJournal, type PendingCommand, type SignedCommand } from '../command-journal/journal'
import { VaultError } from '../vault/errors'
import type { Vault } from '../vault/vault'

import { isSyncRefused, type SyncTransport } from './transport'

/**
 * Push du journal COMMUN du poste (O05 ; contrat 23 « Push, ACK et résolution ») :
 *
 *  1. les commandes non finalisées, dans l'ordre du journal, à partir de la plus ancienne ; le lot s'arrête à la
 *     première commande que le profil ouvert ne sait pas déchiffrer (compartiment d'un autre profil : elle part à
 *     l'ouverture de son profil, et aucune ne la double) ; au plus 50 commandes et 2 Mio ;
 *  2. les commandes sont marquées « envoyées » AVANT l'envoi : une coupure ou une fermeture pendant l'échange les laisse
 *     en attente d'ACK, jamais perdues ;
 *  3. chaque ACK est enregistré localement (`recordAck`) : un ACK final (ACCEPTED, REJECTED) n'est jamais rétrogradé et
 *     aucune commande n'est supprimée ; WAITING_DEPENDENCY et CONFLICT_REQUIRES_REVIEW la gardent dans la file ;
 *  4. le serveur qui n'a pas reçu un rang antérieur (`SYNC_SEQUENCE_GAP`, base restaurée par exemple) reçoit une fois le
 *     journal depuis le rang qu'il demande, commandes déjà confirmées comprises (il répond depuis son inbox).
 *
 * Un délai dépassé rejoue les MÊMES commandes (mêmes identifiants, mêmes signatures) : rien n'est appliqué deux fois.
 */
export const PUSH_MAX_COMMANDS = 50
export const PUSH_MAX_BYTES = 2 * 1024 * 1024

export interface PushReport {
  sent: number
  acknowledged: number
  waiting: number
  conflicts: number
  rejected: number
  /** Une commande d'un autre profil bloque la suite du journal : elle part à l'ouverture de son profil. */
  blockedByOtherProfile: boolean
  prefix: { lastSequence: number; lastHash: string | null } | null
  serverTime: string | null
}

export interface PushOptions {
  now: () => number
  maxCommands?: number
  maxBytes?: number
}

const EMPTY: PushReport = {
  sent: 0,
  acknowledged: 0,
  waiting: 0,
  conflicts: 0,
  rejected: 0,
  blockedByOtherProfile: false,
  prefix: null,
  serverTime: null,
}

interface Batch {
  commands: SignedCommand[]
  ids: string[]
  blocked: boolean
}

async function buildBatch(
  journal: CommandJournal,
  candidates: readonly PendingCommand[],
  compartments: readonly string[],
  maxCommands: number,
  maxBytes: number,
): Promise<Batch> {
  const commands: SignedCommand[] = []
  const ids: string[] = []
  let bytes = 64
  for (const candidate of candidates) {
    if (commands.length >= maxCommands) break
    if (!compartments.includes(candidate.row.compartment)) return { commands, ids, blocked: true }
    const signed = await journal.signed(candidate.row)
    const size = new TextEncoder().encode(JSON.stringify(signed)).length + 1
    if (commands.length > 0 && bytes + size > maxBytes) break
    bytes += size
    commands.push(signed)
    ids.push(candidate.row.commandId)
  }
  return { commands, ids, blocked: false }
}

function referencesOf(ack: Ack): Record<string, unknown> {
  return {
    result_references: ack.result_references,
    versions: ack.versions,
    dependencies: ack.dependencies,
    conflict_id: ack.conflict_id,
    error_code: ack.error_code,
    reason: ack.reason === null ? null : ack.reason.slice(0, 300),
  }
}

async function recordAcks(
  journal: CommandJournal,
  response: PushResponse,
  at: string,
  sent: number,
): Promise<PushReport> {
  const report: PushReport = { ...EMPTY, sent }
  for (const ack of response.results) {
    await journal.recordAck(ack.command_id, ack.status, referencesOf(ack), at)
    if (ack.status === 'ACCEPTED' || ack.status === 'ALREADY_ACCEPTED') report.acknowledged += 1
    else if (ack.status === 'WAITING_DEPENDENCY') report.waiting += 1
    else if (ack.status === 'CONFLICT_REQUIRES_REVIEW') report.conflicts += 1
    else report.rejected += 1
  }
  report.prefix = {
    lastSequence: Number(response.finalized_prefix.last_sequence),
    lastHash: response.finalized_prefix.last_hash,
  }
  report.serverTime = response.server_time
  return report
}

/** Un lot du journal : envoi, ACK enregistrés, heure du serveur notée. */
export async function pushJournal(
  vault: Vault,
  transport: SyncTransport,
  options: PushOptions,
): Promise<PushReport> {
  const session = vault.session()
  if (session === null || session.authority !== 'granted') throw new VaultError('GRANT_REQUIRED')
  const deviceId = await vault.deviceId()
  if (deviceId === null) throw new VaultError('DEVICE_NOT_BOUND')
  const maxCommands = Math.max(
    1,
    Math.min(options.maxCommands ?? PUSH_MAX_COMMANDS, PUSH_MAX_COMMANDS),
  )
  const maxBytes = options.maxBytes ?? PUSH_MAX_BYTES
  const journal = new CommandJournal(vault)

  let candidates = await journal.pending(maxCommands)
  for (let round = 0; round < 2; round += 1) {
    const batch = await buildBatch(journal, candidates, session.compartments, maxCommands, maxBytes)
    const [first, ...rest] = batch.commands
    if (first === undefined) return { ...EMPTY, blockedByOtherProfile: batch.blocked }

    const at = new Date(options.now()).toISOString()
    await journal.markSent(batch.ids, at)
    let response: PushResponse
    try {
      // Cœur du journal = cœur du contrat (`sync/command-core.json`) : seuls les types générés sont plus étroits.
      const commands = [first, ...rest] as unknown as [
        ContractSignedCommand,
        ...ContractSignedCommand[],
      ]
      response = await transport.push({ device_id: deviceId, commands })
    } catch (error) {
      if (round === 0 && isSyncRefused(error, 'SYNC_SEQUENCE_GAP')) {
        const expected = Number(error.details['expected_sequence'])
        if (Number.isSafeInteger(expected) && expected >= 1) {
          candidates = await journal.fromSequence(expected, maxCommands)
          continue
        }
      }
      throw error
    }
    await vault.noteServerTime(response.server_time)
    const report = await recordAcks(journal, response, at, batch.commands.length)
    return { ...report, blockedByOtherProfile: batch.blocked }
  }
  return { ...EMPTY }
}
