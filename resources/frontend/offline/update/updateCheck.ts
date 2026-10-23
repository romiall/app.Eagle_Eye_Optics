import { VAULT_FORMAT_VERSION } from '../vault/db'
import type { Vault } from '../vault/vault'

import { decideUpdate, type ReleaseCompatibility, type UpdateReadiness } from './updateGate'

/**
 * Faits du poste pour décider d'une mise à jour (O06) : commandes du journal en attente avec leurs versions, compartiments
 * ouverts, version du schéma local, format du coffre, dernier export chiffré, occupation du stockage. Lecture seule :
 * rien n'est modifié ni purgé. Sans coffre sur ce poste (module hors-ligne jamais ouvert), rien n'attend : mise à jour
 * libre côté poste.
 */
export async function updateReadiness(
  vault: Vault,
  compatibility: ReleaseCompatibility | null,
  storage: { usage: number | null; quota: number | null } | null,
  now: number,
): Promise<UpdateReadiness> {
  const status = await vault.status()
  if (status === 'absent') {
    return decideUpdate({
      pending: [],
      openCompartments: [],
      localSchemaVersion: await vault.schemaVersion(),
      vaultFormat: VAULT_FORMAT_VERSION,
      lastExportAt: null,
      storage: null,
      compatibility,
      now,
    })
  }
  const lastExport = await vault.lastExport()
  return decideUpdate({
    pending: await vault.pendingVersions(),
    openCompartments: vault.session()?.compartments ?? [],
    localSchemaVersion: await vault.schemaVersion(),
    vaultFormat: VAULT_FORMAT_VERSION,
    lastExportAt: lastExport?.at ?? null,
    storage:
      storage !== null && storage.usage !== null && storage.quota !== null
        ? { usage: storage.usage, quota: storage.quota }
        : null,
    compatibility,
    now,
  })
}
