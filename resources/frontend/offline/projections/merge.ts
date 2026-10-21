import {
  isFinalState,
  type DocumentState,
  type LocalSyncState,
  type RecordOrigin,
} from './syncStates'

/**
 * Lecture consolidée commune (contrat 23 : « la projection tient compte des IDs ACK pour ne pas
 * compter deux fois le même fait »). Les entités locales et consolidées portent les mêmes
 * identifiants (ULID connus avant tout commit local) : la fusion se fait par identifiant, jamais en
 * additionnant un total serveur et un total local. Deux règles, selon la nature de la donnée :
 *
 * - **Faits en ajout seul** (`mergeFacts` : paiements, contre-écritures, avoirs, remboursements,
 *   mouvements) : un fait ne change jamais ; s'il figure dans l'instantané du serveur, il est
 *   consolidé et la copie locale n'est plus comptée.
 * - **Entités modifiables** (`mergeVersioned` : identité patient, consultation, rendez-vous) : une
 *   modification du poste non finalisée (enregistrée, envoyée, en attente, en conflit) reste
 *   affichée avec son état, et la version du serveur est gardée pour comparaison (AT42 : aucune
 *   proposition écrasée, décision explicite). Une modification acceptée l'emporte sur un instantané
 *   plus ancien qu'elle ; rejetée, elle cède à la version du serveur et reste lisible.
 *
 * L'ordre est celui de l'instantané, puis des enregistrements du poste dans leur ordre d'origine.
 */
export interface Identified {
  id: string
}

export interface Versioned extends Identified {
  /** Version de l'entité (colonne `version`, `entity_version` du pull). */
  version: number
}

export interface LocalRecord<T extends Identified> {
  value: T
  state: LocalSyncState
  /** Pièce PDF locale (reçu, facture), si l'enregistrement en a une. */
  document?: DocumentState
}

/** Instantané du serveur : ce qu'il a consolidé jusqu'à `asOf`. */
export interface ServerSnapshot<T extends Identified> {
  items: readonly T[]
  /** Instant du serveur (`server_time`) auquel l'instantané a été lu. */
  asOf: string
  /** Faux si la liste est tronquée ou partielle (pagination, compartiment, totaux distants incomplets). */
  complete: boolean
}

export interface MergedRecord<T extends Identified> {
  id: string
  /** Valeur à afficher. */
  value: T
  origin: RecordOrigin
  /** `consolidated` seulement pour une valeur du serveur que rien ne contredit sur le poste. */
  state: LocalSyncState | 'consolidated'
  /** État local connu pour cet identifiant, même quand la valeur affichée vient du serveur. */
  localState: LocalSyncState | null
  /** Version du serveur, gardée pour comparaison quand le poste en propose une autre. */
  serverValue: T | null
  /** Proposition du poste, gardée quand la valeur affichée vient du serveur (rejet, ancienne). */
  localValue: T | null
  document: DocumentState
}

/** Fraîcheur des données affichées (fichier 04 : fraîcheur distante, AT45 : totaux incomplets). */
export interface Freshness {
  /** Instant de l'instantané serveur utilisé, ou null : données de ce poste seulement. */
  serverAsOf: string | null
  /** Faux si une partie distante manque : les totaux affichés sont incomplets. */
  complete: boolean
}

export interface MergedView<T extends Identified> {
  records: MergedRecord<T>[]
  freshness: Freshness
}

function indexLocal<T extends Identified>(
  local: readonly LocalRecord<T>[],
): Map<string, LocalRecord<T>> {
  const byId = new Map<string, LocalRecord<T>>()
  for (const record of local) {
    if (byId.has(record.value.id)) {
      throw new Error(`Enregistrement local en double : ${record.value.id}.`)
    }
    byId.set(record.value.id, record)
  }
  return byId
}

function merge<T extends Identified>(
  snapshot: ServerSnapshot<T> | null,
  local: readonly LocalRecord<T>[],
  resolve: (server: T, mine: LocalRecord<T>) => MergedRecord<T>,
): MergedView<T> {
  const localById = indexLocal(local)
  const records: MergedRecord<T>[] = []
  const seen = new Set<string>()
  for (const item of snapshot?.items ?? []) {
    if (seen.has(item.id)) throw new Error(`Instantané serveur en double : ${item.id}.`)
    seen.add(item.id)
    const mine = localById.get(item.id)
    records.push(
      mine === undefined
        ? {
            id: item.id,
            value: item,
            origin: 'server',
            state: 'consolidated',
            localState: null,
            serverValue: item,
            localValue: null,
            document: 'none',
          }
        : resolve(item, mine),
    )
  }
  for (const record of local) {
    if (seen.has(record.value.id)) continue
    records.push({
      id: record.value.id,
      value: record.value,
      origin: 'local',
      state: record.state,
      localState: record.state,
      serverValue: null,
      localValue: record.value,
      document: record.document ?? 'none',
    })
  }
  return {
    records,
    freshness: { serverAsOf: snapshot?.asOf ?? null, complete: snapshot?.complete ?? false },
  }
}

/** Faits en ajout seul : présents au serveur, ils sont consolidés (jamais comptés deux fois). */
export function mergeFacts<T extends Identified>(
  snapshot: ServerSnapshot<T> | null,
  local: readonly LocalRecord<T>[],
): MergedView<T> {
  return merge(snapshot, local, (server, mine) => ({
    id: server.id,
    value: server,
    origin: 'server',
    state: 'consolidated',
    localState: mine.state,
    serverValue: server,
    localValue: mine.value,
    document: mine.document ?? 'none',
  }))
}

/** Entités modifiables : une proposition du poste non finalisée n'est jamais masquée. */
export function mergeVersioned<T extends Versioned>(
  snapshot: ServerSnapshot<T> | null,
  local: readonly LocalRecord<T>[],
): MergedView<T> {
  return merge(snapshot, local, (server, mine) => {
    const document = mine.document ?? 'none'
    const acceptedAfterSnapshot =
      mine.state === 'acknowledged' && mine.value.version > server.version
    if (!isFinalState(mine.state) || acceptedAfterSnapshot) {
      return {
        id: server.id,
        value: mine.value,
        origin: 'local',
        state: mine.state,
        localState: mine.state,
        serverValue: server,
        localValue: mine.value,
        document,
      }
    }
    return {
      id: server.id,
      value: server,
      origin: 'server',
      state: 'consolidated',
      localState: mine.state,
      serverValue: server,
      localValue: mine.value,
      document,
    }
  })
}
