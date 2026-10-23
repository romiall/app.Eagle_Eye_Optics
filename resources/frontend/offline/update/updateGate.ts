/**
 * Mise à jour SÛRE de l'application sur un poste préparé (O06 ; fiche : « Proposer SW update sans remplacement forcé »,
 * « Vérifier pending, profils verrouillés, export et capacité avant changement » ; AT45 ; EE042, EE044). Règles pures :
 * ni réseau, ni stockage, ni service worker.
 *
 * Une nouvelle version n'est JAMAIS installée d'office : l'écran la propose, et cette décision dit si l'installer
 * maintenant est sans risque pour les pièces encore sur le poste :
 *  - BLOQUÉE si une commande en attente a une version de schéma, de règles ou un format de coffre que la release du
 *    serveur ne déclare plus reprendre (`contracts/compatibility.json`), ou si la base locale est plus récente que ce
 *    que la release sait ouvrir : il faut d'abord synchroniser ces pièces (elles resteraient sinon sans issue) ;
 *  - À CONFIRMER (avertissements) : commandes en attente (dont celles d'un profil verrouillé, qui ne partiront qu'à son
 *    ouverture), aucun export chiffré récent alors que des pièces attendent, espace de stockage presque plein,
 *    compatibilité du serveur inconnue (hors ligne) ;
 *  - LIBRE sinon.
 * Aucune décision ne purge rien : la migration du schéma local est additive (registre `offline/migrations`).
 */

export type UpdateDecision = 'FREE' | 'CONFIRM' | 'BLOCKED'

export interface PendingCommandVersion {
  commandName: string
  schemaVersion: string
  rulesVersion: string
  compartment: string
}

/** Sous-ensemble de la déclaration de compatibilité du serveur utile au poste. */
export interface ReleaseCompatibility {
  release: string
  rules_versions: readonly string[]
  commands: Readonly<Record<string, readonly string[] | undefined>>
  vault_formats: readonly number[]
  local_schema_versions: readonly number[]
}

export interface UpdateFacts {
  pending: readonly PendingCommandVersion[]
  /** Compartiments ouverts par la session en cours (vide : coffre verrouillé). */
  openCompartments: readonly string[]
  /** Version du schéma IndexedDB ouverte sur ce poste. */
  localSchemaVersion: number
  /** Format du coffre (D21). */
  vaultFormat: number
  /** Dernier export chiffré du poste (ISO), ou null. */
  lastExportAt: string | null
  /** Octets utilisés et quota du stockage du navigateur, si connus. */
  storage: { usage: number; quota: number } | null
  /** Déclaration de compatibilité de la release du serveur, null si elle n'a pas pu être lue. */
  compatibility: ReleaseCompatibility | null
  now: number
}

export type UpdateReasonCode =
  | 'PENDING_COMMANDS'
  | 'LOCKED_PROFILE_COMMANDS'
  | 'NO_RECENT_EXPORT'
  | 'STORAGE_ALMOST_FULL'
  | 'COMPATIBILITY_UNKNOWN'
  | 'COMMAND_VERSION_DROPPED'
  | 'RULES_VERSION_DROPPED'
  | 'VAULT_FORMAT_DROPPED'
  | 'LOCAL_SCHEMA_UNSUPPORTED'

export interface UpdateReason {
  code: UpdateReasonCode
  blocking: boolean
  message: string
}

export interface UpdateReadiness {
  decision: UpdateDecision
  reasons: UpdateReason[]
  pendingCount: number
}

/** Un export est « récent » s'il date de moins de 24 h (proposition, à confirmer avec le cabinet). */
export const RECENT_EXPORT_MS = 24 * 60 * 60 * 1000

/** Au-delà de 90 % du quota, une migration ou un export peuvent échouer faute de place. */
export const STORAGE_WARNING_RATIO_PERCENT = 90

function plural(count: number, one: string, many: string): string {
  return count === 1 ? one : many
}

export function decideUpdate(facts: UpdateFacts): UpdateReadiness {
  const reasons: UpdateReason[] = []
  const pending = facts.pending
  const count = pending.length
  const compat = facts.compatibility

  if (compat === null) {
    if (count > 0) {
      reasons.push({
        code: 'COMPATIBILITY_UNKNOWN',
        blocking: true,
        message:
          'Le serveur est injoignable : impossible de vérifier que la nouvelle version reprendra les pièces en attente. Installez-la après une synchronisation.',
      })
    }
  } else {
    const droppedCommands = pending.filter(
      (command) => !(compat.commands[command.commandName] ?? []).includes(command.schemaVersion),
    )
    if (droppedCommands.length > 0) {
      const names = [...new Set(droppedCommands.map((command) => command.commandName))]
        .sort()
        .join(', ')
      reasons.push({
        code: 'COMMAND_VERSION_DROPPED',
        blocking: true,
        message: `La nouvelle version ne reprend plus certaines pièces en attente (${names}) : synchronisez-les avant de l’installer.`,
      })
    }
    const droppedRules = pending.filter(
      (command) => !compat.rules_versions.includes(command.rulesVersion),
    )
    if (droppedRules.length > 0) {
      reasons.push({
        code: 'RULES_VERSION_DROPPED',
        blocking: true,
        message:
          'Des pièces en attente suivent des règles que la nouvelle version ne reprend plus : synchronisez-les avant de l’installer.',
      })
    }
    if (!compat.vault_formats.includes(facts.vaultFormat)) {
      reasons.push({
        code: 'VAULT_FORMAT_DROPPED',
        blocking: true,
        message:
          'Le coffre de ce poste a un format que la nouvelle version ne déclare pas : ne l’installez pas, prévenez l’exploitant.',
      })
    }
    if (!compat.local_schema_versions.includes(facts.localSchemaVersion)) {
      reasons.push({
        code: 'LOCAL_SCHEMA_UNSUPPORTED',
        blocking: true,
        message:
          'La base de ce poste est d’une version que la nouvelle version ne sait pas ouvrir : ne l’installez pas, prévenez l’exploitant.',
      })
    }
  }

  if (count > 0) {
    reasons.push({
      code: 'PENDING_COMMANDS',
      blocking: false,
      message: `${count} ${plural(count, 'pièce enregistrée sur ce poste attend', 'pièces enregistrées sur ce poste attendent')} la synchronisation : elles seront conservées, mais synchronisez si possible avant d’installer.`,
    })
    const locked = [
      ...new Set(
        pending
          .map((command) => command.compartment)
          .filter((compartment) => !facts.openCompartments.includes(compartment)),
      ),
    ]
    if (locked.length > 0) {
      reasons.push({
        code: 'LOCKED_PROFILE_COMMANDS',
        blocking: false,
        message:
          'Des pièces en attente appartiennent à un profil verrouillé : elles ne partiront qu’à l’ouverture de ce profil.',
      })
    }
    const recent =
      facts.lastExportAt !== null && facts.now - Date.parse(facts.lastExportAt) < RECENT_EXPORT_MS
    if (!recent) {
      reasons.push({
        code: 'NO_RECENT_EXPORT',
        blocking: false,
        message:
          'Aucun export chiffré récent du poste : exportez-le avant d’installer (sauvegarde des pièces en attente).',
      })
    }
  }

  if (facts.storage !== null && facts.storage.quota > 0) {
    // Comparaison entière (aucune division flottante) : usage × 100 ≥ quota × seuil.
    if (facts.storage.usage * 100 >= facts.storage.quota * STORAGE_WARNING_RATIO_PERCENT) {
      reasons.push({
        code: 'STORAGE_ALMOST_FULL',
        blocking: false,
        message:
          'Le stockage du navigateur est presque plein : libérez de la place (autres sites) avant d’installer.',
      })
    }
  }

  const blocking = reasons.some((reason) => reason.blocking)
  return {
    decision: blocking ? 'BLOCKED' : reasons.length > 0 ? 'CONFIRM' : 'FREE',
    reasons,
    pendingCount: count,
  }
}
