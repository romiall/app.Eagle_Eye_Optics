import type { Compartment } from '../compartments'
import { syncStateOf } from '../command-journal/journal'
import { FACT_TYPES, type LocalFact, type LocalHead } from '../LocalCommandBus'
import type { Vault } from '../vault/vault'

import type { LocalSyncState } from './syncStates'

/**
 * Enregistrements du poste pour les lectures fusionnées F10 (`mergeFacts`, `mergeVersioned`) : les faits immuables et
 * les projections d'entités écrits par le journal local, chacun avec l'état de synchronisation de SA commande (local,
 * en attente, en dépendance, en conflit, consolidé, rejeté). Les identifiants sont ceux que le serveur gardera : la
 * fusion se fait par identifiant, jamais par addition d'un total local et d'un total serveur. Seuls les compartiments
 * ouverts par la session se lisent (un profil ne voit pas les faits d'un autre rôle).
 */
export interface LocalFactRecord {
  fact: LocalFact
  state: LocalSyncState
}

export interface LocalHeadRecord {
  head: LocalHead
  state: LocalSyncState
}

async function statesByCommand(vault: Vault): Promise<Map<string, LocalSyncState>> {
  const rows = await vault.localTables().command_status.toArray()
  return new Map(rows.map((row) => [row.commandId, syncStateOf(row.state)]))
}

/** Faits locaux d'un compartiment, dans l'ordre du journal. */
export async function localFacts(
  vault: Vault,
  compartment: Compartment,
): Promise<LocalFactRecord[]> {
  const states = await statesByCommand(vault)
  const facts = await vault.listEntities<LocalFact>(FACT_TYPES[compartment])
  return facts
    .map((entry) => ({ fact: entry.value, state: states.get(entry.value.commandId) ?? 'local' }))
    .sort((left, right) => left.fact.recordedAt.localeCompare(right.fact.recordedAt))
}

/** Projections locales d'un type d'entité ; l'état est celui de la DERNIÈRE commande qui l'a modifiée. */
export async function localHeads(vault: Vault, headType: string): Promise<LocalHeadRecord[]> {
  const states = await statesByCommand(vault)
  const heads = await vault.listEntities<LocalHead>(headType)
  return heads.map((entry) => {
    const last = entry.value.commandIds[entry.value.commandIds.length - 1]
    return {
      head: entry.value,
      state: (last === undefined ? undefined : states.get(last)) ?? 'local',
    }
  })
}
