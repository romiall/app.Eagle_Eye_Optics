import type { LocalSyncState } from '../projections/syncStates'
import type { CommandState, CommandStatusRow, JournalRow } from '../vault/db'
import type { Vault } from '../vault/vault'

import { assembleCore, type CommandCore } from './core'

/**
 * Journal COMMUN du poste vu par la synchronisation (interface préparée pour O05 ; contrat 23 « Push, ACK et
 * résolution »). Le journal est append-only : rien ici ne supprime une commande. Une commande quitte la file active
 * seulement par un ACK FINAL (ACCEPTED ou REJECTED après revue), enregistré localement ; WAITING_DEPENDENCY et
 * CONFLICT_REQUIRES_REVIEW la gardent, dans l'ordre de `device_sequence`, sans sauter de séquence.
 */

/** Commande prête à transmettre : cœur reconstitué (charge déchiffrée), empreinte et signature d'origine. */
export interface SignedCommand {
  core: CommandCore
  event_hash: string
  signature: string
}

export interface PendingCommand {
  row: JournalRow
  state: CommandState
}

const FINAL_STATES: readonly CommandState[] = ['ACCEPTED', 'REJECTED']

/** État d'affichage (F10) d'une commande du journal. */
export function syncStateOf(state: CommandState): LocalSyncState {
  switch (state) {
    case 'LOCAL':
      return 'local'
    case 'SENT':
      return 'pending'
    case 'WAITING_DEPENDENCY':
      return 'waiting_dependency'
    case 'CONFLICT_REQUIRES_REVIEW':
      return 'conflict'
    case 'ACCEPTED':
      return 'acknowledged'
    case 'REJECTED':
      return 'rejected'
  }
}

export class CommandJournal {
  constructor(private readonly vault: Vault) {}

  /** Tête du journal : dernière séquence et empreinte committées sur ce poste. */
  async head(): Promise<{ deviceSequence: number; eventHash: string | null }> {
    const row = await this.vault.localTables().aggregate_heads.get('journal')
    return row === undefined
      ? { deviceSequence: 0, eventHash: null }
      : { deviceSequence: row.deviceSequence, eventHash: row.eventHash }
  }

  /**
   * Commandes non finalisées, dans l'ordre du journal, à partir de la plus ancienne. `limit` borne le lot (push de 50
   * commandes au plus, contrat 23). La première commande non finalisée arrête la file : aucune ne la double.
   */
  async pending(limit = 50): Promise<PendingCommand[]> {
    const tables = this.vault.localTables()
    const rows = await tables.command_journal.orderBy('deviceSequence').toArray()
    const statuses = new Map(
      (await tables.command_status.toArray()).map((status) => [status.commandId, status]),
    )
    const out: PendingCommand[] = []
    for (const row of rows) {
      const state = statuses.get(row.commandId)?.state ?? 'LOCAL'
      if (FINAL_STATES.includes(state)) continue
      out.push({ row, state })
      if (out.length >= limit) break
    }
    return out
  }

  /**
   * Commandes du journal à partir d'un rang, finalisées ou non (reprise O05 : le serveur demande le journal depuis le rang
   * qu'il n'a pas reçu, par exemple après une restauration de sa base). Jamais une suppression.
   */
  async fromSequence(start: number, limit = 50): Promise<PendingCommand[]> {
    const tables = this.vault.localTables()
    const rows = await tables.command_journal
      .where('deviceSequence')
      .aboveOrEqual(start)
      .limit(limit)
      .toArray()
    const statuses = new Map(
      (await tables.command_status.toArray()).map((status) => [status.commandId, status]),
    )
    return rows
      .sort((left, right) => left.deviceSequence - right.deviceSequence)
      .map((row) => ({ row, state: statuses.get(row.commandId)?.state ?? 'LOCAL' }))
  }

  /** État et références de chaque commande du journal (tableau de synchronisation, conflits). */
  async statuses(): Promise<Map<string, CommandStatusRow>> {
    return new Map(
      (await this.vault.localTables().command_status.toArray()).map((status) => [
        status.commandId,
        status,
      ]),
    )
  }

  /** Commande signée reconstituée pour le push : exige la clé du compartiment de la commande (profil ouvert). */
  async signed(row: JournalRow): Promise<SignedCommand> {
    const payload = await this.vault.openCommandPayload<Record<string, unknown>>(row)
    return {
      core: assembleCore(row.core, payload),
      event_hash: row.eventHash,
      signature: row.signature,
    }
  }

  /** Compartiments dont des commandes non finalisées attendent un profil qui sait les déchiffrer (tableau de bord). */
  async waitingCompartments(): Promise<string[]> {
    const pending = await this.pending(Number.MAX_SAFE_INTEGER)
    return [...new Set(pending.map((item) => item.row.compartment))].sort()
  }

  /**
   * ACK reçu du serveur (O05) : seul chemin qui change l'état d'une commande. `ALREADY_ACCEPTED` du transport est
   * enregistré ACCEPTED. Un ACK n'est jamais rétrogradé (un ACCEPTED ou REJECTED reste final).
   */
  async recordAck(
    commandId: string,
    status:
      | 'ACCEPTED'
      | 'ALREADY_ACCEPTED'
      | 'WAITING_DEPENDENCY'
      | 'CONFLICT_REQUIRES_REVIEW'
      | 'REJECTED',
    references: Record<string, unknown> | null,
    at: string,
  ): Promise<void> {
    const state: CommandState = status === 'ALREADY_ACCEPTED' ? 'ACCEPTED' : status
    await this.vault.commitLocal(async (db) => {
      const journal = await db.command_journal.get(commandId)
      if (journal === undefined) throw new Error('ACK d’une commande absente du journal.')
      const current = await db.command_status.get(commandId)
      if (current !== undefined && FINAL_STATES.includes(current.state)) return
      const next: CommandStatusRow = {
        commandId,
        state,
        attempts: (current?.attempts ?? 0) + 1,
        updatedAt: at,
        references,
      }
      await db.command_status.put(next)
    })
  }

  /** Marque un lot comme envoyé (en attente d'ACK) sans rien retirer de la file. */
  async markSent(commandIds: readonly string[], at: string): Promise<void> {
    await this.vault.commitLocal(async (db) => {
      for (const commandId of commandIds) {
        const current = await db.command_status.get(commandId)
        if (current === undefined || current.state !== 'LOCAL') continue
        await db.command_status.put({
          ...current,
          state: 'SENT',
          attempts: current.attempts + 1,
          updatedAt: at,
        })
      }
    })
  }
}
