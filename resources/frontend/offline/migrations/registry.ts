import type Dexie from 'dexie'

/**
 * Registre ORDONNÉ des versions du schéma IndexedDB du poste (base `eeo-device` ; vault.md D21 ; O06). C'est la SEULE
 * source des déclarations Dexie : `VaultDatabase` les applique dans l'ordre, et les tests de migration ouvrent une base
 * à une version antérieure avec le même registre.
 *
 * Règles (O06, fichier 09 « mise à jour destructive interdite sur postes de production ») :
 *  - AJOUTS seulement : une version ajoute des tables ou des index ; elle ne supprime jamais une table, ne change jamais
 *    la clé primaire d'une table existante et ne réécrit aucune ligne (aucune fonction `upgrade` destructive) ;
 *  - une migration Dexie est UNE transaction `versionchange` : une coupure pendant la migration l'annule entièrement,
 *    la base reste à sa version précédente avec toutes ses lignes ;
 *  - une version publiée ne se modifie plus : la suivante s'ajoute à la fin, et `contracts/compatibility.json`
 *    (`local_schema_versions`) déclare toutes les versions que la release sait ouvrir.
 */
export interface LocalSchemaVersion {
  version: number
  task: string
  summary: string
  /** Tables ajoutées (ou index ajoutés à une table déjà déclarée, même clé primaire) : syntaxe Dexie. */
  stores: Readonly<Record<string, string>>
}

export const LOCAL_SCHEMA: readonly LocalSchemaVersion[] = [
  {
    version: 1,
    task: 'O01',
    summary:
      'Coffre : métadonnées, profils, enveloppes de clés, entités, pièces et brouillons chiffrés, journal de sécurité.',
    stores: {
      vault_meta: 'key',
      profiles: 'id, userId',
      key_envelopes: 'id, profileId, [profileId+slot]',
      encrypted_entities: 'id, [compartment+type]',
      encrypted_documents: 'id, [compartment+kind], entityId',
      encrypted_drafts: 'id, [profileId+compartment]',
      vault_events: '++seq',
    },
  },
  {
    version: 2,
    task: 'O02',
    summary: 'Grants signés du poste.',
    stores: { grants: 'grantId, userId, deviceId' },
  },
  {
    version: 3,
    task: 'O03, O04',
    summary: 'Ressources préparées, journal commun du poste, état des commandes, têtes CAS.',
    stores: {
      allocations: 'id, kind, [kind+documentType+documentYear]',
      command_journal: 'commandId, &deviceSequence, &idempotencyKey, [entityType+entityId]',
      command_status: 'commandId, state',
      aggregate_heads: 'key',
    },
  },
  {
    version: 4,
    task: 'O05',
    summary: 'Curseurs de pull par profil.',
    stores: { pull_cursors: 'userId' },
  },
  {
    version: 5,
    task: 'O07',
    summary:
      'Reprise d’un poste remplacé : exports ouverts et commandes signées de l’ancien poste à transmettre, scellées dans ce coffre.',
    stores: {
      recovered_exports: 'exportId, oldDeviceId',
      recovered_commands: 'commandId, [oldDeviceId+deviceSequence], state',
    },
  },
]

/** Version courante du schéma local écrite par ce client. */
export const LOCAL_SCHEMA_VERSION: number = LOCAL_SCHEMA[LOCAL_SCHEMA.length - 1]?.version ?? 1

function primaryKey(spec: string): string {
  return (spec.split(',')[0] ?? '').trim()
}

/**
 * Vérifie le registre : versions strictement croissantes à partir de 1, sans trou ; aucune table supprimée (`null`) ;
 * aucune clé primaire changée pour une table déjà déclarée. Lève une erreur sinon (test et démarrage).
 */
export function assertAdditive(schema: readonly LocalSchemaVersion[] = LOCAL_SCHEMA): void {
  const keys = new Map<string, string>()
  schema.forEach((entry, index) => {
    if (entry.version !== index + 1)
      throw new Error(`Schéma local : version ${entry.version} hors d’ordre.`)
    for (const [table, spec] of Object.entries(entry.stores)) {
      if (typeof spec !== 'string' || spec.trim() === '') {
        throw new Error(
          `Schéma local v${entry.version} : la table ${table} serait supprimée (interdit).`,
        )
      }
      const key = primaryKey(spec)
      const previous = keys.get(table)
      if (previous !== undefined && previous !== key) {
        throw new Error(
          `Schéma local v${entry.version} : clé primaire de ${table} changée (interdit).`,
        )
      }
      keys.set(table, key)
    }
  })
}

/** Déclare les versions du registre sur une base Dexie, jusqu'à `upTo` (tests de migration). */
export function declareLocalSchema(db: Dexie, upTo: number = LOCAL_SCHEMA_VERSION): void {
  for (const entry of LOCAL_SCHEMA) {
    if (entry.version > upTo) break
    db.version(entry.version).stores({ ...entry.stores })
  }
}

/** Tables présentes à une version donnée du schéma. */
export function tablesAt(version: number): string[] {
  const tables = new Set<string>()
  for (const entry of LOCAL_SCHEMA) {
    if (entry.version > version) break
    for (const table of Object.keys(entry.stores)) tables.add(table)
  }
  return [...tables].sort()
}
